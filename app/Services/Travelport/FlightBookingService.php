<?php


namespace App\Services\Travelport;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;

class FlightBookingService
{
    protected TravelportService $travelportService;
    protected string $baseUrl;
    protected string $pcc;
    protected string $access_group;

    public function __construct(TravelportService $travelportService)
    {
        $this->travelportService = $travelportService;
        $this->baseUrl = config('services.travelport.base_url');
        $this->pcc = config('services.travelport.pcc', 'DU7_1G');
        $this->access_group = config('services.travelport.access_group', 'DU7_1G');
    }


    public function searchFlightOffers(array $criteria): array
    {
        return $this->travelportService->searchOffers($criteria);
    }

    public function createNewWorkbench(): string
    {
        $token = $this->travelportService->getAccessToken();
        $version = '11'; // Version utilisée dans vos endpoints précédents

        $url = rtrim($this->baseUrl, '/')
            . "/{$version}/air/book/session/reservationworkbench";

        // Body exact demandé par Travelport pour initialiser la session
        $payload = [
            "@type" => "ReservationID"
        ];

        Log::info('Travelport Create Workbench Session Request', [
            'url' => $url,
            'payload' => $payload
        ]);

        // Envoi de la requête HTTP
        $response = Http::timeout(60)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'Accept-Encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
                'TVP-PCC-Core' => $this->pcc ?? 'DU7_1G',
                'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group ?? '19Y88702-C27A-4E5D-829A-89D7016688B1',
                'TraceId' => 'TraceID_INIT_' . time(),
            ])
            ->post($url, $payload);

        // Logs de la réponse pour le débuggage
        Log::info('Travelport Create Workbench Session Response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        if (!$response->successful()) {
            $error = $response->json()['errors'][0]['message']
                ?? $response->json()['message']
                ?? $response->body();

            throw new \RuntimeException("Travelport Workbench Initialization Error [{$response->status()}]: {$error}");
        }

        $data = $response->json();

        // Extraction de la valeur de l'UUID de session selon votre structure de réponse
        $sessionUuid = $data['ReservationResponse']['Reservation']['Identifier']['value'] ?? null;

        if (!$sessionUuid) {
            throw new \RuntimeException("Travelport Error: Impossible de récupérer l'UUID de session (Reservation Identifier) dans la réponse.");
        }

        return $sessionUuid;
    }

    /**
     * Calcule et valide le tarif d'une offre de vol auprès de Travelport GDS v11.
     *
     * @param string $reservationResourceIdentifier Identifiant de session Travelport
     * @param array $selectedFlight Données complètes de l'itinéraire sélectionné
     * @param array $passengers Liste des passagers associés
     * @return array
     * @throws \Exception
     */
    public function priceFlightOffer(
        string $reservationResourceIdentifier,
        array $selectedFlight,
        array $passengers
    ): array {

        $token = $this->travelportService->getAccessToken();

        if (empty($token)) {
            throw new \Exception('Impossible d’obtenir le token Travelport.');
        }

        if (empty($reservationResourceIdentifier)) {
            throw new \Exception('Travelport Session Identifier manquant.');
        }

        /**
         * 1. EXTRACTION SÉCURISÉE DES IDENTIFIANTS GDS
         */
        $travelportData = $selectedFlight['travelport'] ?? [];

        // L'identifiant spécifique de l'offre (Ex: "o2")
        $offeringId =
            $travelportData['offering_id']
            ?? $selectedFlight['offering_id']
            ?? $selectedFlight['itinerary'][0]['offering_id']
            ?? null;

        // L'identifiant global du catalogue (Ex: "c32724e4-7fce-...")
        $catalogOfferingsIdentifierValue =
            $travelportData['catalog_offerings_identifier']
            ?? $travelportData['gds_authority_value']
            ?? $selectedFlight['id']
            ?? null;

        $transactionId = $travelportData['transaction_id'] ?? null;

        if (empty($offeringId)) {
            Log::error('Travelport AirPrice - Missing Offering ID', [
                'selected_flight' => $selectedFlight
            ]);
            throw new \Exception('Catalog Product Offering ID introuvable pour la sélection.');
        }

        /**
         * 2. CONSTRUCTION DU PASSENGERCRITERIA
         */
        $passengerCriteria = [];
        if (!empty($passengers)) {
            foreach ($passengers as $index => $passenger) {
                $passengerCriteria[] = [
                    '@type'             => 'PassengerCriteria',
                    'number'            => 1,
                    'passengerTypeCode' => $passenger['passenger_type'] ?? 'ADT',
                    'id'                => 'psgr_' . ($index + 1)
                ];
            }
        } else {
            $passengerCriteria[] = [
                '@type'             => 'PassengerCriteria',
                'number'            => 1,
                'passengerTypeCode' => 'ADT',
                'id'                => 'psgr_1'
            ];
        }

        /**
         * 3. MATCHING DYNAMIQUE DES MARQUES ET DES PRODUITS VALIDES (Anti-p0 Error)
         */
        $offeringSelection = [];
        $itinerary = $selectedFlight['itinerary'] ?? [];

        // Récupération de la table des correspondances (Brand -> Products) renvoyée par le GDS
        $productBrandOfferings = $travelportData['product_brand_offerings'] ?? [];

        foreach ($itinerary as $index => $journey) {
            $segmentOfferingId = $journey['offering_id'] ?? $offeringId;
            $brandRef          = $journey['brand_value'] ?? null; // Ex: "b3"

            // On cherche le vrai identifiant de produit (productRef) rattaché à la marque choisie
            $productRef = null;
            foreach ($productBrandOfferings as $offering) {
                if (($offering['brand_ref'] ?? '') === $brandRef) {
                    // On extrait le premier produit disponible pour cette marque (Ex: "p2" ou "p3")
                    $productRef = $offering['product_refs'][0] ?? null;
                    break;
                }
            }

            // Fallback de secours si aucune correspondance n'est trouvée dans le tableau
            if (empty($productRef)) {
                $productRef = $travelportData['products'][0] ?? 'p1';
            }

            $offeringSelection[] = [
                '@type' => 'CatalogProductOfferingSelection',
                'CatalogProductOfferingIdentifier' => [
                    'id' => 'cpo_' . ($index + 1),
                    'Identifier' => [
                        'value'     => $segmentOfferingId,
                        'authority' => 'TVPT'
                    ],
                    'CatalogProductOfferingRef' => 'cpo_' . ($index + 1)
                ],
                'ProductBrandOfferingIdentifier' => [
                    'value'     => $catalogOfferingsIdentifierValue,
                    'authority' => 'TVPT'
                ],
                'ProductIdentifier' => [
                    [
                        // Utilisation du jeton dynamique pour correspondre exactement à l'offre GDS
                        'id'         => 'product_' . $productRef,
                        'productRef' => 'product_' . $productRef,
                        'Identifier' => [
                            'value'     => $productRef, // Injecte "p2", "p3", "p4" ou "p5" selon le segment
                            'authority' => 'TVPT'
                        ]
                    ]
                ],
                'SegmentSequence' => [
                    $index + 1
                ]
            ];
        }

        // Sécurité ultime si l'itinéraire est structurellement vide
        if (empty($offeringSelection)) {
            $fallbackProduct = $travelportData['products'][0] ?? 'p1';
            $offeringSelection[] = [
                '@type' => 'CatalogProductOfferingSelection',
                'CatalogProductOfferingIdentifier' => [
                    'id' => 'cpo_1',
                    'Identifier' => [
                        'value'     => $offeringId,
                        'authority' => 'TVPT'
                    ],
                    'CatalogProductOfferingRef' => 'cpo_1'
                ],
                'ProductBrandOfferingIdentifier' => [
                    'value'     => $catalogOfferingsIdentifierValue,
                    'authority' => 'TVPT'
                ],
                'ProductIdentifier' => [
                    [
                        'id'         => 'product_' . $fallbackProduct,
                        'productRef' => 'product_' . $fallbackProduct,
                        'Identifier' => [
                            'value'     => $fallbackProduct,
                            'authority' => 'TVPT'
                        ]
                    ]
                ]
            ];
        }

        /**
         * 4. ASSEMBLAGE DU PAYLOAD CONFORME À LA DOCUMENTATION V11
         */
        $payload = [
            '@type' => 'OfferQueryBuildFromCatalogProductOfferings',

            'BuildFromCatalogProductOfferingsRequest' => [
                '@type' => 'BuildFromCatalogProductOfferingsRequestAir',

                'CatalogProductOfferingsIdentifier' => [
                    'id' => 'cpo_1',
                    'Identifier' => [
                        'value'     => $catalogOfferingsIdentifierValue,
                        'authority' => 'TVPT'
                    ]
                ],

                'CatalogProductOfferingSelection' => $offeringSelection,
                'PassengerCriteria'               => $passengerCriteria,
                'FareRuleType'                    => 'Structured'
            ],

            'PaymentCriteria' => [
                '@type'                      => 'PaymentCriteria',
                'IssuerIdentificationNumber' => '123456',
                'PaymentCardCode'            => 'VI',
                'agencyAccountInd'           => true,
                'bspInd'                     => true,
                'cashInd'                    => true,
                'invoiceInd'                 => true
            ],

            'MaxNumberOfUpsellsToReturn' => 4
        ];

        Log::info('Travelport AirPrice Request - Production Specs Matched', [
            'session_id' => $reservationResourceIdentifier,
            'payload'    => $payload
        ]);

        /**
         * 5. ENVOI DE LA REQUÊTE HTTP
         */
        $response = Http::withToken($token)
            ->withOptions([
                'connect_timeout' => 15,
                'timeout'         => 30,
            ])
            ->withHeaders([
                'Accept-Encoding'                 => 'gzip, deflate',
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'AirPrice_' . uniqid(),
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier,
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => config('services.travelport.access_group'),
                'TransactionId'                   => $transactionId
            ])
            ->post(
                rtrim($this->baseUrl, '/') . '/11/air/price/offers/buildfromcatalogproductofferings',
                $payload
            );

        $responseData = $response->json();

        Log::info('Travelport AirPrice Response', [
            'http_status' => $response->status(),
            'response'    => $responseData
        ]);

        if ($response->failed()) {
            Log::error('Travelport AirPrice HTTP Error', [
                'status'   => $response->status(),
                'response' => $responseData
            ]);
            throw new \Exception('Erreur de communication avec l\'API de tarification (' . $response->status() . ').');
        }

        /**
         * 6. INTERCEPTION DES ERREURS MÉTIER DU GDS
         */
        $offerListResponse = $responseData['OfferListResponse'] ?? [];
        $errors            = $offerListResponse['Result']['Error'] ?? [];

        if (!empty($errors)) {
            $message = $errors[0]['Message'] ?? 'Le tarif de l\'offre sélectionnée n\'est plus disponible.';
            Log::error('Travelport AirPrice Business Error', [
                'message'  => $message,
                'response' => $responseData
            ]);
            throw new \Exception($message);
        }

        return [
            'success'        => true,
            'offering_id'    => $offeringId,
            'transaction_id' => $transactionId,
            'response'       => $responseData
        ];
    }


    /**
     * ÉTAPES F & J : Valide et scelle le Workbench (Commit).
     *
     * Selon la documentation :
     * - Si AUCUN paiement n'a été injecté (Étape F) : Génère un PNR de hold.
     * - Si UN paiement a été injecté via addPayment (Étape J) : Émet les billets et génère les numéros de tickets.
     *
     * @param string $sessionIdentifier L'identifiant de session du Workbench (Pre ou Post-Commit)
     * @param string $bookingType Type d'action : 'hold' (Étape F) ou 'now' (Étape J)
     * @return array Contient le PNR ('locatorCode') et la réponse brute
     */
    public function commitReservation(string $sessionIdentifier, string $bookingType = 'hold'): array
    {
        $token = $this->travelportService->getAccessToken();
        $versionPath = "11"; // Utilisation du endpoint de base de la passerelle

        // Endpoint de l'identifiant du workbench à commit
        $url = rtrim($this->baseUrl, '/') . "/{$versionPath}/air/book/reservation/reservations/{$sessionIdentifier}";

        // Calcul de la date de rétention de sécurité (J+3) requise par l'autoDeleteDate
        $autoDeleteDate = now()->addDays(3)->format('Y-m-d');

        // MAPPING STRUCTUREL DES QUERY PARAMETERS BASÉ SUR LA DOCUMENTATION
        if ($bookingType === 'hold') {
            // Étape F : Réservation brute (PNR sans paiement immédiat)
            $queryParams = [
                'autoDeleteDate' => $autoDeleteDate,
                'DocumentValue'  => 'Retain',
                'payLaterInd'    => 'true' // Renseigné à true car le paiement est délégué à plus tard
            ];
            // Note : On omet 'Issuance' ici car l'absence de paiement provoquera la création du PNR automatique.
        } else {
            // Étape J : Émission finale (Ticketing après addPayment)
            $queryParams = [
                'autoDeleteDate' => $autoDeleteDate,
                'Issuance'       => 'Ticket', // L'Enum requis pour forcer le ticketing immédiat
                'DocumentValue'  => 'Retain',
                'payLaterInd'    => 'false'   // Le paiement vient d'être injecté à l'étape H/I
            ];
        }

        $fullUrl = $url . '?' . http_build_query($queryParams);

        // Payload par défaut exigé dans le corps (Body) de la requête POST pour signer l'acte
        $payload = [
            "scheduleChangeAcceptedInd"          => true,
            "errorWhenOfferPriceCancelledInd"    => true,
            "inhibitResidualDocumentIssuanceInd" => true,
            "enableTwoStepCommitInd"             => false, // Traitement direct en une seule étape pour obtenir le ticket/PNR immédiatement
            "overrideMCTInd"                     => true,
            "errorWhenScheduleChangesInd"        => true,
            "scheduleChangeReprice"              => "AcceptOfferPriceDifference",
            "ReceivedFrom"                       => "GUENS TRAVEL", // Signature de l'agence (Max 11 caractères)
            "errorWhenOfferPriceChangesInd"      => true
        ];

        Log::info("[Travelport] commitReservation [Mode: {$bookingType}] → Traitement du Commit", [
            'url' => $fullUrl
        ]);

        // Exécution de la requête POST réglementaire
        $response = Http::timeout(120)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'Accept'                          => 'application/json;version=11.33', // Forçage de la version de mapping 11.33
                'Accept-Encoding'                 => 'gzip, deflate',
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => !empty($this->pcc) ? trim($this->pcc) : 'DU7_1G',
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => trim($this->access_group),
                'travelportPlusSessionIdentifier' => $sessionIdentifier,
                'TraceId'                         => 'Commit_Req_' . $sessionIdentifier . '_' . time(),
            ])
            ->post($fullUrl, $payload);

        if (!$response->successful()) {
            Log::error("[Travelport] commitReservation [Mode: {$bookingType}] → Échec critique", [
                'status' => $response->status(),
                'body'   => $response->body()
            ]);

            $json = $response->json() ?? [];
            $error = $json['errors'][0]['message'] ?? $json['message'] ?? "Erreur GDS Gateway {$response->status()}";
            throw new \RuntimeException("Le Commit du Workbench a échoué ({$bookingType}) : {$error}");
        }

        $data = $response->json() ?? [];

        // Extraction robuste du Code PNR (locatorCode) selon les variantes de structures de l'API v11
        $pnr = $data['Reservation']['locatorCode']
            ?? $data['ReservationResponse']['Reservation']['locatorCode']
            ?? $data['ReservationDisplayResponse']['ReservationShort']['Identifier']['value']
            ?? null;

        Log::info("[Travelport] commitReservation [Mode: {$bookingType}] → Succès !", [
            'pnr' => $pnr
        ]);

        return [
            'pnr' => $pnr,
            'raw' => $data
        ];
    }

    public function createPostCommitWorkbench(string $pnr): array
    {
        $token = $this->travelportService->getAccessToken();

        $url = rtrim($this->baseUrl, '/') . "/11/air/ticket/reservation/{$pnr}/products/ticket";

        $payload = [
            'TicketingModifiers' => [
                '@type'         => 'TicketingModifiersAir',
                'PseudoCityCode' => $this->pcc,
            ]
        ];

        Log::info('[Travelport] issueTickets → Request', [
            'url' => $url,
            'pnr' => $pnr,
        ]);

        $response = Http::timeout(120)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'Content-Type'                 => 'application/json',
                'TVP-PCC-Core'                 => $this->pcc,
                'TraceId'                      => 'Ticket_' . $pnr . '_' . time(),
                'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group,
            ])
            ->post($url, $payload);

        Log::info('[Travelport] issueTickets → Response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if (!$response->successful()) {
            $json  = $response->json() ?? [];
            $error = $json['errors'][0]['message'] ?? $json['message'] ?? "HTTP {$response->status()}";

            Log::critical('[Travelport] Ticketing échoué après PNR créé', [
                'pnr'   => $pnr,
                'error' => $error,
            ]);

            throw new \RuntimeException("issueTickets failed pour PNR {$pnr} : {$error}");
        }

        return $response->json() ?? [];
    }
    /**
     * ÉTAPE G : Initialise un Post-Commit Workbench pour créer une session d'émission (Ticketing).
     * Préréquis absolu avant toute transaction modifiant ou émettant un PNR existant.
     *
     * @param string $pnr Le code de réservation GDS (ex: ABC123)
     * @param string $source Le système source qui a généré l'identifiant (Défaut: 'GDS')
     * @return string Le nouveau jeton travelportPlusSessionIdentifier à utiliser pour injecter le paiement (Étape H/I)
     * @throws \RuntimeException
     */
    public function createPostCommitWorkbench2(string $pnr, string $source = 'GDS'): string
    {
        $token = $this->travelportService->getAccessToken();
        $versionPath = "11"; // Utilisation du endpoint de passerelle principale

        // Nettoyage et validation stricte du format du Locator (Max 16 caractères, Alphanumérique Majuscule)
        $cleanPnr = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', trim($pnr)));
        if (strlen($cleanPnr) > 16) {
            throw new \InvalidArgumentException("Le code Locator (PNR) fourni dépasse la limite maximale de 16 caractères.");
        }

        // Endpoint officiel de construction de session à partir du localisateur de réservation
        $url = rtrim($this->baseUrl, '/') . "/{$versionPath}/air/book/session/reservationworkbench/buildfromlocator";

        // Application stricte des Query Parameters issus de la spécification
        $queryParams = [
            'Locator'                  => $cleanPnr,
            'source'                   => substr($source, 0, 128), // Sécurité de troncature à 128 caractères
            'detailViewInd'            => 'true', // Demande le retour de l'objet complet ReservationDetail
            'viewBrandCompleteInfoInd' => 'true', // Inclus les métadonnées de la marque/classe tarifaire
            'viewBaggageDetailInd'     => 'true'  // Inclus le détail complet des franchises bagages
        ];

        $fullUrl = $url . '?' . http_build_query($queryParams);

        Log::info("[Travelport] createPostCommitWorkbench [Étape G] → Envoi", [
            'pnr' => $cleanPnr,
            'url' => $fullUrl
        ]);

        // Exécution de la requête POST (sans corps JSON, l'état initial étant forgé par les paramètres d'URL)
        $response = Http::timeout(90)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'Accept'                       => 'application/json;version=11.33', // Exige le schéma v11.33 pour le mapping
                'Accept-Encoding'              => 'gzip, deflate',
                'TVP-PCC-Core'                 => !empty($this->pcc) ? trim($this->pcc) : 'DU7_1G',
                'XAUTH_TRAVELPORT_ACCESSGROUP' => trim($this->access_group),
                'TraceId'                      => 'PostCommit_Build_' . $cleanPnr . '_' . time(),
            ])
            ->post($fullUrl);

        // Capture des échecs de routage ou de validation métier
        if (!$response->successful()) {
            Log::error("[Travelport] createPostCommitWorkbench [Étape G] → Échec de l'initialisation", [
                'pnr'    => $cleanPnr,
                'status' => $response->status(),
                'body'   => $response->body()
            ]);

            $json = $response->json() ?? [];
            $error = $json['errors'][0]['message'] ?? $json['message'] ?? "HTTP Status {$response->status()} - Bad Request";
            throw new \RuntimeException("Impossible d'initier le Post-Commit Workbench pour le PNR {$cleanPnr}: {$error}");
        }

        // Extraction du jeton de session dans les en-têtes retournés (Spécificité de l'API Gateway de Travelport)
        $headers = $response->headers();
        $postCommitSessionToken = $headers['travelportPlusSessionIdentifier'][0]
            ?? $headers['travelportplussessionidentifier'][0]
            ?? null;

        // Alternative de repli : Recherche de l'identifiant directement dans le corps de réponse JSON
        if (!$postCommitSessionToken) {
            $data = $response->json() ?? [];
            $postCommitSessionToken = $data['ReservationWorkbench']['sessionIdentifier']
                ?? $data['sessionIdentifier']
                ?? null;
        }

        // Si aucune session n'est explicitement renvoyée, on lève une exception pour empêcher la rupture silencieuse du flux
        if (!$postCommitSessionToken) {
            throw new \RuntimeException("Le GDS a validé la réouverture mais n'a retourné aucun jeton de session 'travelportPlusSessionIdentifier' valide.");
        }

        Log::info("[Travelport] createPostCommitWorkbench [Étape G] → Session Initiée avec Succès", [
            'pnr'               => $cleanPnr,
            'allocated_session' => $postCommitSessionToken
        ]);

        return $postCommitSessionToken;
    }

    public function addOfferToWorkbench(string $reservationResourceIdentifier, array $selectedFlight): array
    {
        $token = $this->travelportService->getAccessToken();

        // Récupération des valeurs d'identifiants renvoyées par l'API de recherche d'origine
        $cpoValue = $selectedFlight['gds_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E';
        $offeringId = $selectedFlight['offering_id'] ?? 'cpo_1';
        $brandValue = $selectedFlight['brand_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E';

        // Nettoyage et structure du Payload conforme à l'API Travelport+
        $payload = [
            "OfferQueryBuildFromCatalogProductOfferings" => [
                "@type" => "OfferQueryBuildFromCatalogProductOfferings",
                "PaymentCriteria" => [
                    "@type" => "PaymentCriteria",
                    // Mode Cash/Agency Account privilégié pour l'encaissement via Mobile Money local
                    "agencyAccountInd" => true,
                    "bspInd" => true,
                    "cashInd" => true,
                    "invoiceInd" => true
                ],
                "BuildFromCatalogProductOfferingsRequest" => [
                    "@type" => "BuildFromCatalogProductOfferingsRequestAir",
                    "CatalogProductOfferingsIdentifier" => [
                        "id" => "cpo_1",
                        "Identifier" => [
                            "value" => $cpoValue,
                            "authority" => "TVPT"
                        ]
                    ],
                    "CatalogProductOfferingSelection" => [
                        [
                            "@type" => "CatalogProductOfferingSelection",
                            "CatalogProductOfferingIdentifier" => [
                                "id" => $offeringId,
                                "Identifier" => [
                                    "value" => $cpoValue,
                                    "authority" => "TVPT"
                                ],
                                "CatalogProductOfferingRef" => $offeringId
                            ],
                            "ProductBrandOfferingIdentifier" => [
                                "value" => $brandValue,
                                "authority" => "TVPT"
                            ],
                            "ProductIdentifier" => [
                                [
                                    "id" => "product_1",
                                    "productRef" => "product_1",
                                    "Identifier" => [
                                        "value" => $cpoValue,
                                        "authority" => "TVPT"
                                    ]
                                ]
                            ],
                            "SegmentSequence" => [1, 2] // Séquences d'itinéraires sélectionnées (Aller-Retour)
                        ]
                    ]
                ],
                "MaxNumberOfUpsellsToReturn" => 4
            ]
        ];

        // Appel de l'API avec Laravel HTTP
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
                'TVP-PCC-Core' => $this->pcc,
                'TraceId' => 'BuildOffer_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP' => config('services.travelport.access_group', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($this->baseUrl . "11/air/book/airoffer/reservationworkbench/{$reservationResourceIdentifier}/offers/buildfromcatalogproductofferings", $payload);

        if ($response->failed()) {
            Log::error('Travelport Offer Build From Catalog Failed', [
                'resource_id' => $reservationResourceIdentifier,
                'body' => $response->body()
            ]);
            throw new \Exception('Erreur critique lors de la tarification finale du vol auprès de la compagnie.');
        }

        return $response->json();
    }


    public function addTravelersToWorkbench(
        string $sessionIdentifier,
        array $passengers,
        array $selectedFlight
    ): array {
        $token = $this->travelportService->getAccessToken();
        $version = '11';

        $url = rtrim($this->baseUrl, '/')
            . "/{$version}/air/book/traveler/reservationworkbench/{$sessionIdentifier}/travelers";

        $responses = [];

        foreach ($passengers as $index => $passenger) {
            $travelerNumber = $index + 1;

            // 1. FORMATAGE STRICT DES DATES (Format attendu par Travelport : YYYY-MM-DD)
            // Convertit les formats avec timestamp/ISO (ex: 2000-11-12T00:00:00.000000Z) en chaînes propres
            $formattedBirthDate = date('Y-m-d', strtotime($passenger['birth_date'] ?? '1990-01-01'));
            $formattedExpiryDate = date('Y-m-d', strtotime($passenger['passport_expiry'] ?? now()->addYears(3)->format('Y-m-d')));

            // Détermination du genre selon les civilités standard (M. / Mme) reçues du front
            $gender = (isset($passenger['title']) && $passenger['title'] === 'Mme') ? 'Female' : 'Male';

            // Nettoyage et formatage du numéro de téléphone
            $rawPhone = $passenger['phone'] ?? '670000000';
            $cleanPhone = preg_replace('/[^0-9]/', '', $rawPhone);

            // 2. PAYLOAD NETTOYÉ ET STRIPPE DES REDONDANCES CONFLITIELLES
            $singleTravelerPayload = [
                '@type'             => 'Traveler',
                'gender'            => $gender,
                'birthDate'         => $formattedBirthDate, // 🟢 Corrigé : Format YYYY-MM-DD strict
                'id'                => "trav_{$travelerNumber}",
                'passengerTypeCode' => $passenger['passenger_type'] ?? 'ADT', // ADT, CHD, INF

                'PersonName' => [
                    '@type'   => 'PersonNameDetail',
                    'Given'   => trim($passenger['first_name'] ?? 'Inconnu'),
                    'Surname' => trim($passenger['last_name'] ?? 'Client')
                ],

                'Telephone' => [
                    [
                        '@type'             => 'Telephone',
                        'countryAccessCode' => '237', // Code pays par défaut (Cameroun)
                        'phoneNumber'       => $cleanPhone,
                        'id'                => "tel_{$travelerNumber}",
                        'cityCode'          => 'DLA',
                        'role'              => 'Mobile'
                    ]
                ],

                'Email' => [
                    [
                        'value' => trim($passenger['email'] ?? 'traveler' . $travelerNumber . '@guens.org')
                    ]
                ],

                'TravelDocument' => [
                    [
                        '@type'        => 'TravelDocumentDetail',
                        'docNumber'    => strtoupper(trim($passenger['passport_number'] ?? 'N0000000')),
                        'docType'      => 'Passport',
                        'expireDate'   => $formattedExpiryDate, // 🟢 Corrigé : Format YYYY-MM-DD strict
                        'issueCountry' => strtoupper($passenger['passport_issue_country'] ?? 'CM'),
                        'birthDate'    => $formattedBirthDate,  // 🟢 Corrigé : Format YYYY-MM-DD strict
                        'Gender'       => $gender
                        // 🟢 Supprimé : Le sous-bloc PersonName d'ici qui causait la 500 sur api.pp.travelport.net
                    ]
                ]
            ];

            Log::info("[Travelport] Add Traveler #{$travelerNumber} → Request", [
                'url'     => $url,
                'payload' => $singleTravelerPayload
            ]);

            $response = Http::timeout(60)
                ->withToken($token)
                ->acceptJson()
                ->withHeaders([
                    'Accept-Encoding'                 => 'gzip, deflate',
                    'Content-Type'                    => 'application/json',
                    'TVP-PCC-Core'                    => !empty($this->pcc) ? $this->pcc : 'DU7_1G',
                    'XAUTH_TRAVELPORT_ACCESSGROUP'    => $this->access_group,
                    'travelportPlusSessionIdentifier' => $sessionIdentifier,
                    'TraceId'                         => 'AddTrav_' . $sessionIdentifier . '_' . $travelerNumber,
                ])
                ->post($url, $singleTravelerPayload);

            if (!$response->successful()) {
                $json  = $response->json() ?? [];
                $error = $json['errors'][0]['message'] ?? $json['message'] ?? $response->body();

                Log::error("[Travelport] Erreur Add Traveler #{$travelerNumber}", [
                    'status' => $response->status(),
                    'body'   => $json
                ]);

                throw new \RuntimeException("Travelport Error on Passenger #{$travelerNumber} : {$error}");
            }

            $responses[] = $response->json();
        }

        return $responses;
    }

    public function addFormOfPayment(string $sessionIdentifier, array $paymentData,array $selectedFlight): array
    {
        $token = $this->travelportService->getAccessToken();
        $version = '11'; // Modifiez selon votre version d'API
        $travelportData = $selectedFlight['travelport'] ?? [];
        $url = rtrim($this->baseUrl, '/')
            . "/{$version}/air/payment/reservationworkbench/{$sessionIdentifier}/formofpayment";

        // Formatage du payload selon votre modèle de carte
        $payload = [
            "@type" => "FormOfPaymentPaymentCard",
            "id" => "formOfPayment_1",
            "FormOfPaymentRef" => "formOfPayment_1",
            "Identifier" => [
                "authority" => "Travelport",
                "value" => $travelportData['gds_authority_value']
            ],
            "PaymentCard" => [
                "@type" => "PaymentCardDetail",
                "id" => "paymentCard_4",
                "expireDate" => $paymentData['card_expiry'], // Format: YYYY-MM
                "CardType" => "Credit",
                "CardCode" => $paymentData['card_code'] ?? 'VI', // VI = Visa, MC = Mastercard...
                "CardHolderName" => strtoupper($paymentData['card_holder_name']),
                "CardNumber" => [
                    "@type" => "CardNumber",
                    "PlainText" => preg_replace('/[^0-9]/', '', $paymentData['card_number'])
                ],
                "SeriesCode" => [
                    "PlainText" => $paymentData['card_cvv']
                ]
            ]
        ];

        $response = Http::timeout(60)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'TVP-PCC-Core' => $this->pcc ?? 'DU7_1G',
                'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group ?? '19Y88702-C27A-4E5D-829A-89D7016688B1',
                'travelportPlusSessionIdentifier' => $sessionIdentifier,
                'TraceId' => 'TraceID_FOP_' . time(),
            ])
            ->post($url, $payload);

        if (!$response->successful()) {
            $error = $response->json()['errors'][0]['message'] ?? $response->body();
            throw new \RuntimeException("Travelport FOP Error [{$response->status()}]: {$error}");
        }

        return $response->json();
    }

    public function addPayment(
        string $sessionIdentifier,
        float $totalPrice,
        string $currencyCode,
        array $selectedFlight
    ): array {
        $token = $this->travelportService->getAccessToken();

        if (empty($token)) {
            throw new \RuntimeException('Impossible d’obtenir le token d’accès Travelport.');
        }

        $travelportData = $selectedFlight['travelport'] ?? [];

        /**
         * 1. EXTRACTION DYNAMIQUE DES IDENTIFIANTS DU PAIEMENT (Alignée sur ton JSON réel)
         */
        // Récupère "o2" depuis ton payload réel
        $offeringId = $travelportData['offering_id'] ?? $selectedFlight['offering_id'] ?? 'o1';

        // Autorité GDS (ex: "c32724e4-7fce-4133-9c53-ec2a8341f42e")
        $authority = $travelportData['gds_authority_value'] ?? 'TVPT';

        // Hash du catalogue parent (ex: "c32724e4-7fce-4133-9c53-ec2a8341f42e")
        $catalogIdentifier = $travelportData['catalog_offerings_identifier'] ?? $authority;

        /**
         * 2. RECONSTRUCTION DU PAYLOAD CONFORME TRAVELPORT RESERVATION WORKBENCH
         */
        $payload = [
            "@type" => "Payment",
            "id"    => "payment_1",
            "Identifier" => [
                "authority" => "Travelport",
                "value"     => $catalogIdentifier
            ],
            "Amount" => [
                "code"           => strtoupper($currencyCode), // Assure le format ISO majuscule
                "minorUnit"      => 2,
                "currencySource" => "Charged",
                "value"          => (float) $totalPrice
            ],
            "FormOfPaymentIdentifier" => [
                "id"                => "formOfPayment_1",
                "FormOfPaymentRef"  => "formOfPayment_1",
                "Identifier" => [
                    "authority" => "Travelport",
                    "value"     => "A0656EFF-FAF4-456F-B061-0161008D6FOP" // Identifiant FOP sandbox par défaut
                ]
            ],
            "OfferIdentifier" => [
                [
                    "id"       => "offer_" . $offeringId,
                    "offerRef" => "offer_" . $offeringId,
                    "Identifier" => [
                        "authority" => "Travelport",
                        "value"     => $offeringId // Cible "o2"
                    ]
                ]
            ]
        ];

        Log::info('Travelport AddPayment Request', [
            'session_id' => $sessionIdentifier,
            'url_params' => ['offering_id' => $offeringId, 'catalog_id' => $catalogIdentifier],
            'payload'    => $payload
        ]);

        /**
         * 3. ENVOI DE LA REQUÊTE AU WORKBENCH
         */
        $url = rtrim($this->baseUrl, '/') . "/11/air/paymentoffer/reservationworkbench/{$sessionIdentifier}/payments";

        logger('pccc'.$this->pcc);
        $response = Http::timeout(45)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core' =>'DU7_1G',
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => config('services.travelport.access_group'),
                'travelportPlusSessionIdentifier' => $sessionIdentifier,
                'TraceId'                         => 'TraceID_PAY_' . uniqid(),
                //'TransactionId'                   => $travelportData['transaction_id'] ?? null
            ])
            ->post($url, $payload);

        $responseData = $response->json();

        Log::info('Travelport AddPayment Response', [
            'status'   => $response->status(),
            'response' => $responseData
        ]);

        if (!$response->successful()) {
            // Extraction de l'erreur imbriquée propre au schéma GDS
            $error = $responseData['errors'][0]['message']
                ?? $responseData['ReservationDisplayResponse']['Result']['Error'][0]['Message']
                ?? $response->body();

            throw new \RuntimeException("Erreur lors de l'application du paiement Travelport : {$error}");
        }

        return $responseData;
    }
}

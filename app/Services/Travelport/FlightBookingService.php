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
        $this->baseUrl = config('services.travelport.base_url'); // https://api.pp.travelport.net/
        $this->pcc = config('services.travelport.pcc', 'DU7_1G');
        $this->access_group = config('services.travelport.access_group', 'DU7_1G');
    }

    /**
     * ÉTAPE 1 : Rechercher les offres de vols tarifées (Low Fare Search)
     * Endpoint: /air/catalog/search/catalogproductofferings
     * @param array $criteria
     * @return array
     * @throws \Exception
     */
    public function searchFlightOffers(array $criteria): array
    {
        return $this->travelportService->searchOffers($criteria);
    }

    /**
     * ÉTAPE 2 : Vérifier la disponibilité réelle des sièges (Pre-Checkout Guard)
     * Endpoint: /air/search/airAvailability
     * @param array $selectedFlightData
     * @return bool
     */
    public function verifySeatAvailability(array $selectedFlightData): bool
    {
        $token = $this->travelportService->getAccessToken();
        $reservationResourceIdentifier = $selectedFlightData['id'];

        // 1. Extraction et formatage des segments
        $segments = [];
        foreach ($selectedFlightData['itinerary'] as $journey) {
            foreach ($journey['segments'] as $seg) {
                $departureDate = explode('T', $seg['departure']['time'])[0];
                $departureTime = isset(explode('T', $seg['departure']['time'])[1])
                    ? substr(explode('T', $seg['departure']['time'])[1], 0, 8)
                    : '00:00:00';

                $segments[] = [
                    'departureDate' => $departureDate,
                    'departureTime' => $departureTime,
                    'From' => ['value' => $seg['departure']['airport']],
                    'To' => ['value' => $seg['arrival']['airport']],
                    'CarrierPreference' => [
                        [
                            '@type' => 'CarrierPreference',
                            'preferenceType' => 'Preferred',
                            'carriers' => [$seg['airline_code']]
                        ]
                    ]
                ];
            }
        }

        // 2. Construction du Payload
        $payload = [
            'CatalogProductOfferingsQueryRequest' => [
                'CatalogProductOfferingsRequest' => [
                    '@type' => 'CatalogProductOfferingsRequestAir',
                    'offersPerPage' => 10,
                    'contentSourceList' => ['GDS'],
                    'PassengerCriteria' => [
                        [
                            '@type' => 'PassengerCriteria',
                            'number' => 1,
                            'passengerTypeCode' => 'ADT'
                        ]
                    ],
                    'SearchCriteriaFlight' => $segments,
                    'SearchType' => 'MetaSearch'
                ]
            ]
        ];

        // 3. Appel à l'API Travelport
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
                'TVP-PCC-Core' => $this->pcc,
                'TraceId' => 'Verify_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group,
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($this->baseUrl . '11/air/search/airAvailability', $payload);

        if ($response->failed()) {
            Log::warning('Travelport AirAvailability Check Failed', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return false;
        }

        $responseData = $response->json();

        // 4. CORRECTION ALIGNÉE SUR TON LOG :
        // On extrait le tableau des offres de vols retourné par le catalogue
        $offerings = $responseData['CatalogProductOfferingsResponse']['CatalogProductOfferings']['CatalogProductOffering'] ?? [];

        // Si le GDS renvoie au moins une offre (comme le tableau indexé [0, 1, 2...] de ton log), le siège est dispo.
        if (!empty($offerings) && count($offerings) > 0) {
            return true;
        }

        // Analyse de secours si un bloc d'erreur alternatif ou de rejet est présent à la racine
        if (isset($responseData['Result']['Error']) || isset($responseData['Error'])) {
            Log::info("Travelport a renvoyé un bloc d'erreur explicite d'indisponibilité.");
            return false;
        }

        return false;
    }

    /**
     * Injecte le profil client dans le Reservation Workbench Travelport.
     *
     * PUT /air/book/profile/reservationworkbench/{identifier}/clientprofile
     */
    public function applyClientProfile(
        string $sessionIdentifier,
        array $passengersData
    ): array
    {
        if (empty($sessionIdentifier)) {
            throw new \InvalidArgumentException(
                'Le sessionIdentifier Travelport est obligatoire.'
            );
        }
        $sessionIdentifier = '49f58f5f-c443-43b4-9f5d-be405fd00a01';
        $token = $this->travelportService->getAccessToken();

        // Construction du PersonalTitle depuis le passager principal
        $leadPassenger = $passengersData[0] ?? null;
        $personalTitle = 'DOE/J';

        if ($leadPassenger) {
            $lastName = strtoupper(preg_replace('/[^A-Za-z]/', '', $leadPassenger['last_name'] ?? ''));
            $firstName = strtoupper(preg_replace('/[^A-Za-z]/', '', $leadPassenger['first_name'] ?? ''));

            if ($lastName && $firstName) {
                $personalTitle = $lastName . '/' . $firstName;
            }
        }

        $payload = [
            'ClientProfileMoveHeaderModifiers' => [
                'BusinessTitle' => 'CREATIV_TRIPS',
                'PersonalTitle' => $personalTitle,
                'MultipleIndicator' => true,
                'SelectIndicator' => true,
                'MergeIndicator' => true,
                'RelatedMoveIndicator' => 'Y',
            ],
            'ClientProfileMoveTravelerFlightModifiers' => array_map(
                fn($i, $p) => [
                    'TravelerRef' => 't' . ($i + 1),
                    'FlightRef' => 's1',
                ],
                array_keys($passengersData),
                $passengersData
            ),
        ];

        // ✅ URL corrigée — sans préfixe /11/ ni /res-profile-do/
        $url = rtrim($this->baseUrl, '/')
            . "/11/air/book/profile/reservationworkbench/{$sessionIdentifier}/clientprofile";

        Log::info('[Travelport] applyClientProfile → Request', [
            'url' => $url,
            'session_identifier' => $sessionIdentifier,
            'payload' => $payload,
        ]);

        try {
            $response = Http::timeout(60)
                ->withToken($token)
                ->acceptJson()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'TVP-PCC-Core' => $this->pcc,
                    'TraceId' => 'Profile_' . $sessionIdentifier . '_' . time(),
                    'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group,
                    'travelportPlusSessionIdentifier' => $sessionIdentifier,
                ])
                ->put($url, $payload);

            Log::info('[Travelport] applyClientProfile → Response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {
                $json = $response->json() ?? [];
                $errorMessage = $json['errors'][0]['message']
                    ?? $json['message']
                    ?? "HTTP {$response->status()} — {$response->body()}";

                throw new \RuntimeException(
                    "applyClientProfile failed [{$response->status()}] : {$errorMessage}"
                );
            }

            return $response->json() ?? [];

        } catch (\RuntimeException $e) {
            throw $e;

        } catch (\Throwable $e) {
            Log::error('[Travelport] applyClientProfile → Exception', [
                'session_identifier' => $sessionIdentifier,
                'message' => $e->getMessage(),
                'url' => $url,
                'payload' => $payload,
            ]);

            throw new \RuntimeException(
                'Échec applyClientProfile Travelport : ' . $e->getMessage()
            );
        }
    }
    /**
     * Crée une session de réservation (Reservation Workbench) auprès de Travelport.
     *
     * Endpoint: POST https://{{baseURL}}/{{version}}/air/book/session/reservationworkbench
     *
     * @return string L'UUID de la session générée (ex: "07c5c53c-7272-46c9-ad13-226e8ef0aa96")
     * @throws \RuntimeException En cas d'échec de la requête API
     */
    public function createInitReservationWorkbench(): string
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
     * ÉTAPE 3 : Créer le Reservation Workbench (Session GDS)
     * POST /11/air/book/reservationworkbench
     * @return string — sessionIdentifier UUID
     */
    /**
     * ÉTAPE 4 : Créer le Reservation Workbench (Session GDS)
     * POST /11/air/book/session/reservationworkbench
     * @param array $selectedFlight
     * @param array $passengers
     * @param array $contactInfo
     * @return string — sessionIdentifier UUID Travelport
     */
    public function createReservationWorkbench(array $selectedFlight, array $passengers, array $contactInfo): string
    {
        $token             = $this->travelportService->getAccessToken();
        $gdsAuthorityValue = $selectedFlight['gds_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E';

        // Construction des Travelers
        $travelerList = [];
        foreach ($passengers as $index => $passenger) {
            $prefix     = ($passenger['civility'] === 'Mme') ? 'Mrs' : 'Mr';
            $travelerId = 'traveler_' . ($index + 1);
            $travelerRef = 't' . ($index + 1);

            $travelerList[] = [
                '@type'       => 'Traveler',
                'id'          => $travelerId,
                'TravelerRef' => $travelerRef,
                'Identifier'  => [
                    'value'     => $gdsAuthorityValue,
                    'authority' => 'TVPT',
                ],
                'birthDate' => $passenger['birth_date'],
                'gender'    => ($prefix === 'Mr') ? 'Male' : 'Female',
                'PersonName' => [
                    '@type'   => 'PersonNameDetail',
                    'Prefix'  => $prefix,
                    'Given'   => strtoupper($passenger['first_name']),
                    'Surname' => strtoupper($passenger['last_name']),
                ],
                'Telephone' => [
                    [
                        '@type'             => 'Telephone',
                        'countryAccessCode' => '237',
                        'phoneNumber'       => str_replace([' ', '+237'], '', $contactInfo['phone'] ?? ''),
                        'role'              => 'Mobile',
                    ]
                ],
                'Email' => [
                    [
                        'value'     => $contactInfo['email'] ?? 'agence@creativtrips.com',
                        'id'        => 'email_' . ($index + 1),
                        'emailType' => 'FROM',
                        'validInd'  => true,
                    ]
                ],
                'passengerTypeCode' => 'ADT',
                'TravelDocument' => [
                    [
                        '@type'      => 'TravelDocumentDetail',
                        'docNumber'  => strtoupper($passenger['passport_number']),
                        'docType'    => 'Passport',
                        'expireDate' => now()->addYears(3)->format('Y-m-d'),
                        'id'         => 'doc_' . ($index + 1),
                        'Gender'     => ($prefix === 'Mr') ? 'Male' : 'Female',
                        'PersonName' => [
                            '@type'   => 'PersonNameDetail',
                            'Prefix'  => $prefix,
                            'Given'   => strtoupper($passenger['first_name']),
                            'Surname' => strtoupper($passenger['last_name']),
                        ],
                    ]
                ],
            ];
        }

        $payload = [
            '@type' => 'Reservation',
            'id'    => 'REF_' . strtoupper(uniqid()),
            'Identifier' => [
                'value'     => $gdsAuthorityValue,
                'authority' => 'TVPT',
            ],
            'Offer' => [
                [
                    '@type'    => 'Offer',
                    'id'       => 'offer_1',
                    'offerRef' => 'offer_1',
                    'Identifier' => [
                        'value'     => $gdsAuthorityValue,
                        'authority' => 'TVPT',
                    ],
                    'ContentSource' => 'GDS',
                    'Product' => [
                        [
                            '@type'      => 'ProductAir',
                            'id'         => 'product_1',
                            'productRef' => 'product_1',
                            'Identifier' => [
                                'value'     => $gdsAuthorityValue,
                                'authority' => 'TVPT',
                            ],
                        ]
                    ],
                ]
            ],
            'Traveler' => $travelerList,
            'PrimaryContact' => [
                [
                    '@type' => 'PrimaryContact',
                    'id'    => 'pc_1',
                    'Identifier' => [
                        'value'     => $gdsAuthorityValue,
                        'authority' => 'TVPT',
                    ],
                    'Email' => [
                        'value'     => $contactInfo['email'] ?? 'agence@creativtrips.com',
                        'id'        => 'email_pc_1',
                        'emailType' => 'FROM',
                        'validInd'  => true,
                    ],
                    'Telephone' => [
                        '@type'             => 'Telephone',
                        'countryAccessCode' => '237',
                        'phoneNumber'       => str_replace([' ', '+237'], '', $contactInfo['phone'] ?? ''),
                        'role'              => 'Mobile',
                    ],
                ]
            ],
            'TravelAgency' => [
                '@type'                => 'TravelAgencyDetail',
                'id'                   => 'agency_1',
                'TravelOrganizationRef' => 'TravelAgency_1',
                'Identifier' => [
                    'value'     => $gdsAuthorityValue,
                    'authority' => 'TVPT',
                ],
                'organizationType' => 'TravelAgency',
                'OrganizationName' => [
                    'value'        => 'Creativ Solutions',
                    'id'           => 'agency_name_1',
                    'shortName'    => 'CreativTrips',
                    'code'         => 'CT',
                    'codeContext'  => 'ISO',
                ],
            ],
            'autoDeleteDate' => now()->addHours(24)->format('Y-m-d'),
        ];

        $url = rtrim($this->baseUrl, '/') . '/11/air/book/session/reservationworkbench';

        Log::info('[Travelport] createReservationWorkbench → Request', [
            'url'     => $url,
            'payload' => $payload,
        ]);

        try {
            $response = Http::timeout(60)
                ->withToken($token)
                ->acceptJson()
                ->withHeaders([
                    'Accept-Encoding'              => 'gzip, deflate',
                    'Content-Type'                 => 'application/json',
                    'TVP-PCC-Core'                 => $this->pcc,
                    'TraceId'                      => 'Workbench_' . time(),
                    'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group,
                ])
                ->post($url, $payload);

            Log::info('[Travelport] createReservationWorkbench → Response', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);

            if (!$response->successful()) {
                $json  = $response->json() ?? [];
                $error = $json['errors'][0]['message']
                    ?? $json['message']
                    ?? "HTTP {$response->status()} — {$response->body()}";

                throw new \RuntimeException(
                    "createReservationWorkbench failed [{$response->status()}] : {$error}"
                );
            }

            $data = $response->json() ?? [];

            // Extraction du sessionIdentifier UUID retourné par Travelport
            $sessionIdentifier = $data['ReservationResponse']['Reservation']['Identifier']['value']
                ?? $data['Identifier']['value']
                ?? $data['identifier']
                ?? null;

            if (!$sessionIdentifier) {
                Log::error('[Travelport] sessionIdentifier absent', ['body' => $data]);
                throw new \RuntimeException(
                    'Travelport n\'a pas retourné de sessionIdentifier valide.'
                );
            }

            Log::info('[Travelport] Workbench créé avec succès', [
                'session_identifier' => $sessionIdentifier,
            ]);

            return $sessionIdentifier;

        } catch (\RuntimeException $e) {
            throw $e;

        } catch (\Throwable $e) {
            Log::error('[Travelport] createReservationWorkbench → Exception', [
                'message' => $e->getMessage(),
                'url'     => $url,
            ]);

            throw new \RuntimeException(
                'Échec création Workbench Travelport : ' . $e->getMessage()
            );
        }
    }
    /**
     * ÉTAPE 6 : Injecter le moyen de paiement
     * PUT /11/air/book/reservationworkbench/{sessionIdentifier}/formofpayment
     */
/*    public function addFormOfPayment(string $sessionIdentifier, string $paymentMethod, ?string $phoneNumber = null): array
    {
        $token = $this->travelportService->getAccessToken();

        // Mobile Money (MTN / Orange) → Cash Agency
        $fop = match($paymentMethod) {
        'momo', 'om' => [
        '@type'            => 'FormOfPaymentCash',
        'id'               => 'fop_1',
        'FormOfPaymentRef' => 'fop_1',
        'agencyAccountInd' => true,
        'cashInd'          => true,
    ],
        'card' => [
        '@type'            => 'FormOfPaymentPaymentCard',
        'id'               => 'fop_1',
        'FormOfPaymentRef' => 'fop_1',
        'activeInd'        => true,
    ],
        default => throw new \InvalidArgumentException("Méthode de paiement inconnue : {$paymentMethod}"),
    };

    $payload = ['FormOfPayment' => [$fop]];

    $url = rtrim($this->baseUrl, '/') . "/11/air/book/reservationworkbench/{$sessionIdentifier}/formofpayment";

    Log::info('[Travelport] addFormOfPayment → Request', [
        'url'            => $url,
        'payment_method' => $paymentMethod,
        'payload'        => $payload,
    ]);

    $response = Http::timeout(60)
        ->withToken($token)
        ->acceptJson()
        ->withHeaders([
            'Content-Type'                    => 'application/json',
            'TVP-PCC-Core'                    => $this->pcc,
            'TraceId'                         => 'FOP_' . $sessionIdentifier . '_' . time(),
            'XAUTH_TRAVELPORT_ACCESSGROUP'    => $this->access_group,
            'travelportPlusSessionIdentifier' => $sessionIdentifier,
        ])
        ->put($url, $payload);

    Log::info('[Travelport] addFormOfPayment → Response', [
        'status' => $response->status(),
        'body'   => $response->body(),
    ]);

    if (!$response->successful()) {
        $json = $response->json() ?? [];
        $error = $json['errors'][0]['message'] ?? $json['message'] ?? "HTTP {$response->status()}";
        throw new \RuntimeException("addFormOfPayment failed : {$error}");
    }

    return $response->json() ?? [];
}*/
    /**
     * ÉTAPE 8 : Commit final — génère le PNR
     * POST /11/air/book/reservationworkbench/{sessionIdentifier}/commit
     * @param string $sessionIdentifier
     * @return array
     */
    public function commitReservation(string $sessionIdentifier): array
    {
        $token = $this->travelportService->getAccessToken();

        $url = rtrim($this->baseUrl, '/') . "/11/air/book/reservationworkbench/{$sessionIdentifier}/commit";

        Log::info('[Travelport] commitReservation → Request', [
            'url'     => $url,
            'session' => $sessionIdentifier,
        ]);

        $response = Http::timeout(120)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'Commit_' . $sessionIdentifier . '_' . time(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => $this->access_group,
                'travelportPlusSessionIdentifier' => $sessionIdentifier,
            ])
            ->post($url, []);

        Log::info('[Travelport] commitReservation → Response', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        if (!$response->successful()) {
            $json  = $response->json() ?? [];
            $error = $json['errors'][0]['message'] ?? $json['message'] ?? "HTTP {$response->status()}";
            throw new \RuntimeException("commitReservation failed : {$error}");
        }

        $data = $response->json() ?? [];

        // Extraction du PNR
        $pnr = $data['Reservation']['locatorCode']
            ?? $data['ReservationResponse']['Reservation']['locatorCode']
            ?? null;

        if (!$pnr) {
            Log::warning('[Travelport] PNR absent de la réponse commit', ['body' => $data]);
        }

        return [
            'pnr'  => $pnr,
            'raw'  => $data,
        ];
    }
    /**
     * ÉTAPE 9 : Émission des billets électroniques
     * POST /11/air/ticket/reservation/{pnr}/products/ticket
     */
    public function issueTickets(string $pnr): array
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
     * ÉTAPE 2.7 : Construire l'offre ferme et re-tarifer avant encaissement (Air Price)
     * Endpoint GDS: POST /11/air/book/airoffer/reservationworkbench/{reservationResourceIdentifier}/offers/buildfromcatalogproductofferings
     * @param string $reservationResourceIdentifier
     * @param array $selectedFlight
     * @return array
     * @throws \Exception
     */
    public function buildOfferFromCatalog(string $reservationResourceIdentifier, array $selectedFlight): array
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
                'XAUTH_TRAVELPORT_ACCESSGROUP' => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
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

    /**
     * ÉTAPE 3 : Création finale de la réservation (Génération du PNR)
     * Endpoint GDS: POST /11/air/book/reservation/reservations/build
     * @param string $sessionIdentifier
     * @param array $passengersData
     * @param array $selectedFlight
     * @return array
     * @throws \Exception
     */
    public function buildReservation(string $sessionIdentifier, array $passengersData, array $selectedFlight): array
    {
        $token = $this->travelportService->getAccessToken();

        // Formatage des voyageurs selon la structure Travelport+
        $travelers = [];
        foreach ($passengersData as $index => $passenger) {
            $travelers[] = [
                "@type" => "Traveler",
                "id" => "traveler_" . ($index + 1),
                "TravelerRef" => "t" . ($index + 1),
                "Identifier" => [
                    "value" => $selectedFlight['gds_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E',
                    "authority" => "TVPT"
                ]
            ];
        }

        // Payload standardisé pour l'émission/booking direct
        $payload = [
            "ReservationQueryBuild" => [
                "@type" => "ReservationQueryBuild",
                "ReservationBuild" => [
                    "@type" => "ReservationBuildFromProducts",
                    "autoDeleteDate" => now()->addDays(1)->format('Y-m-d'), // Annulation auto si non émis sous 24h
                    "receivedFrom" => "CREATIV_API",
                    "issuance" => "Ticket",
                    "Traveler" => $travelers,
                    "FormOfPayment" => [
                        [
                            "@type" => "FormOfPaymentPaymentCard",
                            "id" => "fop_1",
                            "FormOfPaymentRef" => "fop_1",
                            "Identifier" => [
                                "value" => $selectedFlight['gds_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E',
                                "authority" => "TVPT"
                            ],
                            "activeInd" => true
                        ]
                    ],
                    "PrimaryContact" => [
                        [
                            "@type" => "PrimaryContact",
                            "id" => "pc_1",
                            "Identifier" => [
                                "value" => $selectedFlight['gds_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E',
                                "authority" => "TVPT"
                            ]
                        ]
                    ],
                    "TravelAgency" => [
                        "@type" => "TravelAgencyDetail",
                        "id" => "agency_1",
                        "TravelOrganizationRef" => "TravelAgency_1",
                        "Identifier" => [
                            "value" => $selectedFlight['gds_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E',
                            "authority" => "TVPT"
                        ],
                        "organizationType" => "TravelAgency",
                        "OrganizationName" => [
                            "value" => "Creativ Solutions",
                            "id" => "agency_name_1",
                            "shortName" => "CreativTrips",
                            "code" => "CT",
                            "codeContext" => "ISO"
                        ]
                    ],
                    "scheduleChangeAcceptedInd" => true,
                    "overrideMCTInd" => true,
                    "errorWhenScheduleChangesInd" => true,
                    "errorWhenOfferPriceChangesInd" => true
                ]
            ]
        ];

        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
                'TVP-PCC-Core' => $this->pcc,
                'TraceId' => 'BuildReservation_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP' => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $sessionIdentifier
            ])
            ->post($this->baseUrl . "11/air/book/reservation/reservations/build", $payload);

        if ($response->failed()) {
            Log::critical('Travelport Reservation Build Failed after Payment', [
                'session' => $sessionIdentifier,
                'body' => $response->body()
            ]);
            throw new \Exception('Le paiement a été perçu, mais la création du PNR a échoué. Notre support technique a été alerté.');
        }

        return $response->json();
    }

    /**
     * ÉTAPE 2.8 : Construire et tarifer l'offre à partir des produits du Workbench (Air Price alternatif)
     * Endpoint GDS: POST /11/air/book/airoffer/reservationworkbench/{reservationResourceIdentifier}/offers/buildfromproducts
     * @param string $reservationResourceIdentifier
     * @return array
     * @throws \Exception
     */
    public function buildOfferFromProducts(string $reservationResourceIdentifier): array
    {
        $token = $this->travelportService->getAccessToken();

        // payload normalisé, propre et débarrassé des dysfonctionnements de guillemets
        $payload = [
            "OfferQueryBuildFromProducts" => [
                "@type" => "OfferQueryBuildFromProducts",
                "BuildFromProductsRequest" => [
                    "@type" => "BuildFromProductsRequestAir"
                ],
                "CabinPreference" => [
                    "@type" => "CabinPreference",
                    "preferenceType" => "Preferred",
                    "cabins" => ["Economy"],
                    "legSequence" => [1, 2]
                ],
                "PaymentCriteria" => [
                    "@type" => "PaymentCriteria",
                    // Configuration requise pour la collecte des fonds locale (Momo/Orange Money)
                    "agencyAccountInd" => true,
                    "bspInd" => true,
                    "cashInd" => true,
                    "invoiceInd" => true
                ],
                "FareRuleType" => "Structured",
                "FareRuleCategory" => [
                    "AdvanceReservationsTicketing"
                ],
                "lowFareFinderInd" => true,
                "returnBrandedFaresInd" => true,
                "reCheckInventoryInd" => true, // Sécurité : Forcer la vérification de l'état des sièges
                "validateInventoryInd" => true, // Sécurité : Valider l'inventaire auprès de la compagnie
                "MaxNumberOfUpsellsToReturn" => 4
            ]
        ];

        // Appel unifié avec le client HTTP de Laravel
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
                'TVP-PCC-Core' => $this->pcc,
                'TraceId' => 'BuildFromProd_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP' => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($this->baseUrl . "11/air/book/airoffer/reservationworkbench/{$reservationResourceIdentifier}/offers/buildfromproducts", $payload);

        if ($response->failed()) {
            Log::error('Travelport Offer Build From Products Failed', [
                'resource_id' => $reservationResourceIdentifier,
                'body' => $response->body()
            ]);
            throw new \Exception('Impossible de valider et tarifer les segments sélectionnés auprès du GDS.');
        }

        return $response->json();
    }

    /**
     * ÉTAPE 2.6 : Injecter les informations détaillées des passagers (APIS/Passeport/Contact)
     * Endpoint GDS: POST /11/air/book/traveler/reservationworkbench/{reservationResourceIdentifier}/travelers/list
     * @param string $reservationResourceIdentifier
     * @param array $passengers
     * @param array $contactInfo
     * @param array $selectedFlight
     * @return array
     * @throws \Exception
     */
    /**
     * ÉTAPE 5 : Injecter tous les passagers en un seul appel
     * POST /11/air/book/traveler/reservationworkbench/{sessionIdentifier}/travelers/list
     * @param string $sessionIdentifier
     * @param array $passengers
     * @param array $contactInfo
     * @param array $selectedFlight
     * @return array
     * @throws \Exception
     */

/*    public function addTravelersToWorkbench(
        string $sessionIdentifier,
        array $passengers,
        array $contactInfo,
        array $selectedFlight
    ): array {

        $token = $this->travelportService->getAccessToken();

        // Récupération de l'identifiant GDS ou valeur par défaut du curl
        $gdsAuthorityValue = $selectedFlight['gds_authority_value']
            ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E';

        $travelers = [];

        foreach ($passengers as $index => $passenger) {
            $travelerNumber = $index + 1;

            // Validation de la date de naissance
            if (empty($passenger['birth_date'])) {
                throw new \InvalidArgumentException("La date de naissance est obligatoire pour le passager {$travelerNumber}");
            }

            $birthDate = new \DateTime($passenger['birth_date']);
            if ($birthDate >= new \DateTime('today')) {
                throw new \InvalidArgumentException("Date de naissance invalide pour le passager {$travelerNumber}");
            }

            if (empty($passenger['passport_number'])) {
                throw new \InvalidArgumentException("Passeport obligatoire pour le passager {$travelerNumber}");
            }

            // Calcul exact de l'âge
            $age = $birthDate->diff(new \DateTime())->y;

            // Détermination du Passenger Type Code (PTC)
            $ptc = match (true) {
            $age < 2 => 'INF',
            $age < 12 => 'CHD',
            default => 'ADT'
        };

        // Civilité & Genre
        $prefix = ($passenger['civility'] ?? 'M.') === 'Mme' ? 'Mrs' : 'Mr';
        $gender = $prefix === 'Mr' ? 'Male' : 'Female';
        $nationality = $passenger['nationality'] ?? 'CM';

        // Nettoyage et formatage du numéro de téléphone
        $cleanPhone = preg_replace('/[^0-9]/', '', str_replace('+237', '', $contactInfo['phone'] ?? ''));

        $travelers[] = [
            '@type' => 'Traveler',
            'id' => "traveler_{$travelerNumber}",
            'TravelerRef' => "t{$travelerNumber}",
            'Identifier' => [
                'value' => $gdsAuthorityValue,
                'authority' => 'TVPT',
            ],
            'birthDate' => $passenger['birth_date'],
            'gender' => $gender,
            'nationality' => $nationality,
            'PersonName' => [
                '@type' => 'PersonNameDetail',
                'Prefix' => $prefix,
                'Given' => strtoupper($passenger['first_name']),
                'Surname' => strtoupper($passenger['last_name']),
            ],
            'Address' => [[
                '@type' => 'AddressDetail',
                'id' => "Address_{$travelerNumber}",
                'BldgRoom' => [
                    'value' => $passenger['address_building'] ?? 'Immeuble',
                    'buldingInd' => true
                ],
                'Number' => [
                    'value' => $passenger['address_number'] ?? '123',
                ],
                'Street' => $passenger['address_street'] ?? 'Rue Non Renseignée',
                'AddressLine' => [
                    $passenger['address_line'] ?? 'Douala Cameroun'
                ],
                'City' => $passenger['address_city'] ?? 'Douala',
                'County' => $passenger['address_county'] ?? 'Littoral',
                'StateProv' => [
                    'value' => $passenger['address_state_code'] ?? 'LT',
                    'name' => $passenger['address_state_name'] ?? 'Littoral'
                ],
                'Country' => [
                    'value' => $passenger['address_country_code'] ?? 'CM',
                    'name' => $passenger['address_country_name'] ?? 'Cameroun',
                    'codeContext' => 'IATA'
                ],
                'PostalCode' => $passenger['address_postal_code'] ?? '00237',
                'Addressee' => strtoupper($passenger['last_name']) . ' ' . strtoupper($passenger['first_name']),
                'role' => 'Business'
            ]],
            'Telephone' => [[
                '@type' => 'Telephone',
                'countryAccessCode' => '237',
                'areaCityCode' => $passenger['phone_area_code'] ?? '972', // Requis par l'API
                'phoneNumber' => $cleanPhone,
                'cityCode' => 'DLA',
                'role' => 'Mobile'
            ]],
            'Email' => [[
                'value' => $contactInfo['email'] ?? 'agence@creativtrips.com',
                'id' => "email_{$travelerNumber}",
                'emailType' => 'FROM',
                'validInd' => true
            ]],
            'passengerTypeCode' => $ptc,
            'age' => (int) $age,
            'TravelDocument' => [[
                '@type' => 'TravelDocumentDetail',
                'id' => "doc_{$travelerNumber}",
                'docNumber' => strtoupper($passenger['passport_number']),
                'docType' => 'Passport',
                // Utilisation des vraies dates du passager si disponibles, sinon fallback
                'issueDate' => $passenger['passport_issue_date'] ?? now()->subYears(2)->format('Y-m-d'),
                'expireDate' => $passenger['passport_expire_date'] ?? now()->addYears(3)->format('Y-m-d'),
                'issueCountry' => $nationality,
                'birthCountry' => $passenger['birth_country'] ?? $nationality,
                'birthDate' => $passenger['birth_date'],
                'Gender' => $gender,
                'Nationality' => $nationality,
                'PersonName' => [
                    '@type' => 'PersonNameDetail',
                    'Prefix' => $prefix,
                    'Given' => strtoupper($passenger['first_name']),
                    'Surname' => strtoupper($passenger['last_name']),
                ]
            ]]
        ];
    }

        $payload = [
            'Traveler' => $travelers
        ];

        $url = rtrim($this->baseUrl, '/')
            . "/11/air/book/traveler/reservationworkbench/{$sessionIdentifier}/travelers/list";

        Log::info('Travelport Add Travelers Request', [
            'url' => $url,
            'sessionIdentifier' => $sessionIdentifier,
            'payload' => $payload
        ]);

        // Envoi de la requête avec les headers du Curl
        $response = Http::timeout(60)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'Accept-Encoding' => 'gzip, deflate',
                'Content-Type' => 'application/json',
                'TVP-PCC-Core' => $this->pcc ?? 'DU7_1G',
                'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group ?? '19Y88702-C27A-4E5D-829A-89D7016688B1',
                'travelportPlusSessionIdentifier' => $sessionIdentifier,
                'TraceId' => 'TraceID_' . time() . '_' . uniqid(),
            ])
            ->post($url, $payload);

        Log::info('Travelport Add Travelers Response', [
            'status' => $response->status(),
            'body' => $response,
        ]);

        if (!$response->successful()) {
            $error = $response->json()['errors'][0]['message']
                ?? $response->json()['message']
                ?? $response->body();

            throw new \RuntimeException("Travelport Error [{$response->status()}] : {$error}");
        }

        $data = $response->json();
        $root = $data['TravelerListResponse'] ?? [];

        return [
            'status' => $root['reservationStatus'] ?? null,
            'transactionId' => $root['transactionId'] ?? null,
            'travelerIds' => $root['TravelerID'] ?? [],
            'raw' => $data
        ];
    }*/

    public function addTravelersToWorkbench(
        string $sessionIdentifier,
        array $passengers,
        array $contactInfo,
        array $selectedFlight
    ): array {

        $token = $this->travelportService->getAccessToken();
        $version = '11';

        $url = rtrim($this->baseUrl, '/')
            . "/{$version}/air/book/traveler/reservationworkbench/{$sessionIdentifier}/travelers";

        // Tableau pour stocker les réponses de chaque passager
        $responses = [];

        foreach ($passengers as $index => $passenger) {
            $travelerNumber = $index + 1;

            // Détermination du genre
            $gender = ($passenger['gender'] ?? 'Male') === 'Female' ? 'Female' : 'Male';

            // Payload pour UN SEUL voyageur
            $singleTravelerPayload = [
                '@type' => 'Traveler',
                'gender' => $gender,
                'birthDate' => $passenger['birth_date'] ?? '1986-11-11',
                'id' => "trav_{$travelerNumber}",
                'passengerTypeCode' => $passenger['passenger_type_code'] ?? 'ADT',

                'PersonName' => [
                    '@type' => 'PersonNameDetail',
                    'Given' => $passenger['first_name'] ?? 'TestFirst',
                    'Surname' => $passenger['last_name'] ?? 'TestLast'
                ],

                'Telephone' => [[
                    '@type' => 'Telephone',
                    'countryAccessCode' => $contactInfo['country_access_code'] ?? '1',
                    'phoneNumber' => preg_replace('/[^0-9]/', '', $contactInfo['phone'] ?? '212456121'),
                    'id' => "4",
                    'cityCode' => $contactInfo['city_code'] ?? 'ORD',
                    'role' => 'Home'
                ]],

                'Email' => [[
                    'value' => $passenger['email'] ?? $contactInfo['email'] ?? 'TravelerOne@gmail.com'
                ]],

                'TravelDocument' => [[
                    '@type' => 'TravelDocumentDetail',
                    'docNumber' => strtoupper($passenger['passport_number'] ?? 'A123123'),
                    'docType' => 'Passport',
                    'expireDate' => $passenger['passport_expire_date'] ?? '2029-01-01',
                    'issueCountry' => $passenger['passport_issue_country'] ?? 'CM',
                    'birthDate' => $passenger['birth_date'] ?? '1986-11-11',
                    'Gender' => $gender,

                    'PersonName' => [
                        '@type' => 'PersonName',
                        'Given' => $passenger['first_name'] ?? 'TestFirst',
                        'Surname' => $passenger['last_name'] ?? 'TestLast'
                    ]
                ]]
            ];

            // Log de la requête courante
            Log::info("Travelport Add Traveler #{$travelerNumber} Request", [
                'url' => $url,
                'payload' => $singleTravelerPayload
            ]);

            // Envoi de la requête HTTP pour CE passager
            $response = Http::timeout(60)
                ->withToken($token)
                ->acceptJson()
                ->withHeaders([
                    'Accept-Encoding' => 'gzip, deflate',
                    'Content-Type' => 'application/json',
                    'TVP-PCC-Core' => $this->pcc ?? 'DU7_1G',
                    'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group ?? '19Y88702-C27A-4E5D-829A-89D7016688B1',
                    'travelportPlusSessionIdentifier' => $sessionIdentifier,
                    'TraceId' => 'TraceID_' . time() . '_' . $travelerNumber,
                ])
                ->post($url, $singleTravelerPayload);

            // Vérification du succès de la requête individuelle
            if (!$response->successful()) {
                $error = $response->json()['errors'][0]['message'] ?? $response->body();
                throw new \RuntimeException("Travelport Error on Passenger #{$travelerNumber} [{$response->status()}]: {$error}");
            }

            // On accumule la réponse réussie
            $responses[] = $response->json();
        }

        // Retourne l'ensemble des réponses des passagers ajoutés
        return $responses;
    }
    public function addFormOfPayment(string $sessionIdentifier, array $paymentData): array
    {
        $token = $this->travelportService->getAccessToken();
        $version = '11'; // Modifiez selon votre version d'API

        $url = rtrim($this->baseUrl, '/')
            . "/{$version}/air/payment/reservationworkbench/{$sessionIdentifier}/formofpayment";

        // Formatage du payload selon votre modèle de carte
        $payload = [
            "@type" => "FormOfPaymentPaymentCard",
            "id" => "formOfPayment_1",
            "FormOfPaymentRef" => "formOfPayment_1",
            "Identifier" => [
                "authority" => "Travelport",
                "value" => "A0656EFF-FAF4-456F-B061-0161008D6FOP"
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
        $version = '11';

        $url = rtrim($this->baseUrl, '/')
            . "/{$version}/air/paymentoffer/reservationworkbench/{$sessionIdentifier}/payments";

        // Récupération des informations de l'offre (généralement reçues lors du re-pricing/build offer)
        $offerId = $selectedFlight['offer_id'] ?? 'offer_1';
        $authority = $selectedFlight['offer_authority'] ?? 'Travelport';
        $authorityValue = $selectedFlight['offer_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D6A5E';

        $payload = [
            "@type" => "Payment",
            "id" => "payment_1",
            "Identifier" => [
                "authority" => "Travelport",
                "value" => "A0656EFF-FAF4-456F-B061-0161008D6A5E"
            ],
            "Amount" => [
                "code" => $currencyCode, // ex: "XAF", "EUR", "USD"
                "minorUnit" => 2,
                "currencySource" => "Charged",
                "value" => (float) $totalPrice
            ],
            "FormOfPaymentIdentifier" => [
                "id" => "formOfPayment_1",
                "FormOfPaymentRef" => "formOfPayment_1",
                "Identifier" => [
                    "authority" => "Travelport",
                    "value" => "A0656EFF-FAF4-456F-B061-0161008D6FOP"
                ]
            ],
            "OfferIdentifier" => [[
                "id" => $offerId,
                "offerRef" => $offerId,
                "Identifier" => [
                    "authority" => $authority,
                    "value" => $authorityValue
                ]
            ]]
        ];

        $response = Http::timeout(60)
            ->withToken($token)
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'TVP-PCC-Core' => $this->pcc ?? 'DU7_1G',
                'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group ?? '19Y88702-C27A-4E5D-829A-89D7016688B1',
                'travelportPlusSessionIdentifier' => $sessionIdentifier,
                'TraceId' => 'TraceID_PAY_' . time(),
            ])
            ->post($url, $payload);

        if (!$response->successful()) {
            $error = $response->json()['errors'][0]['message'] ?? $response->body();
            throw new \RuntimeException("Travelport Payment Error [{$response->status()}]: {$error}");
        }

        return $response->json();
    }
}

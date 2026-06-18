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
        $this->pcc          = config('services.travelport.pcc', 'DU7_1G');
        $this->access_group          = config('services.travelport.access_group', 'DU7_1G');
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
        return $this->travelportService->searchOffers( $criteria);
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
                'Accept-Encoding'                => 'gzip, deflate',
                'Content-Type'                   => 'application/json',
                'TVP-PCC-Core'                   => $this->pcc,
                'TraceId'                        => 'Verify_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'   => $this->access_group,
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($this->baseUrl . '11/air/search/airAvailability', $payload);

        if ($response->failed()) {
            Log::warning('Travelport AirAvailability Check Failed', [
                'status' => $response->status(),
                'body'   => $response->body()
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
     * Endpoint:
     * PUT /11/air/book/profile/reservationworkbench/{identifier}/clientprofile
     *
     * @param string $sessionIdentifier
     * @param array $passengersData
     * @return array
     * @throws \Exception
     */
    public function applyClientProfile(
        string $sessionIdentifier,
        array $passengersData
    ): array {

        if (empty($sessionIdentifier)) {
            throw new \InvalidArgumentException(
                'Le sessionIdentifier Travelport est obligatoire.'
            );
        }

        $token = $this->travelportService->getAccessToken();

        $leadPassenger = $passengersData[0] ?? null;

        $personalTitle = 'SMITH/J';

        if ($leadPassenger) {
            $lastName = strtoupper(
                preg_replace('/[^A-Za-z]/', '', $leadPassenger['last_name'] ?? '')
            );

            $firstName = strtoupper(
                preg_replace('/[^A-Za-z]/', '', $leadPassenger['first_name'] ?? '')
            );

            if ($lastName && $firstName) {
                $personalTitle = $lastName . '/' . substr($firstName, 0, 1);
            }
        }

        /**
         * Payload minimal recommandé pour les premiers tests.
         */
        $payload = [
            'ClientProfileMoveHeaderModifiers' => [
                'BusinessTitle'        => 'CREATIV_TRIPS',
                'PersonalTitle'        => $personalTitle,
                'MultipleIndicator'    => true,
                'SelectIndicator'      => true,
                'MergeIndicator'       => true,
                'RelatedMoveIndicator' => 'Y'
            ]
        ];

        $url = rtrim($this->baseUrl, '/') .
            "/11/air/book/profile/reservationworkbench/{$sessionIdentifier}/clientprofile";

        Log::info('Travelport Client Profile Request', [
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
                    'TraceId' => 'Profile_' . uniqid(),
                    'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group,
                    'travelportPlusSessionIdentifier' => $sessionIdentifier,
                ])
                ->put($url, $payload);

            Log::info('Travelport Client Profile Response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            if (!$response->successful()) {

                $errorMessage = 'Erreur Travelport';

                $json = $response->json();

                if (isset($json['errors'][0]['message'])) {
                    $errorMessage = $json['errors'][0]['message'];
                } elseif (isset($json['message'])) {
                    $errorMessage = $json['message'];
                }

                throw new \Exception(
                    "Client Profile Injection Failed : {$errorMessage}"
                );
            }

            return $response->json();

        } catch (\Throwable $e) {

            Log::error('Travelport Client Profile Exception', [
                'session_identifier' => $sessionIdentifier,
                'message' => $e->getMessage(),
                'payload' => $payload,
                'url' => $url,
            ]);

            throw new \Exception(
                'Échec de la synchronisation du profil client Travelport : ' .
                $e->getMessage()
            );
        }
    }
    /**
     * ÉTAPE 2.7 : Construire l'offre ferme et re-tarifer avant encaissement (Air Price)
     * Endpoint GDS: POST /11/air/book/airoffer/reservationworkbench/{reservationResourceIdentifier}/offers/buildfromcatalogproductofferings
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
                'Accept-Encoding'                 => 'gzip, deflate',
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'BuildOffer_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($this->baseUrl . "11/air/book/airoffer/reservationworkbench/{$reservationResourceIdentifier}/offers/buildfromcatalogproductofferings", $payload);

        if ($response->failed()) {
            Log::error('Travelport Offer Build From Catalog Failed', [
                'resource_id' => $reservationResourceIdentifier,
                'body'        => $response->body()
            ]);
            throw new \Exception('Erreur critique lors de la tarification finale du vol auprès de la compagnie.');
        }

        return $response->json();
    }
    /**
     * ÉTAPE 3 : Création finale de la réservation (Génération du PNR)
     * Endpoint GDS: POST /11/air/book/reservation/reservations/build
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
                    "receivedFrom"   => "CREATIV_API",
                    "issuance"       => "Ticket",
                    "Traveler"       => $travelers,
                    "FormOfPayment"  => [
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
                    "scheduleChangeAcceptedInd"   => true,
                    "overrideMCTInd"              => true,
                    "errorWhenScheduleChangesInd" => true,
                    "errorWhenOfferPriceChangesInd" => true
                ]
            ]
        ];

        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding'                 => 'gzip, deflate',
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'BuildReservation_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $sessionIdentifier
            ])
            ->post($this->baseUrl . "11/air/book/reservation/reservations/build", $payload);

        if ($response->failed()) {
            Log::critical('Travelport Reservation Build Failed after Payment', [
                'session' => $sessionIdentifier,
                'body'    => $response->body()
            ]);
            throw new \Exception('Le paiement a été perçu, mais la création du PNR a échoué. Notre support technique a été alerté.');
        }

        return $response->json();
    }
    /**
     * ÉTAPE 2.8 : Construire et tarifer l'offre à partir des produits du Workbench (Air Price alternatif)
     * Endpoint GDS: POST /11/air/book/airoffer/reservationworkbench/{reservationResourceIdentifier}/offers/buildfromproducts
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
                "lowFareFinderInd"      => true,
                "returnBrandedFaresInd" => true,
                "reCheckInventoryInd"   => true, // Sécurité : Forcer la vérification de l'état des sièges
                "validateInventoryInd"  => true, // Sécurité : Valider l'inventaire auprès de la compagnie
                "MaxNumberOfUpsellsToReturn" => 4
            ]
        ];

        // Appel unifié avec le client HTTP de Laravel
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding'                 => 'gzip, deflate',
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'BuildFromProd_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($this->baseUrl . "11/air/book/airoffer/reservationworkbench/{$reservationResourceIdentifier}/offers/buildfromproducts", $payload);

        if ($response->failed()) {
            Log::error('Travelport Offer Build From Products Failed', [
                'resource_id' => $reservationResourceIdentifier,
                'body'        => $response->body()
            ]);
            throw new \Exception('Impossible de valider et tarifer les segments sélectionnés auprès du GDS.');
        }

        return $response->json();
    }
    /**
     * ÉTAPE 2.6 : Injecter les informations détaillées des passagers (APIS/Passeport/Contact)
     * Endpoint GDS: POST /11/air/book/traveler/reservationworkbench/{reservationResourceIdentifier}/travelers/list
     */
    public function addTravelersToWorkbench(string $reservationResourceIdentifier, array $passengers, array $contactInfo, array $selectedFlight): array
    {
        $token = $this->travelportService->getAccessToken();
        $gdsAuthorityValue = $selectedFlight['gds_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E';

        $travelerList = [];
        foreach ($passengers as $index => $passenger) {
            // Nettoyage de la civilité pour correspondre aux préfixes GDS standards (Mr, Mrs, Ms)
            $prefix = ($passenger['civility'] === 'Mme') ? 'Mrs' : 'Mr';
            $passengerId = "traveler_" . ($index + 1);
            $passengerRef = "t" . ($index + 1);

            // Calcul de l'âge de manière basique à partir de sa date de naissance
            $birthDate = new \DateTime($passenger['birth_date']);
            $age = $birthDate->diff(now())->y;
            $ptc = ($age < 12) ? 'CHD' : 'ADT'; // Détermination du Passenger Type Code (Adulte ou Enfant)

            $travelerList[] = [
                "@type" => "Traveler",
                "id" => $passengerId,
                "TravelerRef" => $passengerRef,
                "Identifier" => [
                    "value" => $gdsAuthorityValue,
                    "authority" => "TVPT"
                ],
                "birthDate" => $passenger['birth_date'],
                "gender" => ($prefix === 'Mr') ? 'Male' : 'Female',
                "PersonName" => [
                    "@type" => "PersonNameDetail",
                    "Prefix" => $prefix,
                    "Given" => strtoupper($passenger['first_name']),
                    "Surname" => strtoupper($passenger['last_name'])
                ],
                "Telephone" => [
                    [
                        "@type" => "Telephone",
                        "countryAccessCode" => "237", // Indicatif Cameroun par défaut
                        "phoneNumber" => str_replace([' ', '+237'], '', $contactInfo['phone'] ?? ''),
                        "role" => "Mobile"
                    ]
                ],
                "Email" => [
                    [
                        "value" => $contactInfo['email'] ?? 'agence@creativtrips.com',
                        "id" => "email_" . ($index + 1),
                        "emailType" => "FROM",
                        "validInd" => true
                    ]
                ],
                "passengerTypeCode" => $ptc,
                "age" => $age,
                "TravelDocument" => [
                    [
                        "@type" => "TravelDocumentDetail",
                        "docNumber" => strtoupper($passenger['passport_number']),
                        "docType" => "Passport",
                        "expireDate" => now()->addYears(3)->format('Y-m-d'), // À rendre dynamique si collecté au front
                        "id" => "doc_" . ($index + 1),
                        "Gender" => ($prefix === 'Mr') ? 'Male' : 'Female',
                        "PersonName" => [
                            "@type" => "PersonNameDetail",
                            "Prefix" => $prefix,
                            "Given" => strtoupper($passenger['first_name']),
                            "Surname" => strtoupper($passenger['last_name'])
                        ]
                    ]
                ]
            ];
        }

        $payload = [
            "Traveler" => $travelerList
        ];

        // Appel unifié à Travelport via le client HTTP de Laravel
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding'                 => 'gzip, deflate',
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'AddTravelers_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($this->baseUrl . "11/air/book/traveler/reservationworkbench/{$reservationResourceIdentifier}/travelers/list", $payload);

        if ($response->failed()) {
            Log::error('Travelport Adding Travelers List Failed', [
                'resource_id' => $reservationResourceIdentifier,
                'body'        => $response->body()
            ]);
            throw new \Exception('Impossible d\'enregistrer les informations d\'état civil des passagers auprès du GDS.');
        }

        return $response->json();
    }
}

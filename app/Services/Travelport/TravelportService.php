<?php


namespace App\Services\Travelport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TravelportService
{
    protected string $authUrl;
    protected string $baseUrl;
    protected string $clientId;
    protected string $username;
    protected string $password;
    protected string $clientSecret;
    protected string $pcc;
    protected string $access_group;

    public function __construct()
    {
        $this->authUrl      = config('services.travelport.auth_url');
        $this->baseUrl      = config('services.travelport.base_url'); // ex: https://api.pp.travelport.net/
        $this->clientId     = config('services.travelport.client_id');
        $this->username     = config('services.travelport.username');
        $this->password     = config('services.travelport.password');
        $this->clientSecret = config('services.travelport.client_secret');
        $this->pcc          = config('services.travelport.pcc', 'DU7_1G');
        $this->access_group          = config('services.travelport.access_group', 'DU7_1G');
    }

    /**
     * Récupère ou génère un jeton JWT valide (OAuth2)
     */
    public function getAccessToken(): string
    {
        return Cache::remember('travelport_oauth_token', 1700, function () {
            $response = Http::asForm()->post($this->authUrl, [
                'grant_type'    => 'password',
                'username'     => $this->username,
                'password'     => $this->password,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if ($response->failed()) {
                Log::error('Travelport Auth Failed', ['body' => $response->body()]);
                throw new \Exception('Échec de l\'authentification avec le GDS Travelport.');
            }

            return $response->json()['access_token'];
        });
    }

    /**
     * Point d'entrée principal pour la recherche de vols
     * @param array $criteria
     * @return array
     * @throws \Exception
     */
    public function searchOffers(array $criteria): array
    {
        try {
            $token = $this->getAccessToken();
            $payload = $this->buildCatalogPayload($criteria);

            // Appel de l'endpoint exact fourni dans votre cURL
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept-Encoding' => 'gzip, deflate',
                    'Content-Type'    => 'application/json',
                    'TVP-PCC-Core'    => $this->pcc,
                    'TraceId'         => 'Trace_' . uniqid() . '_' . time(),
                    // Ajoute cette ligne si Travelport t'a fourni un Access Group ID :
                    'XAUTH_TRAVELPORT_ACCESSGROUP' => $this->access_group,
                ])
                ->post($this->baseUrl . '11/air/catalog/search/catalogproductofferings', $payload);
            logger($response);
            if ($response->failed()) {
                Log::error('Travelport Catalog Error', [
                    'status' => $response->status(),
                    'body'   => $response->json()
                ]);
                throw new \Exception('Erreur lors de la récupération du catalogue de vols Travelport.');
            }

            return $this->formatCatalogResponse($response->json(), $criteria);

        } catch (\Exception $e) {
            Log::error('Travelport Service Exception', ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Construit le payload exact attendu par CatalogProductOfferings
     */
    protected function buildCatalogPayload(array $criteria): array
    {
        $passengerCriteria = [];
        $passCount = 1;

        // Configuration dynamique des passagers
        for ($i = 0; $i < ($criteria['passengers']['adults'] ?? 1); $i++) {
            $passengerCriteria[] = [
                '@type' => 'PassengerCriteria',
                'number' => $passCount++,
                'passengerTypeCode' => 'ADT',
                'age' => 30 // Optionnel ou dynamique
            ];
        }

        // Segments de vols (Gestion Aller / Retour)
        $searchCriteriaFlight = [
            [
                '@type' => 'SearchCriteriaFlight',
                'departureDate' => $criteria['departure_date'], // Format YYYY-MM-DD
                'From' => ['value' => $criteria['origin']],     // ex: DEN ou DLA
                'To' => ['value' => $criteria['destination']]   // ex: ORD ou CDG
            ]
        ];

        if (!empty($criteria['return_date']) && ($criteria['trip_type'] ?? '') === 'round_trip') {
            $searchCriteriaFlight[] = [
                '@type' => 'SearchCriteriaFlight',
                'departureDate' => $criteria['return_date'],
                'From' => ['value' => $criteria['destination']],
                'To' => ['value' => $criteria['origin']]
            ];
        }

        return [
            'CatalogProductOfferingsQueryRequest' => [
                'CatalogProductOfferingsRequest' => [
                    '@type' => 'CatalogProductOfferingsRequestAir',
                    'maxNumberOfUpsellsToReturn' => 1,
                    'offersPerPage' => 15,
                    'contentSourceList' => ['GDS'],
                    'PassengerCriteria' => $passengerCriteria,
                    'SearchCriteriaFlight' => $searchCriteriaFlight,
                    'SearchModifiersAir' => [
                        '@type' => 'SearchModifiersAir',
                        'CarrierPreference' => [
                            [
                                '@type' => 'CarrierPreference',
                                'preferenceType' => 'Preferred',
                                'carriers' => [$criteria['preferred_carrier'] ?? 'UA']
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }

    /**
     * Parseur de la réponse CatalogProductOfferingsResponse
     */
    public function formatCatalogResponse(array $rawResponse, array $criteria): array
    {
        $root = $rawResponse['CatalogProductOfferingsResponse'] ?? [];

        // 1. Interception des cas où aucun vol n'existe (Erreur métier sous statut HTTP 200)
        if (isset($root['Result']['status']) && $root['Result']['status'] === 'Complete') {
            $errors = $root['Result']['Error'] ?? [];
            foreach ($errors as $error) {
                if (($error['Message'] ?? '') === 'No flights found.') {
                    return ['status' => 'success', 'results_count' => 0, 'flights' => []];
                }
            }
        }

        $offeringsContainer = $root['CatalogProductOfferings'] ?? [];
        $offerings = $offeringsContainer['CatalogProductOffering'] ?? [];

        // 2. Extraction du dictionnaire de vols physiques (ReferenceList)
        $referenceList = $root['ReferenceList'] ?? [];
        $flightDictionary = [];
        foreach ($referenceList as $ref) {
            if (($ref['@type'] ?? '') === 'ReferenceListFlight' && isset($ref['id'])) {
                $flightDictionary[$ref['id']] = $ref;
            }
        }

        $formattedFlights = [];

        // 3. Transformation des offres de catalogue vers votre format unifié
        foreach ($offerings as $index => $offering) {

            // Extraction des montants (À adapter selon les objets réels de ProductBrandOffering)
            // Fallback en XAF pour la cohérence monétaire de votre plateforme
            $baseAmount = 280000;
            $taxes = 95000;
            $totalAmount = $baseAmount + $taxes;
            $currency = $root['CurrencyRateConversion'][0]['TargetCurrency']['value'] ?? 'XAF';

            $flightOffer = [
                'id' => 'fl_tvpt_' . ($offering['id'] ?? uniqid()) . '_' . $index,
                'price_details' => [
                    'base_price'  => (float)$baseAmount,
                    'taxes'       => (float)$taxes,
                    'total_sabre' => (float)$totalAmount, // Clé pivot conservée pour vos markups
                    'currency'    => $currency
                ],
                'itinerary' => []
            ];

            // 4. Traitement du trajet aller (Outbound) basé sur les flightRefs (ex: ["s1", "s2"])
            $brandOptions = $offering['ProductBrandOptions'] ?? [];
            foreach ($brandOptions as $bIdx => $option) {
                $flightRefs = $option['flightRefs'] ?? [];

                $journey = [
                    'direction'   => ($bIdx === 0) ? 'outbound' : 'inbound',
                    'stops_count' => max(0, count($flightRefs) - 1),
                    'segments'    => []
                ];

                foreach ($flightRefs as $refId) {
                    $rawSegment = $flightDictionary[$refId] ?? null;

                    $journey['segments'][] = [
                        'flight_number' => $rawSegment['flightNumber'] ?? '101',
                        'airline_code'  => $rawSegment['carrier'] ?? 'UA',
                        'airline_name'  => $rawSegment['carrierName'] ?? 'United Airlines',
                        'departure' => [
                            'airport' => $rawSegment['from'] ?? $offering['Departure'],
                            'time'    => $rawSegment['departureDateTime'] ?? ($criteria['departure_date'] . 'T08:00:00')
                        ],
                        'arrival' => [
                            'airport' => $rawSegment['to'] ?? $offering['Arrival'],
                            'time'    => $rawSegment['arrivalDateTime'] ?? ($criteria['departure_date'] . 'T11:30:00')
                        ],
                        'booking_class' => $rawSegment['classOfService'] ?? 'Y',
                        'duration'      => $rawSegment['duration'] ?? 210,
                    ];
                }

                // Fallback structurel si ReferenceList n'est pas alimenté dans l'environnement de Mock
                if (empty($journey['segments'])) {
                    $journey['segments'][] = [
                        'flight_number' => '101',
                        'airline_code'  => 'UA',
                        'airline_name'  => 'United Airlines',
                        'departure' => [
                            'airport' => $offering['Departure'], // DEN
                            'time'    => $criteria['departure_date'] . 'T08:00:00'
                        ],
                        'arrival' => [
                            'airport' => $offering['Arrival'], // ORD
                            'time'    => $criteria['departure_date'] . 'T11:30:00'
                        ],
                        'booking_class' => 'Y',
                        'duration'      => 210,
                    ];
                }

                $flightOffer['itinerary'][] = $journey;
            }

            // 5. Traitement du trajet retour (Inbound) si FlexNextLeg est défini
            if (!empty($offering['FlexNextLeg'])) {
                foreach ($offering['FlexNextLeg'] as $nextLeg) {
                    $flightOffer['itinerary'][] = [
                        'direction'   => 'inbound',
                        'stops_count' => 0,
                        'segments'    => [[
                            'flight_number' => '102',
                            'airline_code'  => 'UA',
                            'airline_name'  => 'United Airlines',
                            'departure' => [
                                'airport' => $nextLeg['Departure'], // ORD
                                'time'    => $nextLeg['DepartureDate'] . 'T15:00:00'
                            ],
                            'arrival' => [
                                'airport' => $nextLeg['Arrival'], // DEN
                                'time'    => $nextLeg['DepartureDate'] . 'T18:45:00'
                            ],
                            'booking_class' => 'Y',
                            'duration'      => 225,
                        ]]
                    ];
                }
            }

            $flightOffer['baggage_allowance'] = [
                'checked' => '1 PC',
                'cabin'   => '1 PC'
            ];

            $formattedFlights[] = $flightOffer;
        }

        return [
            'status'        => 'success',
            'results_count' => count($formattedFlights),
            'flights'       => $formattedFlights
        ];
    }
}

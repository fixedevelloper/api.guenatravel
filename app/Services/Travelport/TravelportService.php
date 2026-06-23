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
     * Parseur de la réponse CatalogProductOfferingsResponse enrichi pour AirPrice
     * @param array $rawResponse
     * @param array $criteria
     * @return array
     */
    public function formatCatalogResponse(array $rawResponse, array $criteria): array
    {


        $root = $rawResponse['CatalogProductOfferingsResponse'] ?? [];

        if (isset($root['Result']['Error']) && is_array($root['Result']['Error'])) {

            foreach ($root['Result']['Error'] as $error) {

                if (($error['Message'] ?? '') === 'No flights found.') {

                    return [
                        'status' => 'success',
                        'results_count' => 0,
                        'flights' => []
                    ];
                }
            }
        }

        $transactionId = $root['transactionId'] ?? null;

        $catalogOfferingsNode = $root['CatalogProductOfferings'] ?? [];

        $catalogOfferingsIdentifier =
            $catalogOfferingsNode['Identifier']['value'] ?? null;

        $offerings =
            $catalogOfferingsNode['CatalogProductOffering'] ?? [];

        if (!isset($offerings[0])) {
            $offerings = [$offerings];
        }

        $flightDictionary = [];
        $productDictionary = [];

        foreach (($root['ReferenceList'] ?? []) as $reference) {

            if (
                ($reference['@type'] ?? '') === 'ReferenceListFlight'
                && isset($reference['Flight'])
            ) {

                $flights = isset($reference['Flight'][0])
                    ? $reference['Flight']
                    : [$reference['Flight']];

                foreach ($flights as $flight) {

                    if (!empty($flight['id'])) {
                        $flightDictionary[$flight['id']] = $flight;
                    }
                }
            }

            if (
                ($reference['@type'] ?? '') === 'ReferenceListProduct'
                && isset($reference['Product'])
            ) {

                $products = isset($reference['Product'][0])
                    ? $reference['Product']
                    : [$reference['Product']];

                foreach ($products as $product) {

                    if (!empty($product['id'])) {
                        $productDictionary[$product['id']] = $product;
                    }
                }
            }
        }

        $formattedFlights = [];

        foreach ($offerings as $index => $offering) {

            $offeringId = $offering['id'] ?? null;

            $currency =
                $root['CurrencyRateConversion'][0]['TargetCurrency']['value']
                ?? 'XAF';

            $basePrice = 0;
            $taxes = 0;
            $totalPrice = 0;

            $productBrandOptions = $offering['ProductBrandOptions'] ?? [];

            $firstOption = isset($productBrandOptions[0])
                ? $productBrandOptions[0]
                : $productBrandOptions;

            $brandOfferings =
                $firstOption['ProductBrandOffering'] ?? [];

            $firstBrandOffering = isset($brandOfferings[0])
                ? $brandOfferings[0]
                : $brandOfferings;

            $priceDetails =
                $firstBrandOffering['BestCombinablePrice'] ?? [];

            if (!empty($priceDetails)) {

                $basePrice = $priceDetails['Base'] ?? 0;

                $taxes =
                    $priceDetails['TotalTaxes']
                    ?? ($priceDetails['Taxes']['TotalTaxes'] ?? 0);

                $totalPrice =
                    $priceDetails['TotalPrice']
                    ?? ($basePrice + $taxes);

                $currency =
                    $priceDetails['CurrencyCode']['value']
                    ?? $currency;
            }

            $agencyFees = 0;

            $availableBrands = [];
            $productBrandOfferings = [];
            $productRefs = [];
            $flightRefsAll = [];

            foreach (($offering['Brand'] ?? []) as $brand) {

                if (!empty($brand['BrandRef'])) {
                    $availableBrands[] = $brand['BrandRef'];
                }
            }

            $optionsLoop = isset($offering['ProductBrandOptions'][0])
                ? $offering['ProductBrandOptions']
                : [$offering['ProductBrandOptions']];

            foreach ($optionsLoop as $option) {

                foreach (($option['flightRefs'] ?? []) as $flightRef) {
                    $flightRefsAll[] = $flightRef;
                }

                $pboList = isset($option['ProductBrandOffering'][0])
                    ? $option['ProductBrandOffering']
                    : [$option['ProductBrandOffering']];

                foreach ($pboList as $pbo) {

                    $products = [];

                    foreach (($pbo['Product'] ?? []) as $product) {

                        $productRef =
                            $product['productRef'] ?? null;

                        if ($productRef) {
                            $products[] = $productRef;
                            $productRefs[] = $productRef;
                        }
                    }

                    $productBrandOfferings[] = [
                        'brand_ref' =>
                            $pbo['Brand']['BrandRef'] ?? null,

                        'product_refs' => $products,

                        'terms_and_conditions_ref' =>
                            $pbo['TermsAndConditions']['termsAndConditionsRef']
                            ?? null
                    ];
                }
            }

            $flightOffer = [

                'id' => 'fl_tvpt_' . $offeringId . '_' . $index,

                'travelport' => [

                    'transaction_id' => $transactionId,

                    'offering_id' => $offeringId,

                    'gds_authority_value' =>
                        $catalogOfferingsIdentifier,

                    // AJOUT AIRPRICE
                    'catalog_offerings_identifier' =>
                        $catalogOfferingsIdentifier,

                    'available_brands' =>
                        array_values(array_unique($availableBrands)),

                    'product_brand_offerings' =>
                        $productBrandOfferings,

                    'products' =>
                        array_values(array_unique($productRefs)),

                    'flight_refs' =>
                        array_values(array_unique($flightRefsAll)),

                    'raw_offering' => $offering
                ],

                'price_details' => [
                    'base_price' => $basePrice,
                    'taxes' => $taxes,
                    'final_price_to_pay' => $totalPrice + $agencyFees,
                    'currency' => $currency,
                    'agency_fees' => $agencyFees
                ],

                'itinerary' => [],

                'baggage_allowance' => [
                    'checked' => '1 PC',
                    'cabin' => '1 PC'
                ]
            ];

            foreach ($optionsLoop as $brandIndex => $option) {

                if (empty($option)) {
                    continue;
                }

                $brandValue = null;
                $productRef = null;

                if (isset($option['ProductBrandOffering'])) {

                    $offeringsList =
                        isset($option['ProductBrandOffering'][0])
                            ? $option['ProductBrandOffering']
                            : [$option['ProductBrandOffering']];

                    $brandValue =
                        $offeringsList[0]['Brand']['BrandRef']
                        ?? null;

                    $productRef =
                        $offeringsList[0]['Product'][0]['productRef']
                        ?? null;
                }

                $flightRefs =
                    $option['flightRefs'] ?? [];

                $journeyDuration =
                    $productDictionary[$productRef]['totalDuration']
                    ?? null;

                $journey = [

                    'direction' =>
                        $brandIndex === 0
                            ? 'outbound'
                            : 'inbound',

                    'offering_id' => $offeringId,

                    'brand_value' => $brandValue,

                    // AJOUT AIRPRICE
                    'product_ref' => $productRef,
                    'flight_refs' => $flightRefs,

                    'duration' => $journeyDuration,

                    'stops_count' =>
                        max(0, count($flightRefs) - 1),

                    'segments' => []
                ];

                foreach ($flightRefs as $fRefIndex => $flightRef) {

                    $segment =
                        $flightDictionary[$flightRef] ?? null;

                    if (!$segment) {
                        continue;
                    }

                    $bookingClass = null;
                    $cabinType = 'Economy';

                    if (
                        $productRef &&
                        isset($productDictionary[$productRef]['PassengerFlight'][0]['FlightProduct'])
                    ) {

                        $flightProducts =
                            $productDictionary[$productRef]['PassengerFlight'][0]['FlightProduct'];

                        foreach ($flightProducts as $fp) {

                            $sequences =
                                $fp['segmentSequence'] ?? [];

                            if (in_array($fRefIndex + 1, $sequences)) {

                                $bookingClass =
                                    $fp['classOfService'] ?? null;

                                $cabinType =
                                    $fp['cabin'] ?? 'Economy';

                                break;
                            }
                        }
                    }

                    $journey['segments'][] = [

                        // AJOUT AIRPRICE
                        'segment_ref' => $flightRef,
                        'segment_sequence' => $fRefIndex + 1,

                        'flight_number' =>
                            $segment['number']
                            ?? ($segment['FlightNumber'] ?? null),

                        'airline_code' =>
                            $segment['carrier']
                            ?? ($segment['Carrier'] ?? null),

                        'airline_name' =>
                            ($segment['carrier'] === 'SN')
                                ? 'Brussels Airlines'
                                : (
                            ($segment['carrier'] === 'UA')
                                ? 'United Airlines'
                                : 'Compagnie GDS'
                            ),

                        'departure' => [
                            'airport' =>
                                $segment['Departure']['location']
                                ?? null,
                            'time' =>
                                ($segment['Departure']['date'] ?? '')
                                . 'T'
                                . ($segment['Departure']['time'] ?? '')
                        ],

                        'arrival' => [
                            'airport' =>
                                $segment['Arrival']['location']
                                ?? null,
                            'time' =>
                                ($segment['Arrival']['date'] ?? '')
                                . 'T'
                                . ($segment['Arrival']['time'] ?? '')
                        ],

                        'booking_class' => $bookingClass,

                        'cabin' => $cabinType,

                        'duration' =>
                            $segment['duration'] ?? null
                    ];
                }

                if (!empty($journey['segments'])) {
                    $flightOffer['itinerary'][] = $journey;
                }
            }

            if (!empty($flightOffer['itinerary'])) {
                $formattedFlights[] = $flightOffer;
            }
        }

        return [
            'status' => 'success',
            'transaction_id' => $transactionId,
            'results_count' => count($formattedFlights),
            'flights' => $formattedFlights
        ];
    }
    public function formatCatalogResponse2(array $rawResponse, array $criteria): array
    {
        logger($rawResponse);
        $root = $rawResponse['CatalogProductOfferingsResponse'] ?? [];

        // 1. Interception des cas où aucun vol n'existe
        if (isset($root['Result']['status']) && $root['Result']['status'] === 'Complete') {
            $errors = $root['Result']['Error'] ?? [];
            foreach ($errors as $error) {
                if (($error['Message'] ?? '') === 'No flights found.') {
                    return ['status' => 'success', 'results_count' => 0, 'flights' => []];
                }
            }
        }

        // 🔥 INJECTION TECHNIQUE 1 : L'identifiant d'autorité du catalogue indispensable pour le pricing
        $gdsAuthorityValue = $root['CatalogProductOfferings']['Identifier']['value']
            ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E';

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

            // 🔥 INJECTION TECHNIQUE 2 : Le CatalogProductOffering ID indispensable pour fixer le prix
            $offeringId = $offering['id'] ?? 'cpo_default';

            $baseAmount = 280000;
            $taxes = 95000;
            $totalAmount = $baseAmount + $taxes;
            $currency = $root['CurrencyRateConversion'][0]['TargetCurrency']['value'] ?? 'XAF';

            $flightOffer = [
                'id' => 'fl_tvpt_' . $offeringId . '_' . $index,
                'gds_authority_value' => $gdsAuthorityValue, // Injecté à la racine pour votre AirPrice
                'price_details' => [
                    'base_price'         => (float)$baseAmount,
                    'taxes'              => (float)$taxes,
                    'final_price_to_pay' => (float)$totalAmount,
                    'currency'           => $currency
                ],
                'itinerary' => []
            ];

            // 4. Traitement des trajets (Outbound / Inbound)
            $brandOptions = $offering['ProductBrandOptions'] ?? [];
            foreach ($brandOptions as $bIdx => $option) {
                $flightRefs = $option['flightRefs'] ?? [];

                // 🔥 INJECTION TECHNIQUE 3 : Recherche de la structure "Brand" Travelport v11
                // Le brand_value se trouve souvent imbriqué dans ProductBrandOffering ou ProductIdentifier
                $brandValue = $option['ProductBrandOffering']['ProductIdentifier']['value']
                    ?? $option['ProductIdentifier']['value']
                    ?? 'brand_default';

                $journey = [
                    'direction'   => ($bIdx === 0) ? 'outbound' : 'inbound',
                    'offering_id' => $offeringId, // Rattaché au trajet pour être lu par priceFlightOffer
                    'brand_value' => $brandValue, // Rattaché au trajet pour être lu par priceFlightOffer
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

                // Fallback structural Mock
                if (empty($journey['segments'])) {
                    $journey['segments'][] = [
                        'flight_number' => '101',
                        'airline_code'  => 'UA',
                        'airline_name'  => 'United Airlines',
                        'departure' => [
                            'airport' => $offering['Departure'],
                            'time'    => $criteria['departure_date'] . 'T08:00:00'
                        ],
                        'arrival' => [
                            'airport' => $offering['Arrival'],
                            'time'    => $criteria['departure_date'] . 'T11:30:00'
                        ],
                        'booking_class' => 'Y',
                        'duration'      => 210,
                    ];
                }

                $flightOffer['itinerary'][] = $journey;
            }

            // 5. Traitement alternatif FlexNextLeg
            if (!empty($offering['FlexNextLeg'])) {
                foreach ($offering['FlexNextLeg'] as $nextLeg) {
                    $flightOffer['itinerary'][] = [
                        'direction'   => 'inbound',
                        'offering_id' => $offeringId,
                        'brand_value' => 'brand_default',
                        'stops_count' => 0,
                        'segments'    => [[
                            'flight_number' => '102',
                            'airline_code'  => 'UA',
                            'airline_name'  => 'United Airlines',
                            'departure' => [
                                'airport' => $nextLeg['Departure'],
                                'time'    => $nextLeg['DepartureDate'] . 'T15:00:00'
                            ],
                            'arrival' => [
                                'airport' => $nextLeg['Arrival'],
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

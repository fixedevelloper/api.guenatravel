<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SabreService
{
    protected string $apiUrl;
    protected string $clientId;
    protected string $clientSecret;
    protected string $pcc;

    public function __construct()
    {
        $this->apiUrl = config('services.sabre.url');
        $this->clientId = config('services.sabre.client_id');
        $this->clientSecret = config('services.sabre.client_secret');
        $this->pcc = config('services.sabre.pcc');
    }

    /**
     * Génère ou récupère le Token OAuth2 depuis le cache de Laravel.
     */
    /**
     * Génère ou récupère le Token OAuth2 depuis le cache de Laravel.
     */
    protected function getAccessToken(): string
    {
        Log::info('Envoi Secret Sabre Basic: ' . $this->clientId );
        return Cache::remember('sabre_token', 3600, function () {

            // --- CHOISISSEZ LA VARIANTE QUI CORRESPOND À VOTRE COMPTE SABRE ---
            // Essayez la Variante 1. Si elle échoue, commentez-la et activez la Variante 2.

            // Variante 1 (Standard) :
            $base64Credentials = base64_encode($this->clientId . ':' . $this->clientSecret);

            // Variante 2 (Double encodage Sabre) :
             //$base64Credentials = base64_encode(base64_encode($this->clientId) . ':' . base64_encode($this->clientSecret));

            // Débogage (Optionnel) : Vérifiez dans vos logs si cela ressemble à votre cURL
            Log::info('Envoi Header Sabre Basic: ' . $base64Credentials);

            $response = Http::asForm()
                ->withHeaders([
                    'Authorization' => 'Basic ' . $base64Credentials,
                    'Accept'        => 'application/x-www-form-urlencoded',
                ])
                ->post($this->apiUrl . '/v3/auth/token', [
                    'grant_type' => 'password',
                    'username'   => config('services.sabre.username'), // ugt4binobrc3kyy8-DEVCENTER-EXT
                    'password'   => config('services.sabre.password'), // abcd1234
                ]);

            if ($response->failed()) {
                Log::error('Sabre Auth Failure inside Service: ' . $response->body());
                throw new \Exception("Erreur de credentials Sabre : " . $response->json()['error_description'] ?? $response->body());
            }

            return $response->json()['access_token'];
        });
    }

    /**
     * Recherche de vols (Bargain Finder Max API)
     */
    public function searchFlights(array $criteria): array
    {
        $token = $this->getAccessToken();

        // Formatage de la date pour correspondre aux exigences de Sabre (YYYY-MM-DDTHH:MM:SS)
        $departureDateTime = $criteria['departure_date'] . "T00:00:00";

        $payload = [
            "OTA_AirLowFareSearchRQ" => [
                "Target" => config('services.sabre.target', 'Test'),
                "POS" => [
                    "Source" => [
                        [
                            "PseudoCityCode" => $this->pcc,
                            "RequestorID" => [
                                "Type" => "1",
                                "ID" => "REQ.ID",
                                "CompanyName" => ["Code" => "TN"]
                            ]
                        ]
                    ]
                ],
                "OriginDestinationInformation" => [
                    [
                        "RPH" => "1",
                        "DepartureDateTime" => $departureDateTime,
                        "OriginLocation" => ["LocationCode" => strtoupper($criteria['origin'])],
                        "DestinationLocation" => ["LocationCode" => strtoupper($criteria['destination'])]
                    ]
                ],
                "TravelerInfoSummary" => [
                    "AirTravelerAvail" => [
                        [
                            "PassengerTypeQuantity" => [
                                ["Code" => "ADT", "Quantity" => (int)$criteria['adults']]
                            ]
                        ]
                    ]
                ],
                "TPA_Extensions" => [
                    "IntelliSellTransaction" => [
                        "RequestType" => ["Name" => "50ITINS"]
                    ]
                ]
            ]
        ];

        $response = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($this->apiUrl . '/v4/shop/flights?split=true', $payload);

        if ($response->failed()) {
            Log::error('Sabre Search Error: ' . $response->body());
            throw new \Exception("Erreur lors de la recherche de vols Sabre.");
        }

        return $response->json();
    }

    /**
     * Création d'une réservation temporaire (Create Passenger Name Record - PNR)
     */
    public function createBooking(array $bookingData): array
    {
        $token = $this->getAccessToken();

        // Logique d'appel à l'API Sabre '/v2/itinerary/booking' (EnhancedAirBook / PassengerDetails)
        // C'est ici qu'on pousse les informations passagers et les vols choisis.

        return ['status' => 'success', 'pnr' => 'HOLD123']; // Simulation
    }

    /**
     * Nettoie et simplifie la réponse brute de Sabre Bargain Finder Max
     * @param array $rawResponse
     * @return array
     */
    public function formatSabreResponse(array $rawResponse): array
    {
        // Vérification de la présence de données valides
        if (!isset($rawResponse['OTA_AirLowFareSearchRS']['PricedItineraries']['PricedItinerary'])) {
            return ['status' => 'success', 'results_count' => 0, 'flights' => []];
        }

        $formattedFlights = [];
        $itineraries = $rawResponse['OTA_AirLowFareSearchRS']['PricedItineraries']['PricedItinerary'];

        foreach ($itineraries as $itinerary) {
            $flightOffer = [];

            // 1. Récupération du prix total brut de Sabre
            $totalFareInfo = $itinerary['AirItineraryPricingInfo'][0]['ItinTotalFare'] ?? null;
            if (!$totalFareInfo) continue;

            $basePrice = $totalFareInfo['BaseFare']['Amount'] ?? 0;
            $taxes = $totalFareInfo['Taxes']['TotalAmount'] ?? 0;
            $currency = $totalFareInfo['TotalFare']['CurrencyCode'] ?? 'XAF';
            $totalBrut = $totalFareInfo['TotalFare']['Amount'] ?? ($basePrice + $taxes);

            // Structure tarifaire initiale
            $flightOffer['price_details'] = [
                'base_price' => (float)$basePrice,
                'taxes' => (float)$taxes,
                'total_sabre' => (float)$totalBrut,
                'currency' => $currency
            ];

            // 2. Extraction des segments de vol (Aller / Retour)
            $originDestinationOptions = $itinerary['AirItinerary']['OriginDestinationOptions']['OriginDestinationOption'] ?? [];
            $flightOffer['itinerary'] = [];

            foreach ($originDestinationOptions as $index => $option) {
                $journey = [
                    'direction' => ($index === 0) ? 'outbound' : 'inbound', // Aller ou Retour
                    'stops_count' => count($option['FlightSegment']) - 1,   // Nombre d'escales
                    'segments' => []
                ];

                foreach ($option['FlightSegment'] as $segment) {
                    $journey['segments'][] = [
                        'flight_number'   => $segment['FlightNumber'] ?? '',
                        'airline_code'    => $segment['OperatingAirline']['Code'] ?? '',
                        'airline_name'    => $segment['OperatingAirline']['CompanyShortName'] ?? '',
                        'departure' => [
                            'airport' => $segment['DepartureAirport']['LocationCode'] ?? '',
                            'time'    => $segment['DepartureDateTime'] ?? ''
                        ],
                        'arrival' => [
                            'airport' => $segment['ArrivalAirport']['LocationCode'] ?? '',
                            'time'    => $segment['ArrivalDateTime'] ?? ''
                        ],
                        'booking_class'   => $segment['ResBookDesigCode'] ?? '', // ex: Y, J, F
                        'duration'        => $segment['ElapsedTime'] ?? 0,       // en minutes
                    ];
                }

                $flightOffer['itinerary'][] = $journey;
            }

            $formattedFlights[] = $flightOffer;
        }

        return [
            'status' => 'success',
            'results_count' => count($formattedFlights),
            'flights' => $formattedFlights
        ];
    }
}

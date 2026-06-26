<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Exception;
use Illuminate\Support\Facades\Log;

class TravelOproService
{
    protected string $baseUrl;
    protected array $authData;

    public function __construct()
    {
        $this->baseUrl = config('travelopro.base_url');
        $this->authData = [
            'user_id' => config('travelopro.user_id'),
            'user_password' => config('travelopro.user_password'),
            'access' => config('travelopro.access'),
            'ip_address' => config('travelopro.ip_address'),
        ];
    }

    /**
     * Recherche les disponibilités de vols auprès du fournisseur
     * @param array $validatedData
     * @param string $clientIp
     * @return array
     */
    public function searchAvailability(array $validatedData): array
    {
        // 1. Génération d'une clé de cache unique basée sur les paramètres de recherche
        // On trie le tableau pour s'assurer que l'ordre des paramètres ne change pas le hash
        $cacheData = $validatedData;
        ksort($cacheData);
        $cacheKey = 'flight_search_' . md5(json_encode($cacheData));

        // Durée de vie du cache : 10 minutes (600 secondes)
        // C'est le juste milieu pour de la dispo de vol sans renvoyer des tarifs périmés
        $cacheDuration = 600;

        return Cache::remember($cacheKey, $cacheDuration, function () use ($validatedData) {

            // 2. Mapping du type de voyage
            $journeyTypeMap = [
                'one_way'    => 'OneWay',
                'round_trip' => 'Return',
                'multi_city' => 'Circle'
            ];
            $journeyType = $journeyTypeMap[$validatedData['trip_type']];

            // 3. Traitement des segments (Multi-destinations vs Aller Simple/Retour)
            $originDestinationInfo = [];

            if ($validatedData['trip_type'] === 'multi_city') {
                foreach ($validatedData['segments'] as $segment) {
                    $originDestinationInfo[] = [
                        'departureDate'           => $segment['departure_date'],
                        'airportOriginCode'       => strtoupper($segment['origin']),
                        'airportDestinationCode' => strtoupper($segment['destination']),
                    ];
                }
            } else {
                $segment = [
                    'departureDate'           => $validatedData['departure_date'],
                    'airportOriginCode'       => strtoupper($validatedData['origin']),
                    'airportDestinationCode' => strtoupper($validatedData['destination']),
                ];

                if ($validatedData['trip_type'] === 'round_trip' && !empty($validatedData['return_date'])) {
                    $segment['returnDate'] = $validatedData['return_date'];
                }

                $originDestinationInfo[] = $segment;
            }

            // 4. Payload final
            $payload = [
                'user_id'               => $this->authData['user_id'],
                'user_password'         => $this->authData['user_password'],
                'access'                => $this->authData['access'],
                'ip_address'            => $this->authData['ip_address'],
                'requiredCurrency'      => !empty($validatedData['currency']) ? strtoupper($validatedData['currency']) : 'XAF',
                'journeyType'           => $journeyType,
                'OriginDestinationInfo' => $originDestinationInfo,
                'class'                 => $validatedData['class'] ?? 'Economy',
                'adults'                => (int)$validatedData['passengers']['adults'],
                'childs'                => (int)$validatedData['passengers']['children'],
                'infants'               => (int)$validatedData['passengers']['infants'],
                'directFlight'          => isset($validatedData['direct_flight']) ? (int)$validatedData['direct_flight'] : 0,
                'multipleBrandedFares'  => true,
            ];

            logger(json_encode($payload));

            try {
                $response = Http::withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                ])->timeout(60)->post("{$this->baseUrl}/api/aeroVE5/availability", $payload);

                if ($response->successful()) {
                    return [
                        'success' => true,
                        'data'    => $response->json()
                    ];
                }

                Log::error("Flight API Error: {$response->status()}", [
                    'body'    => $response->body(),
                    'payload' => $payload
                ]);

                return [
                    'success' => false,
                    'message' => "Le fournisseur de vols a renvoyé une erreur lors du traitement de la demande."
                ];

            } catch (\Exception $e) {
                Log::critical("Flight API Failure: " . $e->getMessage());
                return [
                    'success' => false,
                    'message' => "Impossible de joindre le serveur de recherche de vols."
                ];
            }
        });
    }

    /**
     * Revalide la disponibilité et le tarif d'un itinéraire sélectionné avant la réservation.
     *
     * @param string $sessionId Identifiant unique de la session de recherche.
     * @param string $fareSourceCode Code de l'itinéraire sélectionné.
     * @param string|null $fareSourceCodeInbound Requis uniquement si le vol retour est vendu séparément.
     * @return array
     */
    public function validateFare(string $sessionId, string $fareSourceCode, ?string $fareSourceCodeInbound = null): array
    {
        $payload = [
            'user_id'          => $this->authData['user_id'],
            'user_password'    => $this->authData['user_password'],
            'access'           => $this->authData['access'],
            'ip_address'       => $this->authData['ip_address'],
            'session_id'       => $sessionId,
            'fare_source_code' => $fareSourceCode,
        ];

        if (!empty($fareSourceCodeInbound)) {
            $payload['fare_source_code_inbound'] = $fareSourceCodeInbound;
        }

        try {
            $response = Http::withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(60)->post("{$this->baseUrl}/api/aeroVE5/revalidate", $payload);

            if ($response->successful()) {
                $responseData = $response->json();

                // 🔥 ANALYSE DU RETOUR MÉTIER DU FOURNISSEUR
                // On va chercher en profondeur si le vol est réellement valide
                $isValid = $responseData['AirRevalidateResponse']['AirRevalidateResult']['IsValid'] ?? false;

                if ($isValid === true) {
                    return [
                        'success' => true,
                        'data'    => $responseData
                    ];
                }

                // Cas où HTTP est 200 mais IsValid est false (Tarif expiré, classe fermée, etc.)
                Log::warning("Flight Fare Revalidation: Flight is no longer valid (IsValid: false)", [
                    'payload' => $payload,
                    'body'    => $responseData
                ]);

                return [
                    'success' => false,
                    'message' => "Ce vol n'est plus disponible au tarif sélectionné. Les places ont probablement été vendues. Veuillez relancer une recherche."
                ];
            }

            // Échec de la requête HTTP (ex: 500, 400, etc.)
            Log::error("Flight Fare Revalidation HTTP Error: {$response->status()}", [
                'body'    => $response->body(),
                'payload' => $payload
            ]);

            return [
                'success' => false,
                'message' => "Le fournisseur de vols a rencontré une erreur. Veuillez réessayer."
            ];

        } catch (\Exception $e) {
            Log::critical("Flight Revalidate API Failure: " . $e->getMessage());
            return [
                'success' => false,
                'message' => "Impossible de joindre le serveur de validation des tarifs."
            ];
        }
    }
    /**
     * Récupérer la liste complète des aéroports.
     */
    public function getAirportList(): array
    {
        logger()->info("TravelOpro - Début getAirportList", ['url' => $this->baseUrl . '/api/aeroVE5/airport_list']);

        try {
            $payload = array_merge($this->authData, [
                // Nettoyage de l'IP : si Laravel renvoie ::1 (localhost en IPv6), on force une IP valide
                'ip_address' => '129.0.60.183',
            ]);

            logger()->debug("TravelOpro - Payload envoyé", [
                'user_id' => $payload['user_id'],
                'access' => $payload['access'],
                'ip_address' => $payload['ip_address']
            ]);

            $response = Http::baseUrl($this->baseUrl)
                ->timeout(60) // Augmenté à 60s car les listes d'aéroports mondiaux sont massives
                ->post('/api/aeroVE5/airport_list', $payload);

            logger()->info("TravelOpro - Réponse reçue", [
                'status' => $response->status(),
                'headers' => $response->headers(),
            ]);

            if ($response->failed()) {
                logger()->error("TravelOpro - L'API a renvoyé un échec", [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                throw new Exception("Erreur API TravelOpro (Airport List): " . $response->body());
            }

            $data = $response->json();

            logger()->info("TravelOpro - Parsing JSON réussi", [
                'total_items' => is_array($data) ? count($data) : 'Non dénombrable',
                'preview' => is_array($data) ? array_slice($data, 0, 2) : substr($response->body(), 0, 200)
            ]);

            return $data;

        } catch (Exception $e) {
            logger()->error("Échec de la récupération de la liste des aéroports", [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
    }

    /**
     * Récupère les services supplémentaires (bagages, repas, etc.)
     * @param string $sessionId
     * @param string $fareSourceCode
     * @return array
     * @throws Exception
     */
    public function fetchExtraServices(string $sessionId, string $fareSourceCode): array
    {
        try {
            $response = Http::withHeaders([
                'Accept'        => 'application/json',
            ])->post("{$this->baseUrl}/api/aeroVE5/extra_services", [
                'session_id'       => $sessionId,
                'fare_source_code' => $fareSourceCode,
            ]);

            logger($response);
            if ($response->failed()) {
                Log::error('Erreur API aeroVE5 Extra Services', [
                    'status' => $response->status(),
                    'body'   => $response->body()
                ]);
                throw new Exception("L'API partenaire a retourné une erreur.");
            }

            return $response->json();

        } catch (Exception $e) {
            Log::critical('Échec de communication avec aeroVE5', ['message' => $e->getMessage()]);
            throw new Exception("Impossible de récupérer les options du vol pour le moment.");
        }
    }

    /**
     * Récupère les règles tarifaires auprès de TravelNext.
     * @param string $sessionId
     * @param string $fareSourceCode
     * @param string|null $fareSourceCodeInbound
     * @return array
     * @throws Exception
     */
    public function getFareRules(string $sessionId, string $fareSourceCode, ?string $fareSourceCodeInbound = null): array
    {
        try {
            // Construction du corps de la requête selon la doc TravelNext
            $payload = [
                'session_id'       => $sessionId,
                'fare_source_code' => $fareSourceCode,
            ];

            // On ajoute le retour uniquement s'il existe (Utile pour les Round-Trip)
            if (!empty($fareSourceCodeInbound)) {
                $payload['fare_source_code_inbound'] = $fareSourceCodeInbound;
            }

            // Exécution de la requête POST vers le endpoint officiel
            $response = Http::withHeaders([
                'Accept' => 'application/json',
            ])->post($this->baseUrl . '/api/aeroVE5/fare_rules', $payload);

            if ($response->failed()) {
                Log::error('Erreur TravelNext API - Fare Rules:', [
                    'status' => $response->status(),
                    'body'   => $response->body()
                ]);
                throw new Exception('Impossible de récupérer les règles tarifaires auprès du partenaire.');
            }

            return $response->json();

        } catch (Exception $e) {
            Log::error('Exception levée lors de l\'appel TravelNext Fare Rules: ' . $e->getMessage());
            throw $e;
        }
    }
    /**
     * Crée une réservation (génération du PNR) auprès de TravelOpro.
     * Cette méthode valide d'abord le tarif en temps réel via l'API revalidate.
     *
     * @param array $data Données reçues du contrôleur (contenant flightBookingInfo et paxInfo)
     * @return array
     */
    /**
     * Crée une réservation (génération du PNR) auprès de TravelOpro.
     * Cette méthode valide d'abord le tarif en temps réel via l'API revalidate.
     *
     * @param array $data Données reçues du contrôleur (contenant flightBookingInfo et paxInfo)
     * @return array
     */
    public function createBooking(array $data): array
    {
        // 1. Extraction et validation des blocs obligatoires
        $flightInfo = $data['flightBookingInfo'] ?? [];
        $sessionId  = $flightInfo['flight_session_id'] ?? null;
        $fareCode   = $flightInfo['fare_source_code'] ?? null;

        if (empty($sessionId) || empty($fareCode)) {
            return [
                'success' => false,
                'message' => "Impossible de traiter la réservation : données de session ou code tarifaire manquants."
            ];
        }

        // 2. ÉTAPE DE SÉCURITÉ : Revalidation du tarif en temps réel (Décommenter en production)
        logger()->info("TravelOpro - Lancement de la revalidation avant réservation", ['session_id' => $sessionId]);
        /*
        $revalidation = $this->validateFare($sessionId, $fareCode, $flightInfo['fare_source_code_inbound'] ?? null);
        if (!$revalidation['success']) {
            return [
                'success' => false,
                'message' => $revalidation['message'] ?? "Le tarif de ce vol a expiré ou n'est plus disponible. Veuillez recommencer."
            ];
        }
        */

        // 3. Construction du payload final STRICTEMENT aligné sur la structure aeroVE5
        // Fusion des accès auth à la racine + respect des sous-tableaux requis par le GDS
        $payload = [
            'user_id'       => $this->authData['user_id'] ?? null,
            'user_password' => $this->authData['user_password'] ?? null,
            'access'        => $this->authData['access'] ?? null,
            'ip_address'    => $this->authData['ip_address'] ?? null,

            // Bloc 1: flightBookingInfo (Nomenclature exacte de votre spec)
            'flightBookingInfo' => [
                'flight_session_id'        => $sessionId,
                'fare_source_code'         => $fareCode,
                'IsPassportMandatory'      => filter_var($flightInfo['IsPassportMandatory'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'areaCode'                 => $flightInfo['areaCode'] ?? '010',
                'countryCode'              => $flightInfo['countryCode'] ?? '33',
                'fareType'                 => $flightInfo['fareType'] ?? 'Private',
                'fare_source_code_inbound' => $flightInfo['fare_source_code_inbound'] ?? null,
            ],

            // Bloc 2: paxInfo
            'paxInfo' => [
                'clientRef'     => $data['paxInfo']['clientRef'] ?? uniqid('REF_'),
                'postCode'      => $data['paxInfo']['postCode'] ?? '0000',
                'customerEmail' => $data['paxInfo']['customerEmail'] ?? null,
                'customerPhone' => $data['paxInfo']['customerPhone'] ?? null,
                'bookingNote'   => $data['paxInfo']['bookingNote'] ?? '',
                'paxDetails'    => $data['paxInfo']['paxDetails'] ?? [], // Reçoit les tableaux parallèles du Job
            ]
        ];

        logger(json_encode($payload));
        try {
            logger()->info("TravelOpro - Envoi de la requête de booking au GDS");


            // 4. 🔥 FIX : Envoi de $payload (et non de $data) avec un Timeout étendu (90 secondes)
            $response = Http::withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])->timeout(90)->post("{$this->baseUrl}/api/aeroVE5/booking", $payload);

            // 5. Analyse de la réponse basée sur vos paramètres officiels
            if ($response->successful()) {
                $responseData = $response->json();

                // 1. Extraction correcte en respectant la hiérarchie BookFlightResponse -> BookFlightResult
                $bookResult = $responseData['BookFlightResponse']['BookFlightResult'] ?? [];

                // 2. Lecture du booléen de succès et du statut (attention à la majuscule initiale)
                $isSuccess = filter_var($bookResult['Success'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $status    = strtoupper($bookResult['Status'] ?? '');

                // 3. Validation de la confirmation
                if ($isSuccess && ($status === 'CONFIRMED' || $status === 'PENDING')) {
                    return [
                        'success'           => true,
                        'booking_reference' => $bookResult['UniqueID'] ?? null, // L'identifiant unique généré (PNR)
                        'status'            => $status,
                        'ticket_time_limit' => $bookResult['TktTimeLimit'] ?? null, // Date limite d'émission
                        'data'              => $responseData
                    ];
                }

                // 4. Traitement des erreurs
                $errorMessage = "La réservation a échoué auprès du fournisseur.";

                // Extraction de l'erreur selon le schéma du GDS
                if (!empty($bookResult['Errors'])) {
                    // Le GDS peut renvoyer un objet, un tableau, ou parfois juste une chaîne de caractères
                    if (is_array($bookResult['Errors']) && isset($bookResult['Errors']['ErrorMessage'])) {
                        $errorMessage = $bookResult['Errors']['ErrorMessage'];
                    } elseif (is_string($bookResult['Errors']) && trim($bookResult['Errors']) !== '') {
                        $errorMessage = $bookResult['Errors'];
                    }
                }

                Log::warning("TravelOpro - Réservation refusée par le GDS", [
                    'response' => $responseData
                ]);

                return [
                    'success' => false,
                    'message' => $errorMessage
                ];
            }

            // Erreur HTTP (500, 503, 400, etc.)
            Log::error("TravelOpro - Erreur HTTP lors du Booking: {$response->status()}", [
                'body' => $response->body()
            ]);

            return [
                'success' => false,
                'message' => "Le système de réservation est indisponible (Erreur HTTP {$response->status()})."
            ];

        } catch (\Exception $e) {
            Log::critical("TravelOpro - Échec critique lors du Booking : " . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => "Une erreur interne est survenue lors du traitement de votre réservation."
            ];
        }
    }
    /**
     * Formate et mappe les résultats bruts de TravelOpro vers la structure standard attendue par le Front.
     * Adapté précisément à la structure réelle du GDS (2026).
     *
     * @param array $rawResponse
     * @return array
     */
    public function formatFlightOffers(array $rawResponse): array
    {
        // 1. Extraction sécurisée de la racine des itinéraires
        $searchResponse = $rawResponse['data']['AirSearchResponse'] ?? [];
        $sessionId = $searchResponse['session_id'] ?? null;
        $fareItineraries = $searchResponse['AirSearchResult']['FareItineraries'] ?? [];

        $formattedOffers = [];

        foreach ($fareItineraries as $index => $itineraryWrapper) {
            $fareItinerary = $itineraryWrapper['FareItinerary'] ?? [];
            if (empty($fareItinerary)) {
                continue;
            }

            // 2. Extraction du premier BrandedFare (qui contient les prix, taxes et bagages)
            $brandedFare = $fareItinerary['BrandedFares'][0]['AirItineraryFareInfo'] ?? [];
            if (empty($brandedFare)) {
                continue;
            }

            // 3. Extraction et typage des prix (ItinTotalFares)
            $itinTotalFares = $brandedFare['ItinTotalFares'] ?? [];
            $basePrice = (float)($itinTotalFares['BaseFare']['Amount'] ?? 0);
            $taxes = (float)($itinTotalFares['TotalTax']['Amount'] ?? 0);
            $finalPrice = (float)($itinTotalFares['TotalFare']['Amount'] ?? 0);
            $currency = $itinTotalFares['TotalFare']['CurrencyCode'] ?? 'XAF';

            // 4. Construction de l'itinéraire (Aller / Retour)
            $itinerary = [];
            $originDestinationOptions = $fareItinerary['OriginDestinationOptions'] ?? [];

            foreach ($originDestinationOptions as $key => $optionWrapper) {
                // Déduction de la direction basée sur la structure indexée
                $direction = ($key === 0) ? 'outbound' : 'inbound';

                // Travelopro imbrique les segments dans un tableau 'OriginDestinationOption'
                $rawSegments = $optionWrapper['OriginDestinationOption'] ?? [];

                $itinerary[] = $this->mapJourney($rawSegments, $direction);
            }

            // 5. Extraction de la franchise bagages (présente dans FareBreakdown)
            $fareBreakdown = $brandedFare['FareBreakdown'][0] ?? [];
            $checkedBaggage = $fareBreakdown['Baggage'] ?? ['1 Pieces'];
            $cabinBaggage = $fareBreakdown['CabinBaggage'] ?? ['1 Pieces'];

            // 6. Assemblage final de la FlightOffer
            $formattedOffers[] = [
                'id' => $brandedFare['ResultIndex'] ?? 'offer_' . $index,
                'is_refundable' => ($brandedFare['IsRefundable'] ?? 'No') === 'Yes',
                'passport_mandatory' => filter_var($fareItinerary['IsPassportMandatory'] ?? true, FILTER_VALIDATE_BOOLEAN),

                'price_details' => [
                    'base_price' => $basePrice,
                    'taxes' => $taxes,
                    'agency_fees' => 0.0, // À ajuster selon votre marge
                    'final_price_to_pay' => $finalPrice,
                    'currency' => $currency,
                ],

                'itinerary' => $itinerary,

                'baggage_allowance' => [
                    // On prend le premier élément du tableau de chaînes (ex: "1 Pieces")
                    'checked' => $checkedBaggage[0] ?? '1 Pieces',
                    'cabin' => $cabinBaggage[0] ?? '1 Pieces',
                ],

                // Métadonnées techniques indispensables pour l'étape du Booking
                'travelport' => [
                    'session_id' => $sessionId,
                    'fare_source_code' => $brandedFare['FareSourceCode'] ?? null,
                    'result_index' => $brandedFare['ResultIndex'] ?? null,
                    'direction_ind' => $fareItinerary['DirectionInd'] ?? 'Return',
                    'validating_carrier' => $fareItinerary['ValidatingAirlineCode'] ?? null,
                ],
            ];
        }

        return [
            'session_id' => $sessionId,
            'offers' => $formattedOffers
        ];
    }

    /**
     * Mappe un groupe de segments de vol vers la structure attendue par le Front.
     *
     * @param array $rawSegments
     * @param string $direction
     * @return array
     */
    private function mapJourney(array $rawSegments, string $direction): array
    {
        $segments = [];

        foreach ($rawSegments as $rawSegmentWrapper) {
            $segment = $rawSegmentWrapper['FlightSegment'] ?? [];
            if (empty($segment)) {
                continue;
            }

            $segments[] = [
                'flight_number' => $segment['FlightNumber'] ?? null,
                'airline_code' => $segment['MarketingAirlineCode'] ?? null,
                'airline_name' => $segment['MarketingAirlineName'] ?? null,
                'operating_carrier' => $segment['OperatingAirline']['Code'] ?? null,

                'departure' => [
                    'airport' => $segment['DepartureAirportLocationCode'] ?? null,
                    'time' => $segment['DepartureDateTime'] ?? null,
                ],

                'arrival' => [
                    'airport' => $segment['ArrivalAirportLocationCode'] ?? null,
                    'time' => $segment['ArrivalDateTime'] ?? null,
                ],

                'booking_class' => null, // Sera injecté si nécessaire via les FareClassDetails
                'cabin_class' => 'Economy', // Valeur par défaut standard
                'duration' => $segment['JourneyDuration'] ?? null,
            ];
        }

        return [
            'direction' => $direction,
            'stops_count' => count($segments) > 0 ? count($segments) - 1 : 0,
            'segments' => $segments,
        ];
    }
}

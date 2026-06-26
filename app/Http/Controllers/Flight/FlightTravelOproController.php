<?php


namespace App\Http\Controllers\Flight;

use App\Events\UserAutoRegistered;
use App\Http\Controllers\Controller;
use App\Models\FlightBooking;
use App\Models\FlightBookingTrip;
use App\Models\FlightPassenger;
use App\Models\User;
use App\Services\TravelOproService;
use App\Services\Travelport\PaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class FlightTravelOproController extends Controller
{
    protected TravelOproService $travelOproService;
    protected $paymentService;
    // Injection de dépendance du service
    public function __construct(TravelOproService $travelOproService,PaymentService $paymentService)
    {
        $this->travelOproService = $travelOproService;
        $this->paymentService=$paymentService;
    }
    /**
     * Rechercher des offres de vols (Search / AirSearch)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function search(Request $request): JsonResponse
    {
        // 1. Validation stricte selon ta structure (Aller-simple, Retour, Multi-city)
        $validatedData = $request->validate([
            'trip_type'                 => 'required|string|in:one_way,round_trip,multi_city',
            'return_date'               => 'nullable|date|after_or_equal:departure_date',
            'passengers.adults'         => 'required|integer|min:1',
            'passengers.children'       => 'required|integer|min:0',
            'passengers.infants'        => 'required|integer|min:0',
            'segments'                  => 'required|array|min:1',
            'segments.*.origin'         => 'required|string|size:3',
            'segments.*.destination'    => 'required|string|size:3',
            'segments.*.departure_date' => 'required|date|after_or_equal:today',
            'origin'                    => 'required|string|size:3',
            'destination'               => 'required|string|size:3',
            'departure_date'            => 'required|date|after_or_equal:today',
            'currency'                  => 'nullable|string|size:3',
            'direct_flight'             => 'nullable|integer|in:0,1',
            'class'                     => 'nullable|string|in:Economy,Business,First,PremiumEconomy',
        ]);

        try {
            // 2. Envoi des données validées à ton service d'intégration TravelOpro
            $rawResponse = $this->travelOproService->searchAvailability($validatedData);

            // Validation du retour de l'API externe
            if (empty($rawResponse) || isset($rawResponse['Response']['Error'])) {
                $errorMessage = $rawResponse['Response']['Error']['ErrorMessage'] ?? 'Aucun vol disponible pour cet itinéraire.';
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage
                ], 422);
            }

          //  logger($rawResponse);
            // 3. Formatage unifié de la réponse brute pour ton Front-end
            $formattedOffers = $this->travelOproService->formatFlightOffers($rawResponse);

            return response()->json([
                'success' => true,
                'count'   => count($formattedOffers),
                'data'    => $formattedOffers
            ]);

        } catch (Exception $e) {
            // Log de l'erreur avec les inputs pour faciliter le débugging en prod
            Log::error('TravelOpro Search Pipeline Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'input' => $validatedData
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Une erreur est survenue lors de la recherche de vols. Veuillez réessayer plus tard.'
            ], 500);
        }
    }

    public function revalidate(Request $request)
    {
        $validated = $request->validate([
            'session_id'                => ['required', 'string'],
            'fare_source_code'          => ['required', 'string'],
            'fare_source_code_inbound'  => ['nullable', 'string'],
        ]);

        $result = $this->travelOproService->validateFare(
            $validated['session_id'],
            $validated['fare_source_code'],
            $validated['fare_source_code_inbound'] ?? null
        );

        if (!$result['success']) {
            return response()->json($result, 422); // Déclenché si le prix a changé ou expiré
        }

        return response()->json($result);
    }

    /**
     * Récupère les services supplémentaires (bagages, repas, options) pour un vol.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getExtraServices(Request $request): JsonResponse
    {
        try {
            // Extraction des données validées
            $validated = $request->validate([
                'session_id'       => ['required', 'string'],
                'fare_source_code' => ['required', 'string'],
            ]);

            // Appel du service pour contacter l'API externe aeroVE5
            $rawExtraServices = $this->travelOproService->fetchExtraServices(
                $validated['session_id'],
                $validated['fare_source_code']
            );

            // Mapping vers la structure normalisée demandée
            $mappedData = $this->mapExtraServicesResponse($rawExtraServices);

            return response()->json([
                'success' => true,
                'data'    => $mappedData
            ]);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Obtenir les règles tarifaires d'un vol via TravelNext.
     */
    public function fareRules(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'session_id'       => ['required', 'string'],
                'fare_source_code' => ['required', 'string'],
                'fare_source_code_inbound' => ['nullable', 'string'],
            ]);

            // Transmission des paramètres, y compris celui du retour s'il est présent
            $data = $this->travelOproService->getFareRules(
                $validated['session_id'],
                $validated['fare_source_code'],
                $validated['fare_source_code_inbound'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Règles tarifaires récupérées avec succès.',
                'data'    => $data
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Une erreur est survenue lors de la récupération des conditions tarifaires.',
            ], 500);
        }
    }

    /**
     * Map la réponse brute de l'API externe vers le format cible attendu par le contrat.
     * @param array $rawResponse
     * @return array
     */
    private function mapExtraServicesResponse(array $rawResponse): array
    {
        // On descend jusqu'au niveau utile s'il est enveloppé dans la réponse type SOAP/API
        $source = $rawResponse['ExtraServicesResponse']['ExtraServicesResult']['ExtraServicesData']
            ?? $rawResponse['data']
            ?? $rawResponse;

        return [
            'ExtraServicesData' => [

                // 1. Mapping du Bagage Dynamique
                'DynamicBaggage' => array_map(function ($baggageSector) {
                    // Gestion du tableau de tableaux si l'API renvoie des services doublement imbriqués
                    $rawServices = $baggageSector['services'] ?? $baggageSector['Services'] ?? [];
                    if (isset($rawServices[0]) && is_array($rawServices[0])) {
                        $rawServices = $rawServices[0];
                    }

                    return [
                        'Behavior'      => $baggageSector['behavior'] ?? $baggageSector['Behavior'] ?? 'PER_PAX_OUTBOUND',
                        'IsMultiSelect' => (bool)($baggageSector['is_multi_select'] ?? $baggageSector['IsMultiSelect'] ?? true),
                        'Services'      => array_map(function ($service) {
                            return [
                                'ServiceId'       => $service['service_id'] ?? $service['ServiceId'] ?? '',
                                'CheckInType'     => $service['check_in_type'] ?? $service['CheckInType'] ?? 'AIRPORT',
                                'Description'     => $service['description'] ?? $service['Description'] ?? '',
                                'FareDescription' => $service['fare_description'] ?? $service['FareDescription'] ?? '',
                                'IsMandatory'     => (bool)($service['is_mandatory'] ?? $service['IsMandatory'] ?? false),
                                'MinimumQuantity' => (int)($service['minimum_quantity'] ?? $service['MinimumQuantity'] ?? 0),
                                'MaximumQuantity' => (int)($service['maximum_quantity'] ?? $service['MaximumQuantity'] ?? 1),
                                'ServiceCost'     => [
                                    'Amount'        => (string)($service['service_cost']['amount'] ?? $service['ServiceCost']['Amount'] ?? '0.00'),
                                    'CurrencyCode'  => $service['service_cost']['currency_code'] ?? $service['ServiceCost']['CurrencyCode'] ?? 'USD',
                                    'DecimalPlaces' => (string)($service['service_cost']['decimal_places'] ?? $service['ServiceCost']['DecimalPlaces'] ?? '2'),
                                ]
                            ];
                        }, $rawServices)
                    ];
                }, $source['dynamic_baggage'] ?? $source['DynamicBaggage'] ?? []),

                // 2. Mapping des Repas Dynamiques
                'DynamicMeal' => array_map(function ($mealSector) {
                    // Correction de la double imbrication du tableau 'Services' [[ ... ]]
                    $rawServices = $mealSector['services'] ?? $mealSector['Services'] ?? [];
                    if (isset($rawServices[0]) && is_array($rawServices[0])) {
                        $rawServices = $rawServices[0];
                    }

                    return [
                        'Behavior'      => $mealSector['behavior'] ?? $mealSector['Behavior'] ?? 'PER_PAX_OUTBOUND',
                        'IsMultiSelect' => (bool)($mealSector['is_multi_select'] ?? $mealSector['IsMultiSelect'] ?? true),
                        'Services'      => array_map(function ($service) {
                            return [
                                'ServiceId'       => $service['service_id'] ?? $service['ServiceId'] ?? '',
                                'CheckInType'     => $service['check_in_type'] ?? $service['CheckInType'] ?? 'AIRPORT',
                                'Description'     => $service['description'] ?? $service['Description'] ?? '',
                                'FareDescription' => $service['fare_description'] ?? $service['FareDescription'] ?? '',
                                'IsMandatory'     => (bool)($service['is_mandatory'] ?? $service['IsMandatory'] ?? false),
                                'MinimumQuantity' => (int)($service['minimum_quantity'] ?? $service['MinimumQuantity'] ?? 0),
                                'MaximumQuantity' => (int)($service['maximum_quantity'] ?? $service['MaximumQuantity'] ?? 1),
                                'ServiceCost'     => [
                                    'Amount'        => (string)($service['service_cost']['amount'] ?? $service['ServiceCost']['Amount'] ?? '0.00'),
                                    'CurrencyCode'  => $service['service_cost']['currency_code'] ?? $service['ServiceCost']['CurrencyCode'] ?? 'USD',
                                    'DecimalPlaces' => (string)($service['service_cost']['decimal_places'] ?? $service['ServiceCost']['DecimalPlaces'] ?? '2'),
                                ]
                            ];
                        }, $rawServices)
                    ];
                }, $source['dynamic_meal'] ?? $source['DynamicMeal'] ?? []),

                // 3. Mapping de la Grille des Sièges
                'DynamicSeat' => array_map(function ($seatSector) {
                    // Correction de la double imbrication racine de DynamicSeat [[ ... ]]
                    if (isset($seatSector[0]) && is_array($seatSector[0])) {
                        $seatSector = $seatSector[0];
                    }

                    return [
                        'DeckSeats' => array_map(function ($deck) {
                            return [
                                'DeckNo'   => (int)($deck['deck_no'] ?? $deck['DeckNo'] ?? 1),
                                'RowSeats' => array_map(function ($row) {
                                    return [
                                        'RowNo' => (string)($row['row_no'] ?? $row['RowNo'] ?? ''),
                                        'Seats' => array_map(function ($seat) {
                                            return [
                                                'ServiceId'                         => $seat['service_id'] ?? $seat['ServiceId'] ?? '',
                                                'AirlineCode'                       => $seat['airline_code'] ?? $seat['AirlineCode'] ?? '',
                                                'FlightNumber'                      => $seat['flight_number'] ?? $seat['FlightNumber'] ?? '',
                                                'EquipmentCode'                     => $seat['equipment_code'] ?? $seat['EquipmentCode'] ?? '',
                                                'DepartureAirportLocationCode'      => $seat['departure_airport_location_code'] ?? $seat['DepartureAirportLocationCode'] ?? '',
                                                'ArrivalAirportLocationCode'        => $seat['arrival_airport_location_code'] ?? $seat['ArrivalAirportLocationCode'] ?? '',
                                                'DeckNo'                            => (string)($seat['deck_no'] ?? $seat['DeckNo'] ?? '1'),
                                                'RowNo'                             => (string)($seat['row_no'] ?? $seat['RowNo'] ?? ''),
                                                'SeatNo'                            => (string)($seat['seat_no'] ?? $seat['SeatNo'] ?? ''),
                                                'SeatCode'                          => $seat['seat_code'] ?? $seat['SeatCode'] ?? '',

                                                // Correction de la clé "AvailablityType" (sans 'i' dans ton JSON)
                                                'AvailabilityType' => [
                                                    'Code' => (string)($seat['availability_type']['code'] ?? $seat['AvailabilityType']['Code'] ?? $seat['AvailablityType']['Code'] ?? '5'),
                                                    'Text' => $seat['availability_type']['text'] ?? $seat['AvailabilityType']['Text'] ?? $seat['AvailablityType']['Text'] ?? 'Not available',
                                                ],
                                                'Description' => [
                                                    'Code' => (string)($seat['description']['code'] ?? $seat['Description']['Code'] ?? '3'),
                                                    'Text' => $seat['description']['text'] ?? $seat['Description']['Text'] ?? '',
                                                ],
                                                'Compartment' => [
                                                    'Code' => (string)($seat['compartment']['code'] ?? $seat['Compartment']['Code'] ?? '1'),
                                                    'Text' => $seat['compartment']['text'] ?? $seat['Compartment']['Text'] ?? '',
                                                ],
                                                'SeatType' => [
                                                    'Code' => (string)($seat['seat_type']['code'] ?? $seat['SeatType']['Code'] ?? '1'),
                                                    'Text' => $seat['seat_type']['text'] ?? $seat['SeatType']['Text'] ?? '',
                                                ],
                                                'SeatWayType' => [
                                                    'Code' => (string)($seat['seat_way_type']['code'] ?? $seat['SeatWayType']['Code'] ?? '1'),
                                                    'Text' => $seat['seat_way_type']['text'] ?? $seat['SeatWayType']['Text'] ?? 'Segment',
                                                ],
                                                'Fare' => [
                                                    'Amount'        => (string)($seat['fare']['amount'] ?? $seat['Fare']['Amount'] ?? '0.00'),
                                                    'CurrencyCode'  => $seat['fare']['currency_code'] ?? $seat['Fare']['CurrencyCode'] ?? 'USD',
                                                    'DecimalPlaces' => (string)($seat['fare']['decimal_places'] ?? $seat['Fare']['DecimalPlaces'] ?? '2'),
                                                ]
                                            ];
                                        }, $row['seats'] ?? $row['Seats'] ?? [])
                                    ];
                                }, $deck['row_seats'] ?? $deck['RowSeats'] ?? [])
                            ];
                        }, $seatSector['deck_seats'] ?? $seatSector['DeckSeats'] ?? [])
                    ];
                }, $source['dynamic_seat'] ?? $source['DynamicSeat'] ?? [])
            ]
        ];
    }
    /**
     * Récupère la liste complète des aéroports (Utile pour alimenter un cache ou un autocomplete)
     */
    public function airports(): JsonResponse
    {
        try {
            $airports = $this->travelOproService->getAirportList();

            return response()->json([
                'success' => true,
                'count'   => is_array($airports) ? count($airports) : 0,
                'data'    => $airports
            ], 200);

        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Impossible de récupérer la liste des aéroports.",
                'error'   => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function verifyAndPay(Request $request)
    {
        // 1. VALIDATION STRICTE ET INTERNATIONALE DU PAYLOAD
        $rules = [
            'session_identifier'                                => 'required|string',
            'booking_type'                                      => 'required|string|in:now,hold',

            // Bloc Selected Flight - Général
            'selected_flight'                                   => 'required|array',
            'selected_flight.id'                                => 'required|string',

            // Bloc Selected Flight - Info GDS (Travelport / TravelOpro)
            'selected_flight.travelport'                        => 'required|array',
            'selected_flight.travelport.transaction_id'         => 'required|string',
            'selected_flight.travelport.offering_id'            => 'required|string',
            'selected_flight.travelport.gds_authority_value'    => 'required|string',
            //'selected_flight.travelport.catalog_offerings_identifier' => 'required|string',

            // Tableaux techniques du GDS
            'selected_flight.travelport.available_brands'       => 'sometimes|array',
            'selected_flight.travelport.products'               => 'sometimes|array',
            'selected_flight.travelport.flight_refs'            => 'sometimes|array',
            'selected_flight.travelport.product_brand_offerings' => 'sometimes|array',
            'selected_flight.travelport.product_brand_offerings.*.brand_ref' => 'required_with:selected_flight.travelport.product_brand_offerings|string',
            'selected_flight.travelport.product_brand_offerings.*.product_refs' => 'required_with:selected_flight.travelport.product_brand_offerings|array',
            'selected_flight.travelport.raw_offering'           => 'nullable|array',

            // Bloc Selected Flight - Tarification
            'selected_flight.price_details'                     => 'required|array',
            'selected_flight.price_details.base_price'          => 'required|numeric',
            'selected_flight.price_details.taxes'               => 'required|numeric',
            'selected_flight.price_details.agency_fees'         => 'sometimes|numeric',
            'selected_flight.price_details.final_price_to_pay'  => 'required|numeric',
            'selected_flight.price_details.currency'            => 'required|string|max:3',

            // Bloc Selected Flight - Structure Itinéraire & Segments
            'selected_flight.itinerary'                         => 'required|array|min:1',
            'selected_flight.itinerary.*.direction'             => 'required|string|in:outbound,inbound',
         //   'selected_flight.itinerary.*.offering_id'           => 'required|string',
            'selected_flight.itinerary.*.brand_value'           => 'nullable|string',
            'selected_flight.itinerary.*.product_ref'           => 'sometimes|string',
            'selected_flight.itinerary.*.travelport'            => 'sometimes|array',
            'selected_flight.itinerary.*.stops_count'           => 'required|integer',

            // Validation des segments imbriqués
            'selected_flight.itinerary.*.segments'              => 'required|array|min:1',
            'selected_flight.itinerary.*.segments.*.flight_number' => 'required|string',
            'selected_flight.itinerary.*.segments.*.airline_code'  => 'required|string|max:3',
            'selected_flight.itinerary.*.segments.*.airline_name'  => 'required|string',
            'selected_flight.itinerary.*.segments.*.booking_class' => 'nullable|string|max:2',
            'selected_flight.itinerary.*.segments.*.duration'      => 'nullable|string',
            'selected_flight.itinerary.*.segments.*.departure'     => 'required|array',
            'selected_flight.itinerary.*.segments.*.departure.airport' => 'required|string|max:5',
            'selected_flight.itinerary.*.segments.*.departure.time'    => 'required|date_format:Y-m-d\TH:i:s',
            'selected_flight.itinerary.*.segments.*.arrival'       => 'required|array',
            'selected_flight.itinerary.*.segments.*.arrival.airport'   => 'required|string|max:5',
            'selected_flight.itinerary.*.segments.*.arrival.time'      => 'required|date_format:Y-m-d\TH:i:s',

            // Bloc Bagages
            'selected_flight.baggage_allowance'                 => 'sometimes|array',
            'selected_flight.baggage_allowance.checked'         => 'nullable|string',
            'selected_flight.baggage_allowance.cabin'           => 'nullable|string',

            // Options et Paiement
            'payment_method'                                    => 'required|string|in:momo,card,wave,om',
            'phone_number'                                      => 'nullable|string|min:9|max:15',

            // Contacts & Voyageurs
            'contact_info'                                      => 'required|array',
            'contact_info.email'                                => 'required|email',
            'contact_info.phone'                                => 'required|string',

            'passengers'                                        => 'required|array|min:1',
            'passengers.*.civility'                             => 'required|string|in:M.,Mme,MR,MRS,MS',
            'passengers.*.first_name'                           => 'required|string|max:100',
            'passengers.*.last_name'                            => 'required|string|max:100',
            'passengers.*.birth_date'                           => 'required|date|before:today',
            'passengers.*.passport_number'                      => 'required|string|max:50',
            'passengers.*.passenger_type'                       => 'sometimes|string|max:10',
            'passengers.*.passport_expiry'                      => 'sometimes|nullable|date|after:today',
        ];

        if (in_array($request->input('payment_method'), ['momo', 'om', 'wave'])) {
            $rules['phone_number'] = 'required|string';
        }

        $validatedData = $request->validate($rules);

        try {
            $sessionIdentifier = $validatedData['session_identifier'];
            $selectedFlight    = $validatedData['selected_flight'];
            $bookingType       = $validatedData['booking_type'];
            $paymentMethod     = $validatedData['payment_method'];

            $currencyCode      = $selectedFlight['price_details']['currency'];
            $totalFlightPrice  = (float) $selectedFlight['price_details']['final_price_to_pay'];
            $phoneNumber       = $validatedData['phone_number'] ?? null;

            // Configuration des frais de blocage (Hold)
            $holdFee = 5000;
            // Si c'est un 'hold', on ne débite que 5000 XAF maintenant. Si c'est 'now', la totalité.
            $amountToDebit = ($bookingType === 'hold') ? (float) $holdFee : $totalFlightPrice;

            // ----------------------------------------------------------------
            // 2. ENREGISTREMENT ET GESTION DU COMPTE (Transaction SQL)
            // ----------------------------------------------------------------
            $booking = DB::transaction(function () use ($validatedData, $selectedFlight, $totalFlightPrice, $sessionIdentifier, $paymentMethod, $bookingType, $currencyCode) {

                $contactEmail = $validatedData['contact_info']['email'];
                $contactPhone = $validatedData['contact_info']['phone'];
                $userId = auth()->id();

                // 🟢 CRÉATION DU COMPTE AUTO SI NON AUTHENTIFIÉ
                if (!$userId) {
                    $user = User::where('email', $contactEmail)->first();

                    if (!$user) {
                        $primaryPassenger = $validatedData['passengers'][0] ?? null;
                        $firstName = $primaryPassenger ? $primaryPassenger['first_name'] : 'Client';
                        $lastName = $primaryPassenger ? $primaryPassenger['last_name'] : 'Voyage';

                        $temporaryPassword = Str::random(10);

                        $user = User::create([
                            'name'     => $firstName . ' ' . $lastName,
                            'email'    => $contactEmail,
                            'phone'    => $contactPhone,
                            'password' => Hash::make($temporaryPassword),
                        ]);

                        event(new UserAutoRegistered($user, $temporaryPassword));
                    }

                    $userId = $user->id;
                }

                // 🟢 INSERTION DE LA RÉSERVATION PRINCIPALE (flight_bookings)
                // Note : 'hold_fee_paid' a été retiré car absent de votre fichier de migration.
                $flightBooking = FlightBooking::create([
                    'user_id'            => $userId,
                    'session_identifier' => $sessionIdentifier,
                    'pnr'                => null, // Le PNR sera généré par le GDS *après* confirmation du paiement
                    'booking_type'       => $bookingType,
                    'booking_status'     => 'pending_payment',
                    'total_amount'       => $totalFlightPrice,
                    'amount_paid'        => 0.00, // Mis à jour lors du webhook de paiement réussi
                    'raw_flight_data'    => $selectedFlight, // Stocké en format JSON
                    'currency'           => $currencyCode,
                    'payment_method'     => $paymentMethod,
                    'payment_status'     => 'unpaid',
                    'contact_email'      => $contactEmail,
                    'contact_phone'      => $contactPhone,
                ]);

                // 🟢 INSERTION DES SEGMENTS DE VOYAGE (flight_booking_trips)
                $itineraries = $selectedFlight['itinerary'] ?? [];
                $sortOrder = 0;

                foreach ($itineraries as $journey) {
                    $offeringId = $journey['offering_id'] ?? $selectedFlight['travelport']['offering_id'];
                    $brandValue = $journey['brand_value'] ?? 'Standard';

                    foreach ($journey['segments'] ?? [] as $segment) {
                        $flightBooking->trips()->create([
                            'sort_order'          => $sortOrder++,
                            'offering_id'         => $offeringId,
                            'brand_value'         => $brandValue,
                            'gds_authority_value' => $selectedFlight['travelport']['gds_authority_value'] ?? 'TravelOpro',
                            'origin'              => strtoupper($segment['departure']['airport']),
                            'destination'         => strtoupper($segment['arrival']['airport']),
                            'departure_time'      => Carbon::parse($segment['departure']['time']),
                            'arrival_time'        => Carbon::parse($segment['arrival']['time']),
                            'airline_code'        => strtoupper($segment['airline_code']),
                            'flight_number'       => $segment['flight_number'],
                        ]);
                    }
                }

                // 🟢 INSERTION DES PASSAGERS (flight_passengers)
                foreach ($validatedData['passengers'] as $passenger) {
                    // Normalisation du type pour correspondre aux enums (ADT, CHD, INF) de votre migration
                    $inputGenericType = strtolower($passenger['passenger_type'] ?? 'adt');
                    $passengerType = 'ADT';
                    if (str_contains($inputGenericType, 'chd') || str_contains($inputGenericType, 'child')) {
                        $passengerType = 'CHD';
                    } elseif (str_contains($inputGenericType, 'inf') || str_contains($inputGenericType, 'infant')) {
                        $passengerType = 'INF';
                    }

                    // Normalisation de la civilité pour votre champ string(10)
                    $civility = strtoupper(str_replace('.', '', $passenger['civility'])); // M. -> M, Mme -> MME

                    $flightBooking->passengers()->create([
                        'passenger_type'  => $passengerType,
                        'title'           => $civility,
                        'first_name'      => $passenger['first_name'],
                        'last_name'       => $passenger['last_name'],
                        'birth_date'      => $passenger['birth_date'],
                        'passport_number' => $passenger['passport_number'] ?? null,
                        'passport_expiry' => $passenger['passport_expiry'] ?? null,
                    ]);
                }

                return $flightBooking;
            });

            // 3. CACHE TEMPORAIRE DES DONNÉES DU VOL (10 min)
            Cache::put('flight_payload_' . $booking->id, $selectedFlight, 600);

            // 4. INITIATION DU ROUTAGE DU PAIEMENT (Momo, OM, Wave, Card)
            $paymentResult = $this->paymentService->initiateLocalPayment(
                $paymentMethod,
                $phoneNumber,
                $amountToDebit, // Débite soit les 5000 XAF (Hold), soit le prix total (Now)
                $booking->id,
                $currencyCode
            );

            if (!$paymentResult) {
                $booking->update(['booking_status' => 'initiation_failed']);
                return response()->json([
                    'status'  => 'error',
                    'message' => 'La passerelle de paiement locale n\'a pas pu générer la demande de débit.'
                ], 400);
            }

            // 5. RÉPONSE DYNAMIQUE POUR L'INTERFACE FRONTEND (React / Next.js)
            if (is_array($paymentResult) && isset($paymentResult['type']) && $paymentResult['type'] === 'redirect') {
                return response()->json([
                    'status'       => 'redirect_required',
                    'message'      => 'Redirection vers l\'interface bancaire sécurisée.',
                    'redirect_url' => $paymentResult['redirect_url'],
                    'booking_id'   => $booking->id
                ]);
            }

            return response()->json([
                'status'     => 'waiting_confirmation',
                'message'    => 'Demande de paiement envoyée. Veuillez valider le prompt de confirmation sur votre téléphone.',
                'booking_id' => $booking->id
            ]);

        } catch (\Exception $e) {
            Log::critical('[verifyAndPay] Erreur critique lors de l\'initiation globale', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString()
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue à l\'initialisation : ' . $e->getMessage(),
            ], 500);
        }
    }
}

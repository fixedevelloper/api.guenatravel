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
            'travel_class'               => 'nullable|string|in:Economy,Business,First,PremiumEconomy',
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

    public function getExtraServices(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'session_id'       => ['required', 'string'],
                'fare_source_code' => ['required', 'string'],
            ]);

            $result = $this->travelOproService->fetchExtraServices(
                $validated['session_id'],
                $validated['fare_source_code']
            );

            if (!$result['success']) {
                return response()->json([
                    'success' => false,
                    'message' => $result['error_message'] ?? 'Impossible de récupérer les services.',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'baggage' => $result['baggage'],
                    'meals'   => $result['meals'],
                    'seats'   => $result['seats'],
                ],
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Paramètres invalides.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            Log::error('getExtraServices error', ['message' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Erreur serveur.',
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
        // 1. EXTRACTION ET AJUSTEMENT DYNAMIQUE DES RÈGLES POUR LES EXTRAS
        $rules = [
            'session_identifier'                                => 'required|string',
            'booking_type'                                      => 'required|string|in:now,hold',

            // Bloc Selected Flight - Général
            'selected_flight'                                   => 'required|array',
            'selected_flight.id'                                => 'required|string',

            // Bloc Selected Flight - Info GDS (Travelport)
            'selected_flight.travelport'                        => 'required|array',
            'selected_flight.travelport.transaction_id'         => 'required|string',
            'selected_flight.travelport.offering_id'            => 'required|string',
            'selected_flight.travelport.gds_authority_value'    => 'required|string',

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

            // Bloc Options Globales du Front
            'insuranceSelected'                                 => 'required|boolean',
            'extraBaggage'                                      => 'required|integer',
            'outboundMeal'                                      => 'nullable|string',
            'inboundMeal'                                       => 'nullable|string',

            // Options et Paiement
            'payment_method'                                    => 'required|string|in:momo,card,wave,om',
            'phone_number'                                      => 'nullable|string|min:9|max:15',
            'finalpricetopay'                                   => 'required|numeric',

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

        // Génération dynamique des règles de validation pour les clés d'extras par passager
        $inputData = $request->all();
        if (isset($inputData['passengers']) && is_array($inputData['passengers'])) {
            foreach ($inputData['passengers'] as $index => $p) {
                $pKey = $index + 1;
                $rules["ExtraServiceOutbound_$pKey"] = 'sometimes|array';
                $rules["ExtraServiceInbound_$pKey"]  = 'sometimes|array';
                $rules["SeatOutbound_$pKey"]         = 'sometimes|array';
                $rules["SeatInbound_$pKey"]          = 'sometimes|array';
                $rules["SeatOutboundCode_$pKey"]     = 'sometimes|array';
                $rules["SeatInboundCode_$pKey"]      = 'sometimes|array';
                $rules["SeatOutboundPrice_$pKey"]    = 'sometimes|array';
                $rules["SeatInboundPrice_$pKey"]     = 'sometimes|array';
            }
        }

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

            // Utiliser le montant final calculé par le front incluant les extras options
            $amountToDebit     = (float) $validatedData['finalpricetopay'];
            $holdFee           = 5000;

            if ($bookingType === 'hold') {
                $amountToDebit = (float) $holdFee;
            }

            // ----------------------------------------------------------------
            // 2. ENREGISTREMENT ET GESTION DU COMPTE (Transaction SQL)
            // ----------------------------------------------------------------
            $booking = DB::transaction(function () use ($validatedData, $selectedFlight, $amountToDebit, $sessionIdentifier, $paymentMethod, $bookingType, $currencyCode) {

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

                // 🟢 INSERTION DE LA RÉSERVATION PRINCIPALE
                $flightBooking = FlightBooking::create([
                    'user_id'            => $userId,
                    'session_identifier' => $sessionIdentifier,
                    'pnr'                => null,
                    'booking_type'       => $bookingType,
                    'booking_status'     => 'pending_payment',
                    'total_amount'       => (float) $validatedData['finalpricetopay'],
                    'amount_paid'        => 0.00,
                    'raw_flight_data'    => $selectedFlight,
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
                            'gds_authority_value' => $selectedFlight['travelport']['gds_authority_value'] ?? 'Travelport',
                            'origin'              => strtoupper($segment['departure']['airport']),
                            'destination'         => strtoupper($segment['arrival']['airport']),
                            'departure_time'      => Carbon::parse($segment['departure']['time']),
                            'arrival_time'        => Carbon::parse($segment['arrival']['time']),
                            'airline_code'        => strtoupper($segment['airline_code']),
                            'flight_number'       => $segment['flight_number'],
                        ]);
                    }
                }

                // 🟢 INSERTION DES PASSAGERS ET DE LEURS EXTRAS CORRESPONDANTS
                foreach ($validatedData['passengers'] as $index => $passenger) {
                    $pKey = $index + 1;

                    $inputGenericType = strtolower($passenger['passenger_type'] ?? 'adt');
                    $passengerType = 'ADT';
                    if (str_contains($inputGenericType, 'chd') || str_contains($inputGenericType, 'child')) {
                        $passengerType = 'CHD';
                    } elseif (str_contains($inputGenericType, 'inf') || str_contains($inputGenericType, 'infant')) {
                        $passengerType = 'INF';
                    }

                    $civility = strtoupper(str_replace('.', '', $passenger['civility']));

                    $dbPassenger = $flightBooking->passengers()->create([
                        'passenger_type'  => $passengerType,
                        'title'           => $civility,
                        'first_name'      => $passenger['first_name'],
                        'last_name'       => $passenger['last_name'],
                        'birth_date'      => $passenger['birth_date'],
                        'passport_number' => $passenger['passport_number'] ?? null,
                        'passport_expiry' => $passenger['passport_expiry'] ?? null,
                    ]);

                    // ── PARSAGE DES REPAS / BAGAGES ALLER ──
                    if (!empty($validatedData["ExtraServiceOutbound_$pKey"][0])) {
                        foreach ($validatedData["ExtraServiceOutbound_$pKey"][0] as $extra) {
                            $dbPassenger->services()->create([
                                'service_type'  => str_starts_with($extra['serviceId'], 'XB') ? 'baggage' : 'meal',
                                'service_id'    => $extra['serviceId'],
                                'description'   => str_starts_with($extra['serviceId'], 'XB') ? 'Bagage supplémentaire' : 'Repas de cabine Aller',
                                'quantity'      => (int) ($extra['quantity'] ?? 1),
                                'segment_index' => (int) ($extra['segment'] ?? 0),
                                'direction'     => 'outbound',
                                'amount'        => 45000.00, // Ton prix fixe par bagage ou ajusté selon le GDS
                                'currency'      => $currencyCode,
                            ]);
                        }
                    }

                    // ── PARSAGE DES REPAS RETOUR ──
                    if (!empty($validatedData["ExtraServiceInbound_$pKey"][0])) {
                        foreach ($validatedData["ExtraServiceInbound_$pKey"][0] as $extra) {
                            $dbPassenger->services()->create([
                                'service_type'  => 'meal',
                                'service_id'    => $extra['serviceId'],
                                'description'   => 'Repas de cabine Retour',
                                'quantity'      => (int) ($extra['quantity'] ?? 1),
                                'segment_index' => (int) ($extra['segment'] ?? 0),
                                'direction'     => 'inbound',
                                'amount'        => 0.00,
                                'currency'      => $currencyCode,
                            ]);
                        }
                    }

                    // ── PARSAGE DES SIÈGES ALLER ──
                    if (!empty($validatedData["SeatOutbound_$pKey"][0])) {
                        foreach ($validatedData["SeatOutbound_$pKey"][0] as $segmentIdx => $serviceId) {
                            $dbPassenger->services()->create([
                                'service_type'  => 'seat',
                                'service_id'    => $serviceId,
                                'seat_code'     => $validatedData["SeatOutboundCode_$pKey"][$segmentIdx] ?? null,
                                'description'   => 'Sélection Siège Aller',
                                'quantity'      => 1,
                                'segment_index' => $segmentIdx,
                                'direction'     => 'outbound',
                                'amount'        => (float) ($validatedData["SeatOutboundPrice_$pKey"][$segmentIdx] ?? 0.00),
                                'currency'      => $currencyCode,
                            ]);
                        }
                    }

                    // ── PARSAGE DES SIÈGES RETOUR ──
                    if (!empty($validatedData["SeatInbound_$pKey"][0])) {
                        foreach ($validatedData["SeatInbound_$pKey"][0] as $segmentIdx => $serviceId) {
                            $dbPassenger->services()->create([
                                'service_type'  => 'seat',
                                'service_id'    => $serviceId,
                                'seat_code'     => $validatedData["SeatInboundCode_$pKey"][$segmentIdx] ?? null,
                                'description'   => 'Sélection Siège Retour',
                                'quantity'      => 1,
                                'segment_index' => $segmentIdx,
                                'direction'     => 'inbound',
                                'amount'        => (float) ($validatedData["SeatInboundPrice_$pKey"][$segmentIdx] ?? 0.00),
                                'currency'      => $currencyCode,
                            ]);
                        }
                    }
                }

                return $flightBooking;
            });

            // 3. CACHE TEMPORAIRE DES DONNÉES DU VOL (10 min)
            Cache::put('flight_payload_' . $booking->id, $selectedFlight, 600);

            // 4. INITIATION DU ROUTAGE DU PAIEMENT LOCAL
            $phoneNumber = $validatedData['phone_number'] ?? null;
            $paymentResult = $this->paymentService->initiateLocalPayment(
                $paymentMethod,
                $phoneNumber,
                $amountToDebit, // Débite 5000 XAF si hold ou prix total si now
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

            // 5. RÉPONSE DYNAMIQUE POUR L'INTERFACE FRONTEND (Next.js)
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
    public function voidTicketQuote(Request $request): JsonResponse
    {
        $request->validate([
            'unique_id'              => 'required|string',
            'pax_details'            => 'required|array|min:1',
            'pax_details.*.type'     => 'required|string|in:ADT,CHD,INF',
            'pax_details.*.title'    => 'required|string',
            'pax_details.*.firstName'=> 'required|string',
            'pax_details.*.lastName' => 'required|string',
            'pax_details.*.eTicket'  => 'required|string',
        ]);

        $result = $this->travelOproService->voidTicketQuote(
            $request->input('unique_id'),
            $request->input('pax_details')
        );

        if (!$result['success']) {
            $status = match($result['type']) {
            'validation_error'  => 422,
            'void_quote_failed' => 400,
            default             => 500,
        };

        return response()->json([
            'message'    => $result['error_message'],
            'error_code' => $result['error_code'] ?? null,
            'type'       => $result['type'],
            'unique_id'  => $result['unique_id']  ?? null,
        ], $status);
    }

        return response()->json([
            'message'         => 'Void quote généré avec succès.',
            'unique_id'       => $result['unique_id'],
            'ptr_unique_id'   => $result['ptr_unique_id'],
            'status'          => $result['status'],
            'voiding_window'  => $result['voiding_window'],
            'processing_time' => $result['processing_time'],
            'void_quotes'     => $result['void_quotes'],
        ], 200);
    }
    public function cancelBooking(Request $request): JsonResponse
    {
        $request->validate([
            'unique_id' => 'required|string',
        ]);

        $result = $this->travelOproService->cancelBooking($request->input('unique_id'));

        if (!$result['success']) {
            $status = match($result['type']) {
            'validation_error' => 422,
            'cancel_failed'    => 400,
            default            => 500,
        };

        return response()->json([
            'message'    => $result['error_message'],
            'error_code' => $result['error_code'] ?? null,
            'type'       => $result['type'],
            'unique_id'  => $result['unique_id']  ?? null,
        ], $status);
    }

        return response()->json([
            'message'   => 'Réservation annulée avec succès.',
            'unique_id' => $result['unique_id'],
            'target'    => $result['target'],
        ], 200);
    }
}

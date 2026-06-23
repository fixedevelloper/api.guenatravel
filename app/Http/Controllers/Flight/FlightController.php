<?php

namespace App\Http\Controllers\Flight;

use App\Events\UserAutoRegistered;
use App\Http\Controllers\Controller;
use App\Models\FlightBooking;
use App\Models\User;
use App\Services\Travelport\FlightBookingService;
use App\Services\Travelport\PaymentService;
use App\Services\Travelport\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class FlightController extends Controller
{
    protected FlightBookingService $bookingService;
    protected TicketService $ticketService;
    protected $paymentService;

    // Injection des deux services requis via le constructeur
    public function __construct(FlightBookingService $bookingService, TicketService $ticketService,PaymentService $paymentService)
    {
        $this->bookingService = $bookingService;
        $this->ticketService = $ticketService;
        $this->paymentService=$paymentService;
    }

    public function search(Request $request)
    {
        // ... (Ton code de recherche initial reste inchangé et propre)
        $validatedData = $request->validate([
            'trip_type' => 'required|string|in:one_way,round_trip,multi_city',
            'return_date' => 'nullable|date|after_or_equal:departure_date',
            'passengers.adults' => 'required|integer|min:1',
            'passengers.children' => 'required|integer|min:0',
            'passengers.infants' => 'required|integer|min:0',
            'segments' => 'required|array|min:1',
            'segments.*.origin' => 'required|string|size:3',
            'segments.*.destination' => 'required|string|size:3',
            'segments.*.departure_date' => 'required|date|after_or_equal:today',
            'origin' => 'required|string|size:3',
            'destination' => 'required|string|size:3',
            'departure_date' => 'required|date|after_or_equal:today',
        ]);

        try {
            $rawResults = $this->bookingService->searchFlightOffers($validatedData);

            if (!isset($rawResults['flights']) || empty($rawResults['flights'])) {
                return response()->json(['status' => 'success', 'results_count' => 0, 'flights' => []]);
            }

            foreach ($rawResults['flights'] as $key => &$flight) {
                $totalGds = $flight['price_details']['total_sabre'] ?? $flight['price_details']['base_price'] ?? 0;
                $fraisAgence = 15000;
                $flight['price_details']['agency_fees'] = (float)$fraisAgence;
                $flight['price_details']['final_price_to_pay'] = (float)($totalGds + $fraisAgence);
                $flight['id'] = 'fl_travelport_' . md5($key . microtime());
            }

            return response()->json($rawResults);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la recherche de vols Travelport.',
                'debug' => env('APP_DEBUG') ? $e->getMessage() : 'Une erreur interne est survenue.'
            ], 500);
        }
    }

    /**
     * Initialise une nouvelle session de réservation Travelport (Workbench).
     * * @return JsonResponse
     */
    public function CreateInitSession()
    {
        try {
            Log::info('[CreateInitSession] Tentative d\'initialisation d\'une session Workbench');

            // 1. Appel du service pour récupérer l'UUID de Travelport
            $sessionIdentifier = $this->bookingService->createNewWorkbench();

            // 2. Stockage dans la session Laravel
            // Vous pouvez utiliser la clé 'travelport_workbench_id' (ou celle de votre choix)
            session(['travelport_workbench_id' => $sessionIdentifier]);

            Log::info('[CreateInitSession] Session initialisée et stockée avec succès', [
                'session_id' => $sessionIdentifier
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Session de réservation initialisée avec succès.',
                'data' => [
                    'session_identifier' => $sessionIdentifier // Optionnel si vous gérez tout par le serveur désormais
                ]
            ], 201);

        } catch (\RuntimeException $e) {
            Log::error('[CreateInitSession] Erreur métier lors de l\'initialisation', [
                'message' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);

        } catch (\Exception $e) {
            Log::critical('[CreateInitSession] Erreur critique inattendue', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Impossible d\'initialiser la session de réservation pour le moment.'
            ], 500);
        }
    }

    /**
     * Enregistre les passagers de la commande dans la session Travelport.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function addPassengers(Request $request): JsonResponse
    {
        // 🔥 La validation attend maintenant obligatoirement l'ID dans le body
        $validated = $request->validate([
            'session_identifier' => 'required|string', // <-- Requis ici
            'passengers' => 'required|array|min:1',
            'passengers.*.civility' => 'required|string|in:MR,MRS',
            'passengers.*.first_name' => 'required|string|max:50',
            'passengers.*.last_name' => 'required|string|max:50',
            'passengers.*.birth_date' => 'required|date|date_format:Y-m-d',
            'contact_info' => 'required|array',
            'contact_info.email' => 'required|email',
            'contact_info.phone' => 'required|string',
            'selected_flight' => 'required|array',
        ]);

        // Extraction depuis les données validées du payload
        $sessionIdentifier = $validated['session_identifier'];

        try {
            $this->bookingService->buildOfferFromCatalog($sessionIdentifier, $validated['selected_flight']);
            // Exécution de votre service d'injection Travelport
            $this->bookingService->addTravelersToWorkbench(
                $sessionIdentifier,
                $validated['passengers'],
                $validated['contact_info'],
                $validated['selected_flight']
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Tous les passagers ont été rattachés avec succès.'
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * ÉTAPE 2 & 3 : Tunnel de réservation transactionnel unifié (GDS + Mobile Money)
     * @param Request $request
     * @return JsonResponse
     */

    /**
     * Initialise la commande, encaisse les fonds de manière asynchrone (Push ou redirection)
     * et libère immédiatement le client vers la page d'attente React.
     * @param Request $request
     * @return JsonResponse
     */
    public function verifyAndPay(Request $request)
    {
        // 1. VALIDATION STRICTE ET INTERNATIONALE DU PAYLOAD
        $rules = [
            'session_identifier'                                => 'required|string|uuid',
            'booking_type'                                      => 'required|string|in:now,hold',

            // Bloc Selected Flight - Général
            'selected_flight'                                   => 'required|array',
            'selected_flight.id'                                => 'required|string',

            // Bloc Selected Flight - Info GDS (Travelport)
            'selected_flight.travelport'                        => 'required|array',
            'selected_flight.travelport.transaction_id'         => 'required|string',
            'selected_flight.travelport.offering_id'            => 'required|string',
            'selected_flight.travelport.gds_authority_value'    => 'required|string',
            'selected_flight.travelport.catalog_offerings_identifier' => 'required|string',

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
            'selected_flight.itinerary.*.offering_id'           => 'required|string',
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
            'passengers.*.civility'                             => 'required|string|in:M.,Mme',
            'passengers.*.first_name'                           => 'required|string|max:100',
            'passengers.*.last_name'                            => 'required|string|max:100',
            'passengers.*.birth_date'                           => 'required|date|before:today',
            'passengers.*.passport_number'                      => 'required|string|max:50',
            'passengers.*.passenger_type'                       => 'sometimes|string|max:3',
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

            $holdFee = 5000;
            $amountToDebit = ($bookingType === 'hold') ? (float) $holdFee : $totalFlightPrice;

            // ----------------------------------------------------------------
            // 2. ENREGISTREMENT ET GESTION DU COMPTE (Transaction unique)
            // ----------------------------------------------------------------
            $booking = DB::transaction(function () use ($validatedData, $selectedFlight, $totalFlightPrice, $sessionIdentifier, $paymentMethod, $bookingType, $currencyCode, $holdFee) {

                $contactEmail = $validatedData['contact_info']['email'];
                $contactPhone = $validatedData['contact_info']['phone'];
                $userId = auth()->id();

                // 🟢 CRÉATION DU COMPTE SI L'UTILISATEUR N'EST PAS AUTHENTIFIÉ
                if (!$userId) {
                    // On vérifie si un utilisateur possède déjà cet e-mail
                    $user = User::where('email', $contactEmail)->first();

                    if (!$user) {
                        // Extraction du premier passager pour lui donner un nom par défaut cohérent
                        $primaryPassenger = $validatedData['passengers'][0] ?? null;
                        $firstName = $primaryPassenger ? $primaryPassenger['first_name'] : 'Client';
                        $lastName = $primaryPassenger ? $primaryPassenger['last_name'] : 'Guen\'s';

                        // Génération d'un mot de passe sécurisé temporaire
                        $temporaryPassword = Str::random(10);

                        $user = User::create([
                            'name'     => $firstName . ' ' . $lastName,
                            'email'    => $contactEmail,
                            'phone'    => $contactPhone,
                            'password' => Hash::make($temporaryPassword),
                        ]);

                        // 🔥 ÉVÉNEMENT : Permet de déclencher l'envoi du mail d'accès (identifiants + lien)
                        event(new UserAutoRegistered($user, $temporaryPassword));
                    }

                    $userId = $user->id;

                    // Optionnel : Connecter directement l'utilisateur dans la session actuelle
                    // auth()->login($user);
                }

                $isHold = ($bookingType === 'hold');

                $flightBooking = FlightBooking::create([
                    'user_id'            => $userId, // Associé au compte existant ou nouvellement créé
                    'session_identifier' => $sessionIdentifier,
                    'booking_type'       => $bookingType,
                    'booking_status'     => 'pending_payment',
                    'total_amount'       => $totalFlightPrice,
                    'amount_paid'        => 0.00,
                    'hold_fee_paid'      => $isHold ? $holdFee : 0.00,
                    'raw_flight_data'    => $selectedFlight,
                    'currency'           => $currencyCode,
                    'payment_method'     => $paymentMethod,
                    'payment_status'     => 'unpaid',
                    'contact_email'      => $contactEmail,
                    'contact_phone'      => $contactPhone,
                ]);

                // Extraction et hydratation des trajets
                $itineraries = $selectedFlight['itinerary'] ?? [];
                $sortOrder = 0;

                foreach ($itineraries as $journey) {
                    $offeringId = $journey['offering_id'] ?? 'cpo_default';
                    $brandValue = $journey['brand_value'] ?? 'brand_default';

                    foreach ($journey['segments'] ?? [] as $segment) {
                        $flightBooking->trips()->create([
                            'sort_order'          => $sortOrder++,
                            'offering_id'         => $offeringId,
                            'brand_value'         => $brandValue,
                            'gds_authority_value' => $selectedFlight['travelport']['gds_authority_value'] ?? null,
                            'origin'              => $segment['departure']['airport'] ?? 'DLA',
                            'destination'         => $segment['arrival']['airport'] ?? 'ORD',
                            'departure_time'      => $segment['departure']['time'] ?? now(),
                            'arrival_time'        => $segment['arrival']['time'] ?? now(),
                            'airline_code'        => $segment['airline_code'] ?? 'UA',
                            'flight_number'       => $segment['flight_number'] ?? '000',
                        ]);
                    }
                }

                // Sauvegarde des passagers
                foreach ($validatedData['passengers'] as $passenger) {
                    $flightBooking->passengers()->create([
                        'passenger_type'  => $passenger['passenger_type'] ?? 'ADT',
                        'title'           => $passenger['civility'],
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

            // 4. INITIATION DU ROUTAGE DU PAIEMENT INTERNATIONAL
            $paymentResult = $this->paymentService->initiateLocalPayment(
                $paymentMethod,
                $phoneNumber,
                $amountToDebit,
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

            // 5. RÉPONSE DYNAMIQUE POUR L'INTERFACE REACT / NEXT.JS
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

    public function payHoldBalance(Request $request, $bookingId)
    {
        $booking = FlightBooking::where('id', $bookingId)
            ->where('booking_status', 'hold')
            ->firstOrFail();

        // Le montant réclamé est l'intégralité du prix, car les 5 000 XAF étaient des frais de service
        $balanceToPay = $booking->total_amount;

        $paymentMethod = $request->input('payment_method'); // momo, om, card
        $phoneNumber   = $request->input('phone_number');

        // 1. On initie le paiement du montant total du billet auprès de l'opérateur local
        $paymentInitiated = $this->paymentService->initiateLocalPayment(
            $paymentMethod,
            $phoneNumber,
            $balanceToPay,
            $booking->id,
            $booking->currency
        );

        if (!$paymentInitiated) {
            return response()->json(['message' => 'Impossible de lancer le paiement du solde.'], 400);
        }

        // On passe le statut en attente de la confirmation finale du billet
        $booking->update(['booking_status' => 'balance_checking']);

        return response()->json([
            'status' => 'waiting_confirmation',
            'message' => "Veuillez confirmer le prompt de " . $balanceToPay . " " . $booking->currency . " sur votre téléphone."
        ]);
    }
    /**
     * Récupère le statut en temps réel d'une réservation pour le polling du Front-end.
     * * @param int $id ID de la table flight_bookings
     * @return \Illuminate\Http\JsonResponse
     */
    public function getBookingStatus($id)
    {
        $booking = FlightBooking::find($id);

        if (!$booking) {
            return response()->json([
                'booking_status' => 'gds_failed',
                'message' => 'Réservation introuvable ou expirée.'
            ], 404);
        }

        // Traduction ou enrichissement des messages d'erreur pour l'interface utilisateur
        $errorMessage = '';
        if (in_array($booking->booking_status, ['gds_failed', 'gds_failed_requires_refund'])) {
            $errorMessage = "Le GDS ou la compagnie aérienne n'a pas pu valider la réservation. Le remboursement est en cours.";
        } elseif ($booking->booking_status === 'initiation_failed') {
            $errorMessage = "L'opérateur Mobile Money a refusé d'initier la transaction.";
        }

        return response()->json([
            'id'             => $booking->id,
            'booking_status' => $booking->booking_status, // pending_payment, paid_pending_gds, ticketed, hold...
            'pnr'            => $booking->pnr,            // Sera null tant que le statut n'est pas ticketed/hold
            'message'        => $errorMessage
        ]);
    }

}

<?php

namespace App\Http\Controllers\Flight;

use App\Http\Controllers\Controller;
use App\Services\Travelport\FlightBookingService;
use App\Services\Travelport\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FlightController extends Controller
{
    protected FlightBookingService $bookingService;
    protected TicketService $ticketService;

    // Injection des deux services requis via le constructeur
    public function __construct(FlightBookingService $bookingService, TicketService $ticketService)
    {
        $this->bookingService = $bookingService;
        $this->ticketService = $ticketService;
    }

    public function search(Request $request)
    {
        // ... (Ton code de recherche initial reste inchangé et propre)
        $validatedData = $request->validate([
            'trip_type'               => 'required|string|in:one_way,round_trip,multi_city',
            'return_date'             => 'nullable|date|after_or_equal:departure_date',
            'passengers.adults'       => 'required|integer|min:1',
            'passengers.children'     => 'required|integer|min:0',
            'passengers.infants'      => 'required|integer|min:0',
            'segments'                => 'required|array|min:1',
            'segments.*.origin'       => 'required|string|size:3',
            'segments.*.destination'  => 'required|string|size:3',
            'segments.*.departure_date'=> 'required|date|after_or_equal:today',
            'origin'                  => 'required|string|size:3',
            'destination'             => 'required|string|size:3',
            'departure_date'          => 'required|date|after_or_equal:today',
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
            $sessionIdentifier = $this->bookingService->createInitReservationWorkbench();

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
            'session_identifier'                          => 'required|string', // <-- Requis ici
            'passengers'                                  => 'required|array|min:1',
            'passengers.*.civility'                       => 'required|string|in:MR,MRS',
            'passengers.*.first_name'                     => 'required|string|max:50',
            'passengers.*.last_name'                      => 'required|string|max:50',
            'passengers.*.birth_date'                     => 'required|date|date_format:Y-m-d',
            'contact_info'                                => 'required|array',
            'contact_info.email'                          => 'required|email',
            'contact_info.phone'                          => 'required|string',
            'selected_flight'                             => 'required|array',
        ]);

        // Extraction depuis les données validées du payload
        $sessionIdentifier = $validated['session_identifier'];

        try {
            $this->bookingService->buildOfferFromCatalog($sessionIdentifier,$validated['selected_flight']);
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
    public function verifyAndPay(Request $request)
    {
        // 1. VALIDATION STRICTE ET MINIMALE DU PAYLOAD
        $rules = [
            'session_identifier'                                => 'required|string',
            'booking_type'                                      => 'required|string|in:now,hold',
            'selected_flight.price_details.final_price_to_pay'  => 'required|numeric',
            'selected_flight.price_details.currency'            => 'required|string',
            'payment_method'                                    => 'required|string|in:momo,om,card,hold',
            'passengers'                                        => 'required|array|min:1',
        ];

        // Téléphone obligatoire si paiement par Mobile Money actif
        if ($request->input('booking_type') === 'now' && in_array($request->input('payment_method'), ['momo', 'om'])) {
            $rules['phone_number'] = 'required|string';
        }

        $validatedData = $request->validate($rules);

        try {
            $sessionIdentifier = $validatedData['session_identifier'];
            $bookingType       = $validatedData['booking_type'];
            $paymentMethod     = $validatedData['payment_method'];
            $currencyCode      = $request->input('selected_flight.price_details.currency');
            $totalFlightPrice  = (float) $request->input('selected_flight.price_details.final_price_to_pay');
            $phoneNumber       = $request->input('phone_number') ?? null;

            // ----------------------------------------------------------------
            // CALCUL DU MONTANT DU DÉBIT (TOTALITÉ OU ACOMPTE)
            // ----------------------------------------------------------------
            $holdFee = 5000; // Frais fixes obligatoires pour bloquer la réservation
            $amountToDebit = ($bookingType === 'hold') ? (float) $holdFee : $totalFlightPrice;

            // ----------------------------------------------------------------
            // PASSERELLE DE PAIEMENT & DÉBIT FINTECH
            // ----------------------------------------------------------------
            // On détermine la méthode pour l'encaissement (si hold, on regarde l'opérateur sélectionné)
            $actualPaymentMethod = ($bookingType === 'hold') ? $paymentMethod : $paymentMethod;

            $paymentStatus = $this->processLocalPayment($actualPaymentMethod, $phoneNumber, $amountToDebit);

            if (!$paymentStatus) {
                Log::warning('[verifyAndPay] Échec du prélèvement local.', ['session' => $sessionIdentifier, 'amount' => $amountToDebit]);
                return response()->json([
                    'status'  => 'payment_failed',
                    'message' => 'La transaction financière a été refusée, annulée ou a expiré.'
                ], 402);
            }

            // Si paiement par carte réussi en totalité, on injecte les informations bancaires requises par le GDS
            if ($bookingType === 'now' && $paymentMethod === 'card') {
                $cardData = [
                    'card_number'       => $request->input('card_number'),
                    'card_expiry'       => $request->input('card_expiry'),
                    'card_holder_name'  => $request->input('card_holder_name'),
                    'card_cvv'          => $request->input('card_cvv'),
                    'card_code'         => $request->input('card_code', 'VI')
                ];
                $this->bookingService->addFormOfPayment($sessionIdentifier, $cardData);
                $this->bookingService->addPayment($sessionIdentifier, $amountToDebit, $currencyCode, $request->input('selected_flight'));
            }

            // ----------------------------------------------------------------
            // VALIDATION DIRECTE DU DOSSIER (COMMIT PNR)
            // ----------------------------------------------------------------
            Log::info('[verifyAndPay] Encaissé. Génération du PNR Travelport.', ['session' => $sessionIdentifier]);

            $commitResult = $this->bookingService->commitReservation($sessionIdentifier);
            $pnr          = $commitResult['pnr'] ?? null;

            if (!$pnr) {
                Log::critical('[verifyAndPay] CRITIQUE : Encaissé mais échec génération PNR', [
                    'session' => $sessionIdentifier,
                    'amount'  => $amountToDebit
                ]);
                return response()->json([
                    'status'  => 'pnr_failed',
                    'message' => 'Votre paiement a été reçu, mais une latence technique empêche la confirmation immédiate de vos places. Nos agents valident votre dossier manuellement.',
                ], 500);
            }

            // ----------------------------------------------------------------
            // ÉMISSION DES BILLETS (UNIQUEMENT SI PAYÉ EN TOTALITÉ)
            // ----------------------------------------------------------------
            $ticketResult = null;

            if ($bookingType === 'now') {
                $ticketResult = $this->bookingService->issueTickets($pnr);
                $successMessage = 'Votre paiement a été validé et vos billets électroniques ont été émis.';
            } else {
                Log::info('[verifyAndPay] Mode HOLD. Places bloquées sur le GDS.', ['pnr' => $pnr]);
                $successMessage = 'Votre tarif a été bloqué avec succès ! Vos places sont réservées.';
            }

            return response()->json([
                'status'  => 'success',
                'message' => $successMessage,
                'data'    => [
                    'pnr'              => $pnr,
                    'booking_type'     => $bookingType,
                    'tickets'          => $ticketResult,
                    'amount_paid'      => $amountToDebit,
                    'remaining_amount' => ($bookingType === 'hold') ? ($totalFlightPrice - $holdFee) : 0,
                    'currency'         => $currencyCode,
                    'passengers_count' => count($validatedData['passengers']),
                ]
            ]);

        } catch (\Exception $e) {
            Log::critical('[verifyAndPay] Erreur critique durant le paiement final', [
                'exception' => $e->getMessage()
            ]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur technique est survenue lors de la finalisation de votre commande : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Simulation / Intégration de ta passerelle de paiement locale (Campay, Monetbil, Maviance...)
     */
    protected function processLocalPayment(string $method, ?string $phone, float $amount): bool
    {
        if (env('APP_ENV') === 'local') {
            return true;
        }

        // Ton code de communication cURL ou Http::post avec l'agrégateur choisi
        return true;
    }
}

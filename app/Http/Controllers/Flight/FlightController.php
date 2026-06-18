<?php

namespace App\Http\Controllers\Flight;

use App\Http\Controllers\Controller;
use App\Services\Travelport\FlightBookingService;
use App\Services\Travelport\TicketService;
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
     * ÉTAPE 2 & 3 : Tunnel de réservation transactionnel unifié (GDS + Mobile Money)
     */
    public function verifyAndPay(Request $request)
    {
        // 1. Validation stricte du panier Zustand et des données voyageurs / contacts
        $validatedData = $request->validate([
            'selected_flight'                                  => 'required|array',
            'selected_flight.id'                               => 'required|string', // Utilisé comme session identifier
            'selected_flight.price_details'                    => 'required|array',
            'selected_flight.price_details.final_price_to_pay' => 'required|numeric',
            'selected_flight.itinerary'                        => 'required|array|min:1',

            // Infos de contact de l'acheteur (Essentiel pour APIS)
            'contact_info'                                     => 'required|array',
            'contact_info.email'                               => 'required|email',
            'contact_info.phone'                               => 'required|string',

            // Informations de facturation locale
            'payment_method'                                   => 'required|string|in:momo,om,card',
            'phone_number'                                     => 'required_if:payment_method,momo,om|string',

            // Liste complète des passagers
            'passengers'                                       => 'required|array|min:1',
            'passengers.*.civility'                            => 'required|string',
            'passengers.*.first_name'                          => 'required|string|max:50',
            'passengers.*.last_name'                           => 'required|string|max:50',
            'passengers.*.birth_date'                          => 'required|date',
            'passengers.*.passport_number'                     => 'required|string',
        ]);

        try {
            $selectedFlight = $validatedData['selected_flight'];

            // L'identifiant de session ou ressource initié lors de l'analyse ou du choix du vol
            $reservationResourceIdentifier = $selectedFlight['id'];

            // ------------------------------------------------------------
            // PHASE GDS PRÉ-PAIEMENT : Préparer et figer le dossier
            // ------------------------------------------------------------

            // A. Sécurité Disponibilité : Vérification des classes de sièges
            $isAvailable = $this->bookingService->verifySeatAvailability($selectedFlight);
            logger($isAvailable);
            if (!$isAvailable) {
                return response()->json([
                    'status' => 'expired',
                    'message' => 'Le statut de ce vol ou son tarif a expiré auprès de la compagnie aérienne.'
                ], 422);
            }

            // B. Injection du Profil Client Corporate / Agence dans la session de travail
            $this->bookingService->applyClientProfile($reservationResourceIdentifier, $validatedData['passengers']);

            // C. Injection de l'état civil complet et des documents APIS (Passeports)
            $this->bookingService->addTravelersToWorkbench(
                $reservationResourceIdentifier,
                $validatedData['passengers'],
                $validatedData['contact_info'],
                $selectedFlight
            );

            // D. Re-tarification ferme et blocage d'inventaire auprès de la compagnie aérienne
            $pricedOffer = $this->bookingService->buildOfferFromProducts($reservationResourceIdentifier);


            // ------------------------------------------------------------
            // PHASE FINANCIÈRE LOCALE : Débit Mobile Money (MTN / Orange)
            // ------------------------------------------------------------
            $amountToDebit = (float) $selectedFlight['price_details']['final_price_to_pay'];

            $paymentStatus = $this->processLocalPayment(
                $validatedData['payment_method'],
                $validatedData['phone_number'] ?? null,
                $amountToDebit
            );

            if (!$paymentStatus) {
                return response()->json([
                    'status' => 'payment_failed',
                    'message' => 'La transaction Mobile Money a été rejetée, annulée ou a expiré.'
                ], 402);
            }


            // ------------------------------------------------------------
            // PHASE GDS POST-PAIEMENT : Clôture comptable & Émission PNR
            // ------------------------------------------------------------

            // E. Déclaration de la forme de paiement d'agence (Modèle Cash) pour couvrir le flux financier local
            $this->ticketService->addCashFormOfPayment($reservationResourceIdentifier, $selectedFlight);

            // F. Attacher la décomposition comptable stricte (Tarif de base + Taxes IATA + Markup)
            $this->ticketService->attachPaymentDetails($reservationResourceIdentifier, $selectedFlight, $validatedData['passengers']);

            // G. Commit Final : Génération finale du PNR et création officielle des e-tickets
            $issuanceResult = $this->ticketService->commitAndIssueTicket($reservationResourceIdentifier);

            // Extraction sécurisée du code PNR officiel (Locator) généré par Travelport+
            $realPnr = $issuanceResult['Reservation']['Locator']['value'] ?? 'GDS-OK';
            $ticketsGenerated = $issuanceResult['AirTicket'] ?? [];

            // Tout est parfait ! On retourne les données de confirmation au client
            return response()->json([
                'status' => 'success',
                'message' => 'Votre paiement a été validé et vos billets électroniques ont été émis.',
                'data' => [
                    'pnr'              => $realPnr,
                    'tickets'          => $ticketsGenerated,
                    'amount_paid'      => $amountToDebit,
                    'passengers_count' => count($validatedData['passengers'])
                ]
            ]);

        } catch (\Exception $e) {
            // Log critique avec le détail de l'erreur pour débugger tes flux SOAP/REST de Travelport
            Log::critical('Erreur critique dans le tunnel verifyAndPay', [
                'exception' => $e->getMessage(),
                'trace'     => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur technique est survenue lors de la finalisation de votre commande.',
                'debug' => env('APP_DEBUG') ? $e->getMessage() : null
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

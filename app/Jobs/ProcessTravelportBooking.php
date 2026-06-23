<?php

namespace App\Jobs;

use App\Models\FlightBooking;
use App\Services\Travelport\FlightBookingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTravelportBooking implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Nombre de tentatives maximales (API GDS instables).
     */
    public int $tries = 3;

    /**
     * Délai en secondes entre chaque tentative.
     */
    public int $backoff = 20;

    public function __construct(
        protected int $bookingId,
        protected array $selectedFlight
    ) {}

/**
 * Exécute le workflow d'émission complet (Étapes A → J du TripServices GDS v11).
 */
public function handle(FlightBookingService $bookingService): void
{
    $booking = FlightBooking::with(['trips', 'passengers'])->find($this->bookingId);

    if (!$booking || in_array($booking->booking_status, ['ticketed', 'hold', 'gds_failed'])) {
        return;
    }

    $passengers    = $booking->passengers->toArray();
    $bookingType   = $booking->booking_type;
    $sessionId     = $booking->session_identifier;

    try {
        // ════════════════════════════════════════════════════════════════
        // PHASE 1 — PRE-COMMIT WORKBENCH (Étapes A → F)
        // ════════════════════════════════════════════════════════════════

        // Étape B — Vérification et fixation du tarif officiel (AirPrice)
        Log::info('[GDS] Étape B — AirPrice', ['booking_id' => $booking->id]);
        $bookingService->priceFlightOffer($sessionId,$this->selectedFlight , $passengers);

        // Étape C — Initialisation du Workbench si expiré ou absent
        Log::info('[GDS] Étape C — Workbench', ['booking_id' => $booking->id]);
        if (empty($sessionId)) {
            $sessionId = $bookingService->createNewWorkbench();
            $booking->update(['session_identifier' => $sessionId]);
        }

        // Étape D — Ajout de l'offre de vol au Workbench
        Log::info('[GDS] Étape D — AddOffer', ['session' => $sessionId]);
        $bookingService->addOfferToWorkbench($sessionId, $this->selectedFlight);

        // Étape E — Injection des passagers (passeports, civilités)
        Log::info('[GDS] Étape E — AddTravelers', ['count' => count($passengers)]);
        $bookingService->addTravelersToWorkbench($sessionId, $passengers, $this->selectedFlight);

        // Étape F — Premier commit → création de la réservation brute (PNR préliminaire)
        Log::info('[GDS] Étape F — Premier commit');
        $preCommit = $bookingService->commitReservation($sessionId, $booking->booking_type);
        $pnr       = $preCommit['pnr'] ?? null;

        if (!$pnr) {
            throw new \RuntimeException('Étape F : aucun PNR préliminaire retourné par Travelport.');
        }

        $booking->update(['pnr' => $pnr]);

        // Flux "hold" : le client ne paie que les frais de blocage → on s'arrête ici.
        if ($bookingType === 'hold') {
            $booking->update([
                'booking_status' => 'hold',
                'payment_status' => 'unpaid',
            ]);
            Log::info("[GDS] Réservation mise en HOLD. PNR : {$pnr}");
            return;
        }

        // ════════════════════════════════════════════════════════════════
        // PHASE 2 — POST-COMMIT WORKBENCH (Étapes G → J)
        // ════════════════════════════════════════════════════════════════

        // Étape G — Réouverture du dossier pour post-commit
        Log::info('[GDS] Étape G — PostCommitWorkbench', ['pnr' => $pnr]);
        $postSession = $bookingService->createPostCommitWorkbench($pnr);

        // Étapes H & I — Injection des informations de paiement (AddPayment)
        Log::info('[GDS] Étapes H & I — AddPayment', ['session_post' => $postSession]);
        $bookingService->addPayment(
            $postSession,
            $booking->total_amount,
            $booking->currency,
            $this->selectedFlight
        );

        // Étape J — Commit final → validation et émission des billets électroniques
        Log::info('[GDS] Étape J — Commit final & Ticketing');
        $bookingService->commitReservation($postSession, 'now');

        $booking->update([
            'booking_status' => 'ticketed',
            'payment_status' => 'paid',
        ]);

        Log::info("[GDS] Succès total — billets émis. PNR : {$pnr}");

        // event(new \App\Events\FlightBookingConfirmed($booking));

    } catch (\Exception $e) {
        Log::error("[GDS] Erreur critique sur la commande #{$this->bookingId} : " . $e->getMessage());

        if ($this->attempts() >= $this->tries) {
            $this->handleFailureRecovery($booking, $sessionId);
        }

        throw $e;
    }
}

/**
 * Protocole de repli en cas d'échec définitif du workflow GDS.
 *
 * — Si les places ont déjà été sécurisées (PNR présent) mais que le paiement
 *   a échoué, on force un statut "paid_hold_forced" pour traitement manuel
 *   depuis le back-office.
 * — Sinon, on marque la commande comme échouée et remboursable.
 */
private function handleFailureRecovery(?FlightBooking $booking, string $sessionId): void
{
    if (!$booking) {
        return;
    }

    $placesAlreadySecured = in_array($booking->booking_status, ['paid_pending_gds', 'pending_payment'])
        && !empty($booking->pnr);

    if ($placesAlreadySecured) {
        Log::warning('[GDS] Sauvetage — places sécurisées mais paiement échoué. Passage en paid_hold_forced.');
        $booking->update([
            'booking_status'    => 'paid_hold_forced',
            'ticket_time_limit' => now()->addHours(24),
        ]);
        return;
    }

    $booking->update(['booking_status' => 'gds_failed_requires_refund']);
}
}

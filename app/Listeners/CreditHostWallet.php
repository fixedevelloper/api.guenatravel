<?php

namespace App\Listeners;
use App\Events\BookingConfirmed;
use App\Services\FinanceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CreditHostWallet implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Le nombre de fois que le job peut être réessayé en cas d'échec (Fintech Security).
     */
    public $tries = 5;

    /**
     * Le temps d'attente (en secondes) entre chaque tentative.
     */
    public $backoff = 10;

    /**
     * Crée une nouvelle instance du listener en injectant le service financier.
     */
    public function __construct(
        protected FinanceService $financeService
    ) {}

/**
 * Traite l'événement de confirmation de réservation.
 */
public function handle(BookingConfirmed $event): void
{
    $booking = $event->booking;

    // Sécurité : On s'assure que la réservation est bien confirmée et payée
    if ($booking->status !== 'confirmed' || $booking->payment_status !== 'paid') {
        Log::warning("Tentative avortée de crédit de portefeuille : La réservation {$booking->booking_reference} n'est pas confirmée/payée.");
        return;
    }

    // Charger la relation de paiement si elle est absente
    $booking->loadMissing('payments');

    // Récupérer le paiement valide le plus récent
    $payment = $booking->payments()->where('status', 'succeeded')->latest()->first();

    if (!$payment) {
        Log::error("Impossible de créditer l'hôte : Aucun paiement réussi trouvé pour la réservation {$booking->booking_reference}.");
        return;
    }

    try {
        // Appel au service financier pour exécuter l'incrémentation sécurisée
        $this->financeService->creditHostAfterPayment($payment);

        Log::info("Portefeuille de l'hôte crédité avec succès suite au paiement de la réservation : {$booking->booking_reference}");

    } catch (\Exception $e) {
        Log::error("Échec lors du virement virtuel sur le portefeuille pour la réservation {$booking->booking_reference} : " . $e->getMessage());

        // En cas de blocage de ligne (Lock Concurrency), on remet le job en file d'attente
        $this->release($this->backoff);
    }
}
}

<?php

namespace App\Listeners;

use App\Events\BookingConfirmed; // Ajustez le namespace selon votre classe BookingConfirmed
use App\Models\Commission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class CreateCommissionRecord implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Le nombre de fois que le job peut être réessayé en cas d'échec.
     */
    public $tries = 3;

    /**
     * Le nombre de secondes à attendre avant de réessayer le job.
     */
    public $backoff = 15;

    /**
     * Crée une nouvelle instance du listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Traite l'événement de confirmation de réservation.
     */
    public function handle(BookingConfirmed $event): void
    {
        $booking = $event->booking;

        // On charge les relations nécessaires si elles ne le sont pas déjà
        $booking->loadMissing('items.room.property');

        try {
            foreach ($booking->items as $item) {
                // Calcul de la base de calcul (Prix total des nuitées pour cet item)
                $baseAmount = $item->quantity_ordered * array_sum(array_column($item->nightly_prices, 'price'));

                // Génération de la ligne de commission B2B
                Commission::create([
                    'booking_id' => $booking->id,
                    'property_id' => $item->room->property_id,
                    'base_amount' => $baseAmount,
                    'rate_applied' => $item->commission_rate_applied,
                    'commission_amount' => $item->commission_amount,
                    'status' => 'calculated', // Enregistré, en attente de facturation en fin de mois
                    'processed_at' => now(),
                ]);
            }

            Log::info("Commissions enregistrées avec succès pour la réservation : {$booking->booking_reference}");

        } catch (\Exception $e) {
            Log::error("Échec de la création des commissions pour la réservation {$booking->booking_reference} : " . $e->getMessage());

            // Permet de replacer le job dans la file d'attente pour un nouvel essai
            $this->release($this->backoff);
        }
    }
}

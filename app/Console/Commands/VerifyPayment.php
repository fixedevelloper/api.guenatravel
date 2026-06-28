<?php

namespace App\Console\Commands;

use App\Models\HotelBooking;
use App\Jobs\ProcessHotelBooking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyPayment extends Command
{
    // Signature à appeler via le planificateur (Ex: php artisan booking:verify-payment)
    protected $signature = 'booking:verify-payment';
    protected $description = 'Vérifie le statut des paiements Stripe/PayPal et distribue les réservations d\'hôtels';

    public function handle()
    {
        // 1. Récupérer les réservations bloquées à l'étape du paiement
        $pendingBookings = HotelBooking::where('status', 'PENDING_PAYMENT')->get();

        foreach ($pendingBookings as $booking) {

            // 2. Simuler ou appeler la passerelle de paiement (Stripe API / BDD logs)
            // $isPaid = $paymentGateway->checkStatus($booking->reference_num);
            $isPaid = true; // Pour l'exemple

            if ($isPaid) {
                // Mettre à jour le statut du paiement en local d'abord
                $booking->update(['status' => 'PENDING']);

                // 3. ENVOI AU JOB : On pousse la tâche dans la Queue d'arrière-plan
                ProcessHotelBooking::dispatch($booking);

                $this->info("Paiement validé pour le Booking #{$booking->id}. Job de réservation envoyé à la Queue.");
            }
        }
    }
}

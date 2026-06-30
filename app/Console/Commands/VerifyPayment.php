<?php

namespace App\Console\Commands;

use App\Models\HotelBooking;
use App\Jobs\ProcessHotelBooking;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyPayment extends Command
{
    // Signature à appeler via le planificateur (Ex: php artisan booking:verify-payment)
    protected $signature = 'booking:verify-payment';
    protected $description = 'Vérifie le statut des paiements Stripe/PayPal et distribue les réservations d\'hôtels';

    /**
     * Exécute la commande de vérification des paiements en attente.
     */
    public function handle(): void
    {
        // 1. Récupérer uniquement les réservations bloquées à l'étape du paiement
        // On peut eager-loader la relation 'room' si elle existe pour optimiser les requêtes
        $pendingBookings = HotelBooking::where('status', 'PENDING_PAYMENT')->get();

        if ($pendingBookings->isEmpty()) {
            $this->comment("Aucune réservation en attente de paiement.");
            return;
        }

        foreach ($pendingBookings as $booking) {
            try {
                // 2. Interroger la passerelle de paiement (Stripe, Wave, Orange Money, etc.)
                // $isPaid = $paymentGateway->checkStatus($booking->reference_num);
                $isPaid = true; // Simulation pour l'exemple

                if (!$isPaid) {
                    continue; // On passe à la réservation suivante si le paiement n'est pas effectif
                }

                // 3. Sécurisation par transaction pour éviter les désynchronisations de statuts
                DB::transaction(function () use ($booking) {

                    // Étape A : On valide d'abord la réception des fonds en passant en 'PENDING'
                    $booking->update(['status' => 'PENDING']);

                    // Étape B : Normalisation propre du booléen (gère true, "true", 1, "1")
                    $payload = is_string($booking->api_request_payload)
                        ? json_decode($booking->api_request_payload)
                        : $booking->api_request_payload;

                    logger($payload['is_local']);
                  //  $isLocal = filter_var($payload->is_local ?? false, FILTER_VALIDATE_BOOLEAN);
                    $isLocal = $payload['is_local'];
                    if (!$isLocal) {
                        // CAS API EXTERNE : On pousse la tâche de réservation chez le fournisseur en arrière-plan
                        ProcessHotelBooking::dispatch($booking);
                        $this->info("Paiement validé pour le Booking externe #{$booking->id}. Envoyé à la Queue.");
                    } else {
                        // CAS LOCAL : L'inventaire nous appartient, on valide immédiatement la chambre
                        // Optionnel : Tu peux revérifier la disponibilité ou bloquer les dates ici avec $booking->room->isAvailable(...)
                        $booking->update([
                            'status'       => 'CONFIRMED',
                            'confirmed_at' => now(),
                        ]);
                        $this->info("Paiement validé pour le Booking local #{$booking->id}. Confirmé instantanément.");
                    }
                });

            } catch (\Exception $e) {
                // En cas d'erreur sur un dossier, on log et on passe au suivant sans bloquer tout le script
                Log::error("Erreur lors du traitement du paiement pour le Booking #{$booking->id}: " . $e->getMessage());
                $this->error("Erreur sur le Booking #{$booking->id}. Consultez les logs.");
            }
        }
    }
}

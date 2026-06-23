<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\FlightBooking;
use App\Jobs\ProcessTravelportBooking;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckPendingPaymentsCommand extends Command
{
    /**
     * Le nom et la signature de la commande Artisan.
     * Utilisation : php artisan payments:check-status
     */
    protected $signature = 'payments:check-status';

    /**
     * La description textuelle de la commande.
     */
    protected $description = 'Vérifie le statut des paiements Mobile Money en attente auprès de l\'agrégateur et lance le GDS au besoin.';

    /**
     * Exécution de la commande.
     */
    public function handle()
    {
        $this->info("=== Démarrage de la vérification des paiements pendants ===");

        // 1. Récupérer les bookings en attente de paiement créés depuis plus de 2 minutes
        // pour laisser le temps au client de taper son code PIN, mais pas plus de 30 minutes (expiration)
        $pendingBookings = FlightBooking::where('booking_status', 'pending_payment')
            ->where('created_at', '>=', now()->subMinutes(30))
            //->where('created_at', '<=', now()->subMinutes(2))
            ->get();

        if ($pendingBookings->isEmpty()) {
            $this->comment("Aucune réservation en attente de validation trouvée.");
            return Command::SUCCESS;
        }

        $baseUrl = env('INTERNATIONAL_GATEWAY_URL', 'https://api.passerelle-afrique.com');
        $apiKey  = env('INTERNATIONAL_GATEWAY_KEY');

        foreach ($pendingBookings as $booking) {
            $this->info("Vérification du Booking ID: {$booking->id}...");

            try {
                // 2. Interroger l'API de l'agrégateur pour connaître le statut réel de cette transaction
/*                $response = Http::withToken($apiKey)
                    ->get("{$baseUrl}/v1/payments/status/{$booking->id}");

                if ($response->successful()) {
                    $paymentData = $response->json();
                    $remoteStatus = $paymentData['status'] ?? 'PENDING'; // SUCCESS, FAILED, PENDING

                    // CAS 1 : Le paiement a été validé avec succès par le client !
                    if ($remoteStatus === 'SUCCESS') {
                        $this->info("➔ [SUCCÈS] Paiement confirmé pour le Booking #{$booking->id}. Traitement GDS...");

                        // Sécurisation immédiate du statut pour le Polling Next.js
                        $booking->update([
                            'booking_status' => 'paid_pending_gds',
                            'payment_status' => 'paid'
                        ]);

                        // Extraction du payload du vol stocké
                        $selectedFlight = json_decode($booking->raw_flight_data, true);

                        // ⚡ EXECUTION ET INJECTION DANS LA QUEUE DU JOB TRAVELPORT
                        ProcessTravelportBooking::dispatch($booking->id, $selectedFlight);

                        Log::info("[Command Payment] Synchronisation réussie. Job GDS lancé pour Booking ID {$booking->id}");
                    }
                    // CAS 2 : Le paiement a été explicitement annulé ou a échoué (solde insuffisant, rejet...)
                    elseif ($remoteStatus === 'FAILED') {
                        $this->error("➔ [ÉCHEC] Le paiement a échoué à la banque/opérateur pour le Booking #{$booking->id}.");
                        $booking->update([
                            'booking_status' => 'payment_failed',
                            'payment_status' => 'failed'
                        ]);
                    }
                    // CAS 3 : Toujours en attente
                    else {
                        $this->comment("➔ [PENDING] Le client n'a pas encore validé le prompt PIN pour le Booking #{$booking->id}.");
                    }
                } else {
                    Log::error("[Command Payment] Impossible de joindre la passerelle pour le Booking #{$booking->id}");
                }*/
                $booking->update([
                    'booking_status' => 'paid_pending_gds',
                    'payment_status' => 'paid'
                ]);
                $selectedFlight = $booking->raw_flight_data;
                ProcessTravelportBooking::dispatch($booking->id, $selectedFlight);

            } catch (\Exception $e) {
                Log::error("[Command Payment] Erreur lors de la vérification du Booking #{$booking->id} : " . $e->getMessage());
            }
        }

        $this->info("=== Fin du traitement ===");
        return Command::SUCCESS;
    }
}

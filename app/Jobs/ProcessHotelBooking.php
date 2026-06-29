<?php

namespace App\Jobs;

use App\Models\HotelBooking;
use App\Services\HotelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable; // Remplacement d'Exception par Throwable pour intercepter toutes les erreurs PHP/cURL

class ProcessHotelBooking implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $booking;

    /**
     * Le nombre de fois que le job peut être tenté.
     * @var int
     */
    public $tries = 3;

    /**
     * Le nombre de secondes à attendre avant de réessayer le job (Backoff).
     * @var int
     */
    public $backoff = 15; // Attend 15 secondes avant de retenter (évite de saturer si le réseau revient doucement)

    public function __construct(HotelBooking $booking)
    {
        $this->booking = $booking;
    }

    public function handle(HotelService $service)
    {
        // 1. Protection : Si déjà confirmé, on s'arrête
        if ($this->booking->status === 'CONFIRMED') {
            return;
        }

        // 2. Optionnel : On peut notifier le front-end qu'on est en train de traiter (PROCESSING)
        if ($this->booking->status !== 'PENDING') {
            $this->booking->update(['status' => 'PENDING']);
        }

        // On extrait le JSON de requête
        $payload = $this->booking->api_request_payload;

        // Appel à l'API via le service
        $apiResult = $service->bookHotel($payload);

        if ($apiResult['success']) {
            $this->booking->update([
                'status'                    => 'CONFIRMED',
                'reference_num'             => $apiResult['booking']['reference_num'] ?? null,
                'supplier_confirmation_num' => $apiResult['booking']['supplier_confirmation_num'] ?? null,
                'api_response'              => $apiResult['booking'],
            ]);
            Log::info("Booking {$this->booking->id} confirmé avec succès auprès de l'hôtel.");
        } else {
            // Ici, l'API a répondu mais a refusé (Ex: Carte refusée, plus de chambres). C'est un vrai FAILED commercial.
            $this->booking->update([
                'status'       => 'FAILED',
                'api_response' => $apiResult
            ]);
            Log::error("L'API de l'hôtel a refusé le Booking {$this->booking->id} : " . ($apiResult['error_message'] ?? 'Erreur inconnue'));
        }
    }

    /**
     * Gestion des échecs de niveau système / réseau (cURL errors, timeouts, crashs PHP).
     * Si l'erreur survient et qu'il reste des essais (tries), Laravel va relancer le job automatiquement.
     *
     * @param Throwable $exception
     * @return void
     */
    public function failed(Throwable $exception)
    {
        // Cette méthode n'est exécutée QUE lorsque les 3 essais ($this->tries) ont été épuisés sans succès.
        $this->booking->update([
            'status'       => 'FAILED',
            'api_response' => [
                'error'   => 'System/Network Error',
                'message' => $exception->getMessage()
            ]
        ]);

        Log::critical("Échec définitif du Job de réservation après épuisement des essais pour le Booking {$this->booking->id} : " . $exception->getMessage());
    }
}

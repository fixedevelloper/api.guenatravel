<?php

namespace App\Listeners;

use App\Events\BookingConfirmed; // Ajustez le namespace selon votre classe BookingConfirmed
use App\Mail\BookingConfirmationMail; // Votre classe Mailable Laravel
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendConfirmationEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Le nombre de fois que le job peut être réessayé en cas d'échec (ex: timeout de l'API de mail).
     */
    public $tries = 3;

    /**
     * Le nombre de secondes à attendre avant de réessayer l'envoi.
     */
    public $backoff = 30;

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

        // Chargement de la relation de l'utilisateur (le client/voyageur) si non présente
        $booking->loadMissing('guest');

        $guest = $booking->guest;

        try {
            // Envoi de l'e-mail via la façade Mail de Laravel
            Mail::to($guest->email)->send(new BookingConfirmationMail($booking));

            Log::info("E-mail de confirmation de réservation envoyé avec succès à : {$guest->email} (Réf: {$booking->booking_reference})");

        } catch (\Exception $e) {
            Log::error("Échec de l'envoi de l'e-mail de confirmation pour la réservation {$booking->booking_reference} : " . $e->getMessage());

            // Si le serveur de mail est temporairement indisponible, on remet le job en file d'attente
            $this->release($this->backoff);
        }
    }
}

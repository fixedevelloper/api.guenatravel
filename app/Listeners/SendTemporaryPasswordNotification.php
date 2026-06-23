<?php


namespace App\Listeners;

use App\Events\UserAutoRegistered;
use App\Mail\TemporaryPasswordMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendTemporaryPasswordNotification implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Traite l'événement.
     *
     * @param UserAutoRegistered $event
     * @return void
     */
    public function handle(UserAutoRegistered $event)
    {
        try {
            Mail::to($event->user->email)->send(
                new TemporaryPasswordMail($event->user, $event->temporaryPassword)
            );
        } catch (\Exception $e) {
            Log::error('Échec de l\'envoi du mail de mot de passe temporaire', [
                'user_id' => $event->user->id,
                'email'   => $event->user->email,
                'error'   => $e->getMessage()
            ]);
        }
    }
}

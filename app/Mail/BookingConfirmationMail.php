<?php

namespace App\Mail;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Crée une nouvelle instance de message.
     * En utilisant la promotion de propriété de PHP 8+, la variable $booking
     * est automatiquement disponible dans votre vue HTML sous le nom $booking.
     */
    public function __construct(
        public Booking $booking
    ) {}

/**
 * Définit l'enveloppe du message (Sujet, expéditeur optionnel, etc.).
 */
public function envelope(): Envelope
{
    return new Envelope(
        subject: 'Votre réservation est confirmée ! 🎉 (Réf : ' . $this->booking->booking_reference . ')',
        );
    }

/**
 * Définit le contenu du message (Le template HTML à charger).
 */
public function content(): Content
{
    return new Content(
        view: 'emails.bookings.confirmed', // Le chemin de votre fichier Blade
        );
    }

/**
 * Récupère les pièces jointes pour le message.
 * Useful si vous générez une facture PDF plus tard.
 *
 * @return array<int, \Illuminate\Mail\Mailables\Attachment>
 */
public function attachments(): array
{
    return [];
}
}

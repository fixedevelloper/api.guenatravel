<?php

namespace App\Events;

use App\Models\Booking;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BookingConfirmed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Crée une nouvelle instance d'événement.
     * * En utilisant la promotion de propriété de PHP 8+, la propriété $booking
     * est automatiquement déclarée et assignée en une seule ligne.
     */
    public function __construct(
        public Booking $booking
    ) {}
}

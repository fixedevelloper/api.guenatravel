<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
'booking_id',
    'room_id',
    'quantity_ordered',
    'commission_rate_applied',
    'commission_amount',
    'nightly_prices'
])]
class BookingItem extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'integer',
            'commission_rate_applied' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            // Cast automatique du JSON en tableau PHP associatif
            'nightly_prices' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * L'item appartient à une réservation parente globale.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * L'item fait référence à la chambre réservée.
     * Utilise withTrashed() car la chambre peut être en SoftDelete côté hôtel,
     * mais l'historique de l'achat doit rester accessible à l'admin et au client.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }
}

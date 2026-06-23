<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo; // <-- Ajoute cette ligne
class FlightBooking extends Model
{
    protected $fillable = [
        'session_identifier',
        'user_id',
        'pnr',
        'booking_type',
        'booking_status',
        'total_amount',
        'amount_paid',
        'raw_flight_data',
        'currency',
        'payment_method',
        'payment_status',
        'contact_email',
        'contact_phone'
    ];

    protected function casts(): array
    {
        return [
            // Gestion multilingue native (JSON)
            'raw_flight_data' => 'array',
            ];
    }
    /**
     * Récupère tous les trajets associés à cette réservation (One-Way, RT, Multi-Dest)
     */
    public function trips(): HasMany
    {
        return $this->hasMany(FlightBookingTrip::class)->orderBy('sort_order', 'asc');
    }

    /**
     * Récupère la liste des passagers enregistrés pour ce vol
     */
    public function passengers(): HasMany
    {
        return $this->hasMany(FlightPassenger::class);
    }
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

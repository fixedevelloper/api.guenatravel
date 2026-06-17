<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
'booking_id',
    'property_id',
    'base_amount',
    'rate_applied',
    'commission_amount',
    'status',
    'processed_at'
])]
class Commission extends Model
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
            'base_amount' => 'decimal:2',
            'rate_applied' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * La commission est issue d'une réservation spécifique.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * La commission est rattachée à un établissement (Property).
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes Locaux (Pour les statistiques et la facturation mensuelle)
    |--------------------------------------------------------------------------
    */

    /**
     * Scope pour récupérer les commissions en attente (avant le check-out du client).
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope pour récupérer les commissions prêtes à être facturées en fin de mois.
     */
    public function scopeReadyToInvoice($query)
    {
        return $query->where('status', 'calculated');
    }

    /**
     * Scope pour filtrer les commissions déjà collectées / encaissées par la plateforme.
     */
    public function scopePaid($query)
    {
        return $query->where('status', 'paid');
    }
}

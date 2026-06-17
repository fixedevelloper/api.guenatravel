<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
'booking_reference',
    'guest_id',
    'check_in',
    'check_out',
    'subtotal_amount',
    'tax_amount',
    'service_fee',
    'total_amount',
    'currency',
    'total_commission_amount',
    'host_payout_amount',
    'status',
    'payment_status',
    'guest_notes'
])]
class Booking extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'check_in' => 'date',
            'check_out' => 'date',
            'subtotal_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'total_commission_amount' => 'decimal:2',
            'host_payout_amount' => 'decimal:2',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * La réservation appartient à un voyageur (Guest).
     */
    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guest_id');
    }

    /**
     * Une réservation contient une ou plusieurs lignes de chambres réservées (BookingItems).
     */
    public function items(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    /**
     * Une réservation peut avoir plusieurs tentatives ou flux de paiements enregistrés.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Une réservation génère un enregistrement de commission pour la plateforme.
     */
    public function commission(): HasMany
    {
        return $this->hasMany(Commission::class);
    }
    public function review() {
        return $this->hasOne(Review::class);
    }
    /*
    |--------------------------------------------------------------------------
    | Scopes Locaux (Utiles pour les filtres rapides dans vos requêtes)
    |--------------------------------------------------------------------------
    */

    /**
     * Scope pour filtrer uniquement les réservations confirmées.
     */
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    /**
     * Scope pour filtrer les réservations entièrement payées.
     */
    public function scopePaid($query)
    {
        return $query->where('payment_status', 'paid');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers Métier
    |--------------------------------------------------------------------------
    */

    /**
     * Calcule instantanément le nombre de nuits du séjour.
     */
    public function getDurationInNightsAttribute(): int
    {
        return $this->check_in->diffInDays($this->check_out);
    }
}

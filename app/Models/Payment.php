<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
'booking_id',
    'gateway',
    'transaction_reference',
    'amount',
    'status',
    'gateway_response_raw'
])]
class Payment extends Model
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
            'amount' => 'decimal:2',
            // Cast automatique du JSON brut de la banque en tableau PHP pour le débug
            'gateway_response_raw' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * Le paiement est rattaché à une réservation spécifique.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * Un paiement peut générer des mouvements de crédit ou débit dans le portefeuille de l'hôte.
     * Relation polymorphe inversée vers la table wallet_transactions (colonne source).
     */
    public function walletTransactions(): MorphMany
    {
        return $this->morphMany(WalletTransaction::class, 'source');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes Locaux
    |--------------------------------------------------------------------------
    */

    /**
     * Scope pour filtrer uniquement les transactions réussies.
     */
    public function scopeSucceeded($query)
    {
        return $query->where('status', 'succeeded');
    }

    /**
     * Scope pour filtrer les échecs de paiement.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Vérifie instantanément si la transaction bancaire est un succès.
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'succeeded';
    }
}

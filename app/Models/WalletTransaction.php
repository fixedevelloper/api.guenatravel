<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
'user_id',
    'source_id',
    'source_type',
    'type',
    'amount',
    'currency',
    'description'
])]
class WalletTransaction extends Model
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
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * La transaction appartient à un utilisateur (L'hôte).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation polymorphe vers l'entité déclencheuse de la transaction.
     * Permet de remonter soit vers le Payment, soit vers le Withdrawal associé.
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes Locaux (Pour simplifier les calculs de bilans financiers)
    |--------------------------------------------------------------------------
    */

    /**
     * Filtre uniquement les entrées d'argent (Crédits).
     */
    public function scopeCredits($query)
    {
        return $query->where('type', 'credit');
    }

    /**
     * Filtre uniquement les sorties d'argent (Débits).
     */
    public function scopeDebits($query)
    {
        return $query->where('type', 'debit');
    }
}

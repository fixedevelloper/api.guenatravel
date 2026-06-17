<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
'reference',
    'user_id',
    'amount',
    'currency',
    'payment_method',
    'bank_details_snapshot',
    'status',
    'gateway_transaction_id',
    'admin_notes',
    'processed_at'
])]
class Withdrawal extends Model
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
            // Snapshot immuable des coordonnées bancaires ou mobiles de l'hôte
            'bank_details_snapshot' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * Le retrait appartient à l'utilisateur (Hôte) qui l'a demandé.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Un retrait engendre un enregistrement de débit dans le portefeuille de l'hôte.
     * Relation polymorphe inversée vers la table wallet_transactions (colonne source).
     */
    public function walletTransactions(): MorphMany
    {
        return $this->morphMany(WalletTransaction::class, 'source');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes Locaux (Cruciaux pour le tableau de bord d'administration)
    |--------------------------------------------------------------------------
    */

    /**
     * Filtre les demandes de retraits en attente de validation par l'admin.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Filtre les retraits validés et complétés avec succès.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Filtre les demandes rejetées ou échouées.
     */
    public function scopeRejectedOrFailed($query)
    {
        return $query->whereIn('status', ['rejected', 'failed']);
    }
}

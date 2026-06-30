<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ViewedOffer extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'session_id',
        'viewable_type',
        'viewable_id',
        'price_at_view',
        'currency',
    ];

    /**
     * Récupère le modèle parent lié (Property, Flight, Car, etc.).
     */
    public function viewable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * L'enregistrement appartient optionnellement à un utilisateur.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

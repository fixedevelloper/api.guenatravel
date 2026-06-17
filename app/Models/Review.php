<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
'booking_id',
    'user_id',
    'property_id',
    'rating',
    'cleanliness_rating',
    'location_rating',
    'value_rating',
    'comment_positive',
    'comment_negative'
])]
class Review extends Model
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
            'rating' => 'decimal:1', // Note globale (ex: 8.7)
            'cleanliness_rating' => 'integer',
            'location_rating' => 'integer',
            'value_rating' => 'integer',
        ];
    }

    /**
     * Les "Booted" et "Model Events" de Laravel.
     * Automatise le calcul de la note globale 'rating' avant d'enregistrer l'avis.
     */
    protected static function booted(): void
    {
        static::creating(function (Review $review) {
            $review->calculateGlobalRating();
        });

        static::updating(function (Review $review) {
            $review->calculateGlobalRating();
        });

        static::saved(function (Review $review) {
            $review->property->updateAverageRating();
        });

        static::deleted(function (Review $review) {
            $review->property->updateAverageRating();
        });

    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * L'avis est lié à un séjour/réservation valide.
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * L'avis a été rédigé par un utilisateur (Le client).
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * L'avis concerne un établissement spécifique.
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Logique Interne / Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Calcule automatiquement la moyenne des sous-notes pour remplir la note globale.
     */
    public function calculateGlobalRating(): void
    {
        $ratings = array_filter([
            $this->cleanliness_rating,
            $this->location_rating,
            $this->value_rating,
        ]);

        if (count($ratings) > 0) {
            // Moyenne arrondie à 1 chiffre après la virgule
            $this->rating = round(array_sum($ratings) / count($ratings), 1);
        }
    }
}

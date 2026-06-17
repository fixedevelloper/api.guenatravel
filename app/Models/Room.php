<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

#[Fillable([
'property_id',
    'name',
    'description',
    'base_occupancy',
    'max_occupancy',
    'max_children',
    'total_inventory','bed_type', 'bed_quantity',
    'default_price_per_night',
   'superficie', 'is_active', 'is_smooking'
])]
class Room extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Gestion multilingue native (JSON)
            'name' => 'array',
            'description' => 'array',
            'bed_type' => 'array',
            // Casts numériques et booléens
            'base_occupancy' => 'integer',
            'max_occupancy' => 'integer',
            'max_children' => 'integer',
            'total_inventory' => 'integer',
            'default_price_per_night' => 'decimal:2',

            'is_active' => 'boolean',
            'is_smooking' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * La chambre appartient à un établissement parent (Property).
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    /**
     * La chambre possède ses propres équipements spécifiques (Amenities).
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'amenity_room');
    }

    /**
     * La chambre possède une grille de calendrier pour ses prix journaliers et blocages.
     */
    public function calendars(): HasMany
    {
        return $this->hasMany(RoomCalendar::class);
    }

    /**
     * La chambre est référencée dans plusieurs lignes de réservations.
     */
    public function bookingItems(): HasMany
    {
        return $this->hasMany(BookingItem::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Configuration Spatie Media Library (Photos des Chambres)
    |--------------------------------------------------------------------------
    */

    /**
     * Définit les collections d'images propres à la chambre (ex: vue de la pièce, salle de bain).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('room_photos')
            ->useFallbackUrl('/images/placeholder-room.jpg');
    }

    /**
     * Génère automatiquement des miniatures (Conversions) lors du téléversement d'une image.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumbnail')
            ->width(400)
            ->height(300)
            ->sharpen(10)
            ->nonQueued();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes Locaux
    |--------------------------------------------------------------------------
    */

    /**
     * Filtre uniquement les chambres actives et prêtes à la vente.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
    /**
     * Scope pour filtrer les chambres ayant des offres actives.
     * Cette requête vérifie s'il existe des enregistrements dans RoomCalendar
     * où une remise (discount) est appliquée et valide aujourd'hui.
     */
    public function scopeWithActiveOffers($query)
    {
        return $query->whereHas('calendars', function ($q) {
            $q->where('is_active', true)
                ->where('price_actual', '>', 0)
                ->whereDate('date', '>=', now()); // On ne prend que les offres futures ou en cours
        });
    }

    /**
     * Vérifie la disponibilité réelle en consultant le calendrier.
     * @param string $startDate
     * @param string $endDate
     * @return bool
     */
    public function isAvailable(string $startDate, string $endDate): bool
    {
        return !$this->calendars()
            ->whereBetween('date', [$startDate, $endDate])
            ->where('is_blocked', true) // Supposons une colonne 'is_blocked' dans RoomCalendar
            ->exists();
    }
    /**
     * Récupère le prix pour une date spécifique.
     */
    public function getPriceForDate(string $date): float
    {
        $calendarDay = $this->calendars()->where('date', $date)->first();

        return $calendarDay
            ? (float) $calendarDay->price_actual
            : (float) $this->default_price_per_night;
    }
}

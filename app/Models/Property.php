<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Translatable\HasTranslations;

#[Fillable([
'host_id',
    'type',
    'name',
    'description',
    'address_line_1',
    'address_line_2',
    'city',
    'state_province',
    'postal_code',
    'country_code',
    'latitude',
    'longitude',
    'commission_rate',
    'check_in_after',
    'check_out_before',
    'cancellation_policy',
    'is_active'
])]
class Property extends Model implements HasMedia
{
    use HasFactory, SoftDeletes, InteractsWithMedia,HasUuid,HasTranslations;

    protected $translatable = [
        'name',
        'description',
        'cancellation_policy'
    ];
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Casts JSON automatiques pour la gestion multilingue (Spatie Translatable ou natif)
            'name' => 'array',
            'description' => 'array',
            'cancellation_policy' => 'array',

            // Casts géographiques et financiers
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'commission_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
    /**
     * Accesseur pour le prix minimum des chambres.
     */
    protected function minPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->rooms()->min('default_price_per_night') ?? 0
    );
}

    /**
     * Accesseur pour le prix maximum des chambres.
     */
    protected function maxPrice(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->rooms()->max('default_price_per_night') ?? 0
    );
}
    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * L'établissement appartient à un hôte (User).
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /**
     * L'établissement possède plusieurs types de chambres.
     */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /**
     * L'établissement partage plusieurs équipements (Amenities).
     */
    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'amenity_property');
    }

    /**
     * L'établissement possède un historique de commissions générées.
     */
    public function commissions(): HasMany
    {
        return $this->hasMany(Commission::class);
    }

    /**
     * L'établissement regroupe tous les avis (Reviews) laissés par les clients.
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
    /**
     * Met à jour la note moyenne de la propriété.
     */
    public function updateAverageRating(): void
    {
        // Calcul de la moyenne des notes globales de tous les avis liés
        $average = $this->reviews()->avg('rating');

        // Mise à jour de la propriété
        $this->update(['average_rating' => round($average, 1)]);
    }
    /*
    |--------------------------------------------------------------------------
    | Configuration Spatie Media Library (Photos & Galeries de hotel)
    |--------------------------------------------------------------------------
    */

    /**
     * Définit les collections d'images pour l'établissement (ex: photos principales, galeries).
     */
    public function registerMediaCollections(): void
    {
        // Image de couverture principale de l'hôtel
        $this->addMediaCollection('cover')
            ->singleFile()
            ->useFallbackUrl('/images/placeholder-property.jpg');

        // Galerie de photos secondaires
        $this->addMediaCollection('gallery');
    }

    /**
     * Génère automatiquement des miniatures (Conversions) lors du téléversement d'une image.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        // Version optimisée pour les grilles de recherche (Cards UI)
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
     * Filtre uniquement les établissements visibles en ligne.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeActiveOffers($query)
    {
        return $query->where('is_active', true)
            ->whereNotNull('discount_price')
            ->where('discount_price', '<', DB::raw('base_price'));
    }
}

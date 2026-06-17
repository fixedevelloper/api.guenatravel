<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
'slug',
    'icon',
    'name',
    'category'
])]
class Amenity extends Model
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
            // Cast JSON natif pour stocker les traductions de l'équipement
            'name' => 'array',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * Les établissements qui possèdent cet équipement.
     */
    public function properties(): BelongsToMany
    {
        return $this->belongsToMany(Property::class, 'amenity_property');
    }

    /**
     * Les chambres qui possèdent cet équipement.
     */
    public function rooms(): BelongsToMany
    {
        return $this->belongsToMany(Room::class, 'amenity_room');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes Locaux (Utiles pour l'affichage sectorisé dans l'UI)
    |--------------------------------------------------------------------------
    */

    /**
     * Filtre uniquement les équipements applicables aux établissements (ex: Parking, Piscine).
     */
    public function scopeForProperties($query)
    {
        return $query->where('category', 'property');
    }

    /**
     * Filtre uniquement les équipements applicables aux chambres (ex: Lit bébé, Mini-bar).
     */
    public function scopeForRooms($query)
    {
        return $query->where('category', 'room');
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    /**
     * Transforme les données d'une chambre en format JSON strict.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // On récupère la langue actuelle de l'application (ex: 'fr' ou 'en')
        $locale = app()->getLocale();

        return [
            // Pour le cas local, ces IDs d'API externes n'existent pas sur le modèle Room,
            // on renvoie l'ID de la chambre ou des valeurs fallback pour TypeScript
            'product_id'          => (string) ($this->product_id ?? $this->id),
            'token_id'            => (string) ($this->token_id ?? 'local_token'),

            // CORRECTION : Extraction de la bonne chaîne multilingue selon la locale
            'room_type'           => is_array($this->name)
                ? ($this->name[$locale] ?? $this->name['en'] ?? '')
                : (string) $this->name,

            'description'         => is_array($this->description)
                ? ($this->description[$locale] ?? $this->description['en'] ?? '')
                : (string) $this->description,

            'room_code'           => (string) ($this->room_code ?? 'ROOM-' . $this->id),
            'fare_type'           => (string) ($this->fare_type ?? 'STANDARD'),
            'rate_basis_id'       => (string) ($this->rate_basis_id ?? ''),
            'currency'            => (string) ($this->currency ?? 'XAF'),
            'net_price'           => (float) $this->default_price_per_night,
            'board_type'          => (string) ($this->board_type ?? 'RO'), // Room Only par défaut
            'max_occupancy'       => (int) $this->max_occupancy,
            'inventory_type'      => (string) ($this->inventory_type ?? 'HOTEL'),
            'cancellation_policy' => (array) ($this->cancellation_policy ?? ['Annulation gratuite disponible']),

            'room_images'         => $this->relationLoaded('media')
                ? $this->getMedia('room_photos')->map(function ($media) {
                    return $media->getUrl();
                })->toArray()
                : [],

            // CORRECTION : Extraction du nom traduit des équipements si 'name' est aussi un array
            'facilities' => $this->relationLoaded('amenities')
                ? $this->amenities->map(function ($amenity) use ($locale) {
                    return is_array($amenity->name)
                        ? ($amenity->name[$locale] ?? $amenity->name['fr'] ?? '')
                        : $amenity->name;
                })->toArray()
                : [],
        ];
    }
}

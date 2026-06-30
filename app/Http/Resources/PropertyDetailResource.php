<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read string $hotel_id
 * @property-read string $name
 * @property-read string $address
 * @property-read string $city
 * @property-read string|null $postal_code
 * @property-read float $latitude
 * @property-read float $longitude
 * @property-read int $rating
 * @property-read string|null $description
 * @property-read array $facilities
 * @property-read mixed $images
 */
class PropertyDetailResource extends JsonResource
{
    /**
     * Transforme les détails de l'établissement en un format JSON strict.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'hotel_id'    => (string) $this->hotel_id,
            'name'        => (string) $this->name,
            'address'     => (string) $this->address,
            'city'        => (string) $this->city,
            'postal_code' => $this->postal_code,
            'latitude'    => (float) $this->latitude,
            'longitude'   => (float) $this->longitude,
            'rating'      => (int) $this->rating,
            'description' => $this->description,
            'facilities'  => (array) ($this->facilities ?? []),
            'images'      => collect($this->images ?? [])->map(function ($image) {

                return [
                    'url'   => (string) ($image['url'] ?? $image->url ?? ''),
                    'caption' => (string) ($image['title'] ?? $image->title ?? ''),
                ];
            })->toArray(),
        ];
    }
}

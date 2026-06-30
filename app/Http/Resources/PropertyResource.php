<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read string $hotel_id
 * @property-read string $twx_hotel_id
 * @property-read string $product_id
 * @property-read string $token_id
 * @property-read string $name
 * @property-read int $rating
 * @property-read string $property_type
 * @property-read string $fare_type
 * @property-read float $total
 * @property-read string $currency
 * @property-read string $city
 * @property-read string $locality
 * @property-read string $country
 * @property-read string $address
 * @property-read string|null $postal_code
 * @property-read string|null $phone
 * @property-read string|null $email
 * @property-read float $latitude
 * @property-read float $longitude
 * @property-read array|object $distance
 * @property-read string|null $thumbnail
 * @property-read array $facilities
 * @property-read array|object $trip_advisor
 */
class PropertyResource extends JsonResource
{
    /**
     * Transforme la ressource en tableau optimisé pour le front-end TypeScript.
     *
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'hotel_id'      => (string) $this->id,
            'twx_hotel_id'  => (string) $this->twx_hotel_id,
            'product_id'    => (string) $this->uuid,
            'token_id'      => (string) $this->token_id,
            'name'          => (string) $this->name,
            'rating'        => (int) $this->rating,
            'property_type' => (string) $this->type,
            'fare_type'     => (string) $this->fare_type,
            'total'         => (float) $this->minPrice,
            'currency'      => (string) $this->currency,
            'city'          => (string) $this->city,
            'locality'      => (string) $this->locality,
            'country'       => (string) $this->country,
            'address'       => (string) $this->address,
            'postal_code'   => $this->postal_code,
            'phone'         => $this->phone,
            'email'         => $this->email,
            'latitude'      => (float) $this->latitude,
            'longitude'     => (float) $this->longitude,
            'distance'      => [
                'value' => (float) ($this->distance['value'] ?? $this->distance->value ?? 0),
                'unit'  => (string) ($this->distance['unit'] ?? $this->distance->unit ?? 'km'),
            ],
            'thumbnail'     => $this->getFirstMediaUrl('cover', 'thumbnail'),
          'facilities' => $this->relationLoaded('amenities')
        ? $this->amenities->pluck('name')->toArray()
        : [],
            'trip_advisor'  => [
                'rating'  => $this->trip_advisor['rating'] ?? $this->trip_advisor->rating ?? null,
                'reviews' => $this->trip_advisor['reviews'] ?? $this->trip_advisor->reviews ?? null,
            ],
        ];
    }
}

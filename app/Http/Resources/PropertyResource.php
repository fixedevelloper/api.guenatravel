<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PropertyResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * @param Request $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'type' => $this->type,
            'name' => $this->getTranslations('name'),
            'description' => $this->getTranslations('description'),
            'cancellation_policy' => $this->getTranslations('cancellation_policy'),
            'location' => [
                'address_line_1' => $this->address_line_1,
                'city' => $this->city,
                'postal_code'=>$this->postal_code,
                'state_province'=>$this->state_province,
                'country_code' => $this->country_code,
                'coordinates' => [
                    'lat' => (float) $this->latitude,
                    'lng' => (float) $this->longitude,
                ]
            ],
            'price_range' => [
                'min' => $this->minPrice ?? 0,
                'max' => $this->maxPrice ?? 0,
            ],
            'media' => [
                'cover' => $this->getFirstMediaUrl('cover', 'thumbnail'),
                'gallery' => $this->getMedia('gallery')->map(fn($media) => $media->getUrl()),
            ],
            'rooms_count' => $this->whenCounted('rooms'),
            'amenities' => AmenityResource::collection($this->whenLoaded('amenities')),
            'rooms' => RoomResource::collection($this->whenLoaded('rooms')),
            'created_at' => $this->created_at->toDateTimeString(),
        ];
    }

    /**
     * Ajouter des données supplémentaires à la réponse de la collection.
     * Ces données n'apparaissent qu'au premier niveau du JSON.
     */
    public function with(Request $request): array
    {
        return [
            'success' => true,
        ];
    }
}

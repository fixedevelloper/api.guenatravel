<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Récupération de la locale courante (ex: 'fr' ou 'en')
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'property_id' => $this->property_id,

            // Gestion de la traduction : Clé localisée ou fallback sur la valeur brute
            'name' => $this->name,
            'description' => $this->description,

            // Si vous avez besoin de récupérer l'intégralité des traductions (ex: pour un formulaire d'édition)
            'translations' => [
                'name' => $this->name,
                'description' => $this->description,
            ],

            // Capacités et inventaire
            'base_occupancy' => $this->base_occupancy,
            'max_occupancy' => $this->max_occupancy,
            'max_children' => $this->max_children,
            'total_inventory' => $this->total_inventory,

            // Tarification (formaté proprement pour l'affichage/API)
            'default_price_per_night' => (float) $this->default_price_per_night,
            'is_active' => $this->is_active,

            // Relations chargées de manière conditionnelle (optimisation des requêtes)
            'property' => new PropertyResource($this->whenLoaded('property')),
            'amenities' => AmenityResource::collection($this->whenLoaded('amenities')),
            'calendars' => RoomCalendarResource::collection($this->whenLoaded('calendars')),

            // Gestion des médias avec Spatie MediaLibrary
            'images' => $this->when($this->relationLoaded('media'), function () {
                return $this->getMedia('room_photos')->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'url' => $media->getUrl(),
                        'thumbnail' => $media->getUrl('thumbnail'),
                        'mime_type' => $media->mime_type,
                        'size' => $media->size,
                    ];
                });
            }),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

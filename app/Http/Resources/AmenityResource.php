<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AmenityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name, // Retourne le tableau JSON multilingue {fr, en}
            'icon' => $this->icon, // Nom de l'icône (ex: "wifi", "pool")
            'category' => $this->category, // 'property' ou 'room'
        ];
    }
}

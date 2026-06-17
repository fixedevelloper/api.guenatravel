<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoomCalendarResource extends JsonResource
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
            'room_id' => $this->room_id,

            // Format standard ISO pour les dates en API YYYY-MM-DD
            'date' => $this->date?->format('Y-m-d'),

            // Tarification dynamique pour ce jour précis
            'price_actual' => (float) $this->price_actual,

            // État des réservations et blocages administratifs
            'rooms_booked' => $this->rooms_booked,
            'is_blocked' => $this->is_blocked,

            // Métriques métiers calculées (Disponibles si la relation 'room' est chargée)
            'inventory' => $this->when($this->relationLoaded('room'), function () {
        return [
            'total_capacity' => $this->room->total_inventory,
            'remaining' => $this->remainingInventory(),
            'has_vacancy' => $this->hasVacancy(),
        ];
    }),

            // Relation parente conditionnelle (évite les boucles infinies de ressources)
            'room' => new RoomResource($this->whenLoaded('room')),

            // Timestamps utiles pour le cache ou le debugging
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'check_in' => $this->check_in,
            'check_out' => $this->check_out,
            'total_price' => $this->total_amount,
            // Accès aux relations chargées avec 'with()'
            'property_name' => $this->items->first()->room->property->name ?? 'N/A',
            'property_address' => $this->items->first()->room->property->city ?? 'N/A',
            'room_names' => $this->items->map(fn($item) => $item->room->name)->implode(', '),
            'property_image' => $this->items->first()->room->property->image_url ?? null,
        ];
    }
}

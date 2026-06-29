<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerBookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Extraction optionnelle du premier passager adulte ou d'un nom de chambre depuis pax_details ou api_request_payload
        $roomNames = collect($this->pax_details ?? [])->map(function($room) {
            return "Chambre " . ($room['room_no'] ?? 1);
        })->implode(', ');

        return [
            'id'               => $this->id,
            'reference'        => $this->reference_num ?? 'En attente',
            'supplier_confirmation_num'        => $this->supplier_confirmation_num ?? 'En attente',
            'property_name'    => $this->api_request_payload['hotel_name'] ?? 'Établissement hôtelier', // Fallback si non stocké en colonne directe
            'property_image'   => $this->api_request_payload['hotel_main_image'] ?? 'https://images.unsplash.com/photo-1566073771259-6a8506099945?q=80&w=600', // Image par défaut si manquante
            'property_address' => $this->api_request_payload['hotel_address'] ?? $this->hotel_id,
            'room_names'       => $roomNames ?: 'Chambre Standard',
            'check_in'         => $this->check_in->format('Y-m-d'),
            'check_out'        => $this->check_out->format('Y-m-d'),
            'total_price'      => (float) $this->net_price,
            'currency'         => $this->currency ?? 'XAF',
            'status'           => $this->status, // Renvoie CONFIRMED, PENDING_PAYMENT, PROCESSING, FAILED, etc.
            'rating'           => $this->api_request_payload['hotel_stars'] ?? null,
            'host_phone'       => $this->customer_phone, // Numéro de l'assistance ou de l'hôte
        ];
    }
}

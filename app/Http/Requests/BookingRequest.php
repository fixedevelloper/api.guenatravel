<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BookingRequest extends FormRequest
{
    /**
     * Autorise uniquement les utilisateurs authentifiés.
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Règles de validation pour le processus de réservation.
     */
    public function rules(): array
    {
        return [
            // Dates de séjour au format ISO
            'check_in'  => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'check_out' => ['required', 'date_format:Y-m-d', 'after:check_in'],

            // Détails des occupants
            'adults'   => ['required', 'integer', 'min:1'],
            'children' => ['required', 'integer', 'min:0'],

            // Liste des chambres (supporte une ou plusieurs réservations)
            'rooms'            => ['required', 'array', 'min:1'],
            'rooms.*.room_id'  => ['required', 'integer', 'exists:rooms,id'],
            'rooms.*.quantity' => ['required', 'integer', 'min:1'],

            // Options complémentaires
            'currency'    => ['sometimes', 'string', 'size:3'],
            'guest_notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Messages d'erreur personnalisés.
     */
    public function messages(): array
    {
        return [
            'check_in.required'       => 'La date d\'arrivée est requise.',
            'check_in.after_or_equal' => 'La date d\'arrivée ne peut pas être dans le passé.',
            'check_out.after'         => 'La date de départ doit être postérieure à la date d\'arrivée.',
            'rooms.required'          => 'Veuillez sélectionner au moins une chambre.',
            'rooms.*.room_id.exists'  => 'La chambre sélectionnée est invalide.',
            'currency.size'           => 'La devise doit être un code ISO de 3 lettres (ex: XAF).',
            'guest_notes.max'         => 'La note ne peut excéder 1000 caractères.',
        ];
    }
}

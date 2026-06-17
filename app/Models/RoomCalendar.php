<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
'room_id',
    'date',
    'price_actual',
    'rooms_booked',
    'is_blocked'
])]
class RoomCalendar extends Model
{
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'price_actual' => 'decimal:2',
            'rooms_booked' => 'integer',
            'is_blocked' => 'boolean',
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Relations Eloquent
    |--------------------------------------------------------------------------
    */

    /**
     * Le jour de calendrier appartient à une chambre spécifique.
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes Locaux (Cruciaux pour l'algorithme de recherche de dispo)
    |--------------------------------------------------------------------------
    */

    /**
     * Scope pour filtrer les jours ouverts à la réservation (non bloqués manuellement).
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_blocked', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers Métier (Vérifications d'inventaire en temps réel)
    |--------------------------------------------------------------------------
    */

    /**
     * Vérifie s'il reste des chambres physiques disponibles à cette date exacte.
     * Compare le nombre de réservations enregistrées à l'inventaire total de la chambre.
     */
    public function hasVacancy(): bool
    {
        if ($this->is_blocked) {
            return false;
        }

        return $this->rooms_booked < $this->room->total_inventory;
    }

    /**
     * Calcule le nombre de places restantes pour cette journée.
     */
    public function remainingInventory(): int
    {
        if ($this->is_blocked) {
            return 0;
        }

        return max(0, $this->room->total_inventory - $this->rooms_booked);
    }
}

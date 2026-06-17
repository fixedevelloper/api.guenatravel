<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCalendar;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SearchService
{
    /**
     * Moteur de recherche principal pour les établissements disponibles.
     *
     * @param array $filters ['city', 'check_in', 'check_out', 'guests', 'amenities']
     * @return \Illuminate\Pagination\LengthAwarePaginator
     */
    public function searchProperties(array $filters, int $perPage = 15)
    {
        $query = Property::with(['rooms', 'amenities', 'media'])
            ->withMin('rooms', 'default_price_per_night') // Ajoute une colonne 'rooms_min_base_price'
            ->withMax('rooms', 'default_price_per_night') // Ajoute une colonne 'rooms_max_base_price'
            ->active(); // Uniquement les hôtels en ligne

        // 1. Filtrage géographique par ville
        if (!empty($filters['city'])) {
            $query->where('city', 'LIKE', '%' . $filters['city'] . '%');
        }

        // 2. Filtrage par capacité et disponibilité des dates
        if (!empty($filters['check_in']) && !empty($filters['check_out'])) {
            $checkIn = Carbon::parse($filters['check_in']);
            $checkOut = Carbon::parse($filters['check_out']);
            $guests = (int) ($filters['guests'] ?? 1);

            // Étape critique : Trouver les IDs des chambres indisponibles ou complètes
            $unavailableRoomIds = $this->getUnavailableRoomIds($checkIn, $checkOut, $guests);

            // Filtrer les établissements qui possèdent au moins une chambre disponible
            $query->whereHas('rooms', function (Builder $roomQuery) use ($unavailableRoomIds, $guests) {
                $roomQuery->active()
                    ->whereNotIn('id', $unavailableRoomIds)
                    ->where('max_occupancy', '>=', $guests); // La chambre doit pouvoir accueillir le groupe
            });

            // Injection dynamique des prix calculés pour la période dans la collection finale
            return $query->paginate($perPage)->through(function (Property $property) use ($checkIn, $checkOut) {
                // On attache le prix minimum trouvé pour cet établissement sur ces dates
                $property->computed_min_price = $this->calculateCheapestRoomPriceForPeriod($property, $checkIn, $checkOut);
                return $property;
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * Algorithme Anti-Overbooking : Identifie les chambres indisponibles.
     * Retourne un tableau d'IDs de chambres qui n'ont plus de stock sur au moins une nuit.
     * @param Carbon $checkIn
     * @param Carbon $checkOut
     * @param int $guests
     * @return array
     */
    protected function getUnavailableRoomIds(Carbon $checkIn, Carbon $checkOut, int $guests): array
    {
        $totalNights = $checkIn->diffInDays($checkOut);

        // Requête sur la table RoomCalendar pour trouver les anomalies de stock
        return RoomCalendar::select('room_id')
            ->whereBetween('date', [$checkIn->format('Y-m-d'), $checkOut->copy()->subDay()->format('Y-m-d')])
            ->where(function ($query) {
                // Une chambre est indisponible si elle est bloquée manuellement OU si elle est complète
                $query->where('is_blocked', true)
                    ->orWhereHas('room', function ($roomQuery) {
                        // Vérification dynamique : stock occupé >= stock total disponible
                        $roomQuery->whereRaw('room_calendars.rooms_booked >= rooms.total_inventory');
                    });
            })
            ->groupBy('room_id')
            // Si le nombre de lignes retournées pour une chambre est inférieur au nombre de nuits demandées,
            // cela signifie que sur certaines nuits elle n'a pas de calendrier (donc elle utilise son stock de base intact).
            // Mais si elle apparaît ici, c'est qu'elle a échoué à la règle sur au moins une nuit de la période.
            ->pluck('room_id')
            ->toArray();
    }

    /**
     * Calcule le prix total ou minimum du séjour pour l'affichage de l'établissement.
     * @param Property $property
     * @param Carbon $checkIn
     * @param Carbon $checkOut
     * @return float
     */
    protected function calculateCheapestRoomPriceForPeriod(Property $property, Carbon $checkIn, Carbon $checkOut): float
    {
        $cheapestTotal = PHP_FLOAT_MAX;
        $totalNights = $checkIn->diffInDays($checkOut);

        foreach ($property->rooms as $room) {
            if (!$room->is_active) continue;

            $roomTotal = 0;

            for ($i = 0; $i < $totalNights; $i++) {
                $dateString = $checkIn->copy()->addDays($i)->format('Y-m-d');

                // Récupération du prix journalier (dynamique ou prix par défaut de la chambre)
                $calendar = $room->calendars->firstWhere('date', $dateString);
                $roomTotal += $calendar ? (float) $calendar->price_actual : (float) $room->default_price_per_night;
            }

            if ($roomTotal < $cheapestTotal && $roomTotal > 0) {
                $cheapestTotal = $roomTotal;
            }
        }

        return $cheapestTotal === PHP_FLOAT_MAX ? 0.00 : $cheapestTotal;
    }
}

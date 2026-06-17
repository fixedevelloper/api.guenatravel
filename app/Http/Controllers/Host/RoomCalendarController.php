<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Models\Room;
use App\Models\RoomCalendar;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoomCalendarController extends Controller
{
    public function index(Room $room)
    {
        // Retourne les entrées calendrier pour cette chambre
        return response()->json($room->calendars()->get());
    }
    public function bulkUpdate(Request $request, Room $room)
    {
        $validated = $request->validate([
            'dates' => 'required|array',
            'dates.*' => 'date_format:Y-m-d',
            'price' => 'nullable|numeric|min:0',
            'is_blocked' => 'boolean',
        ]);

        $isBlocked = $request->is_blocked ?? false;

        // Utilisation d'une transaction pour garantir l'intégrité des données
        return DB::transaction(function () use ($request, $room, $validated, $isBlocked) {

            foreach ($validated['dates'] as $date) {

                // Si on tente de bloquer, on vérifie si une réservation active existe
                if ($isBlocked) {
                    $hasBooking = Booking::confirmed()
                        ->whereHas('items', function ($query) use ($room, $date) {
                            $query->where('room_id', $room->id)
                                ->where('check_in', '<=', $date) // Note : vérifier si check_in/out est sur Booking
                                ->where('check_out', '>', $date);
                        })
                        ->exists();

                    if ($hasBooking) {
                        return response()->json([
                            'message' => "Impossible de bloquer le {$date} : une réservation est active."
                        ], 422);
                    }
                }

                // Mise à jour ou création de l'entrée calendrier
                RoomCalendar::updateOrCreate(
                    [
                        'room_id' => $room->id,
                        'date' => $date
                    ],
                    [
                        'price_actual' => $request->price ?? $room->default_price_per_night,
                        'is_blocked' => $isBlocked
                    ]
                );
            }

            return response()->json(['message' => 'Calendrier mis à jour avec succès.']);
        });
    }
}

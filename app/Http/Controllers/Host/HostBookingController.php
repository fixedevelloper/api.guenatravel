<?php


namespace App\Http\Controllers\Host;


use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

class HostBookingController extends Controller
{
    public function index(Request $request)
    {
        $host = $request->user();

        // 1. Filtrer les établissements de l'hôte
        $propertyIds = DB::table('properties')
            ->where('host_id', $host->id)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        if (empty($propertyIds)) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }

        // 2. Extraire toutes les réservations attachées
        $bookings = Booking::with([
            'guest:id,name,email,phone_number',
            'items.room.property'
        ])
            ->whereHas('items.room', function ($query) use ($propertyIds) {
                $query->whereIn('property_id', $propertyIds);
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json([
            'success' => true,
            'data' => $bookings
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|in:confirmed,cancelled'
        ]);

        $host = $request->user();

        // Protection : S'assurer que la réservation modifiée cible bien une chambre de l'hôte
        $booking = Booking::whereHas('items.room.property', function ($query) use ($host) {
            $query->where('host_id', $host->id);
        })->findOrFail($id);

        $booking->update([
            'status' => $request->status
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Statut mis à jour.',
            'data' => $booking
        ]);
    }
}

<?php


namespace App\Http\Controllers\Host;


use App\Http\Controllers\Controller;
use App\Models\HotelBooking;
use Illuminate\Http\Request;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

class HostBookingController extends Controller
{
    public function index(Request $request)
    {
        $host = $request->user();

        $propertyIds = DB::table('properties')
            ->where('host_id', $host->id)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        if (empty($propertyIds)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $status = $request->query('status');

        $query = HotelBooking::whereIn('hotel_id', $propertyIds)
            ->orderBy('created_at', 'desc');

        if ($status) {
            $query->where('status', $status);
        }

        $bookings = $query->paginate(15);

        // Enrichit chaque booking avec les infos property + room
        $properties = DB::table('properties')
            ->whereIn('id', $propertyIds)
            ->get(['id', 'name', 'city'])
            ->keyBy('id');

        $bookings->getCollection()->transform(function ($booking) use ($properties) {
            $property = $properties[$booking->hotel_id] ?? null;

            $booking->property_name = $property?->name;
        $booking->property_city = $property?->city;

        return $booking;
    });

        return response()->json([
            'success' => true,
            'data'    => $bookings,
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

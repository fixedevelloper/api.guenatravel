<?php


namespace App\Http\Controllers;

use App\Http\Resources\BookingResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();

        // 1. Récupération des réservations
        $bookings = $user->bookings()->with('items.room.property')->latest()->get();

        // 2. Calcul des statistiques
        $stats = [
            'total_bookings' => $bookings->count(),
            'completed_stays' => $bookings->where('status', 'confirmed')
                ->where('check_out', '<', now())
                ->count(),
            'amount_spent' => $bookings->where('status', 'confirmed')->sum('total_price'),
        ];

        // 3. Séparation upcoming vs past
        $upcoming = $bookings->where('check_out', '>=', now())
            ->values();

        $past = $bookings->where('check_out', '<', now())
            ->values();

        return response()->json([
            'status' => 'success',
            'data' => [
                'stats' => $stats,
                'upcoming_bookings' => BookingResource::collection($upcoming),
                'past_bookings' => BookingResource::collection($past),
            ]
        ]);
    }
    public function bookings(Request $request)
    {
        // On récupère l'utilisateur connecté
        $user = Auth::user();
        $bookings = $user->bookings()
            ->with(['items.room.property'])
            ->latest()
            ->paginate(10); // Pagination recommandée pour de meilleures performances

        return BookingResource::collection($bookings);
    }
}

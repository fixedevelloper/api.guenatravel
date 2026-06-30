<?php


namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FlightBooking;
use Illuminate\Http\Request;

class FlightBookingController extends Controller
{
    public function index(Request $request)
    {
        // On charge l'utilisateur, les passagers, et les trajets (trips)
        $bookings = FlightBooking::with(['user', 'passengers', 'trips'])
            ->latest()
            ->paginate(15);

        return response()->json($bookings);
    }
}

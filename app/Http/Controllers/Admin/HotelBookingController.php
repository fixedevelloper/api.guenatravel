<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HotelBooking;
use Illuminate\Http\Request;

class HotelBookingController extends Controller
{
    public function index(Request $request)
    {
        $bookings = HotelBooking::with('user')
            ->latest()
            ->paginate(15);

        return response()->json($bookings);
    }
}

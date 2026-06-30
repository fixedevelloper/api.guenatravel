<?php


namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        // Récupérer uniquement les comptes clients globaux
        $users = User::where('role', 'customer')
            ->withCount(['bookings', 'flightBookings', 'reviews'])
            ->latest()
            ->paginate(15);

        return response()->json($users);
    }
}

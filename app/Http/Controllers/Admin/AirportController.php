<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Airport;
use Illuminate\Http\Request;

class AirportController extends Controller
{
    public function index(Request $request)
    {
        $query = Airport::query();

        // Filtre par mot-clé (Recherche floue)
        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('airport_code', 'LIKE', "%{$search}%")
                    ->orWhere('airport_name', 'LIKE', "%{$search}%")
                    ->orWhere('city', 'LIKE', "%{$search}%");
            });
        }

        // Filtre strict par pays
        if ($request->filled('country')) {
            $query->where('country', $request->input('country'));
        }

        $airports = $query->orderBy('airport_code', 'asc')->paginate(15);

        return response()->json($airports);
    }
}

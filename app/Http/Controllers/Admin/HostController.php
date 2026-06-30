<?php


namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class HostController extends Controller
{
    public function index(Request $request)
    {
        // Récupérer uniquement les utilisateurs ayant le rôle d'hôte
        $hosts = User::where('role', 'host')
            ->withCount('properties') // Compte automatiquement le nombre d'établissements rattachés
            ->latest()
            ->paginate(15);

        return response()->json($hosts);
    }
}

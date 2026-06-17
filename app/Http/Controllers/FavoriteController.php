<?php


namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FavoriteController extends Controller
{
    /**
     * Liste des favoris de l'utilisateur connecté.
     */
    public function index()
    {
        $user = Auth::user();

        // On charge les propriétés avec leurs relations nécessaires pour l'affichage
        $favorites = $user->wishlist()->get();

        return response()->json([
            'status' => 'success',
            'data' => $favorites
        ]);
    }

    /**
     * Ajoute une propriété aux favoris (Toggle sécurisé).
     */
    public function store(Request $request)
    {
        $request->validate([
            'property_id' => 'required|exists:properties,id'
        ]);

        $user = Auth::user();
        $propertyId = $request->property_id;

        // syncWithoutDetaching ajoute sans créer de doublons
        $user->wishlist()->syncWithoutDetaching([$propertyId]);

        return response()->json([
            'status' => 'success',
            'message' => 'Propriété ajoutée aux favoris.'
        ]);
    }

    /**
     * Supprime une propriété des favoris.
     */
    public function destroy($propertyId)
    {
        $user = Auth::user();

        // Vérifie si la propriété existe avant de tenter le détachement
        if (!$user->wishlist()->where('property_id', $propertyId)->exists()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Cette propriété n\'est pas dans vos favoris.'
            ], 404);
        }

        $user->wishlist()->detach($propertyId);

        return response()->json([
            'status' => 'success',
            'message' => 'Propriété retirée des favoris.'
        ]);
    }
}

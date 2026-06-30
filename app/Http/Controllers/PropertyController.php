<?php

namespace App\Http\Controllers;

use App\Http\Resources\PropertyResource;
use App\Models\Property;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    /**
     * Liste paginée avec recherche flexible.
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        // 1. Utilisation de scopes pour une lecture facilitée (voir le modèle)
        $query = Property::query()
            ->active()
            ->withMin('rooms', 'default_price_per_night') // Ajoute une colonne 'rooms_min_base_price'
            ->withMax('rooms', 'default_price_per_night') // Ajoute une colonne 'rooms_max_base_price'
            ->with(['media']) // On charge les médias dès maintenant pour éviter le N+1
            ->withCount('rooms');

        // 2. Filtres dynamiques : plus besoin de if/else manuels
        $query->when($request->filled('city'), function ($q) use ($request) {
            $q->where('city', 'LIKE', '%' . $request->city . '%');
        });

        $query->when($request->filled('type'), function ($q) use ($request) {
            $q->where('type', $request->type);
        });

        return response()->json([
            'success' => true,
            'meta'    => ['total' => $query->count()],
            'data'    => PropertyResource::collection($query->latest()->paginate($request->input('per_page', 15)))
        ]);
    }

    /**
     * Récupération des meilleures offres.
     */
    public function offers(): JsonResponse
    {
        // Utilisation d'un scope dédié aux offres
        $offers = Property::active()
            ->hasDiscount()
            ->with(['media'])
            ->latest()
            ->limit(6)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => PropertyResource::collection($offers)
        ]);
    }

    /**
     * Récupération d'un établissement spécifique avec ses relations.
     * @param $id
     * @return JsonResponse
     */
    public function show($id): JsonResponse
    {
        try {
            $property = Property::where('uuid',$id)
                ->active()
                ->with(['rooms','rooms.amenities', 'amenities', 'media'])
                ->withCount('rooms')
                ->first();

            return response()->json([
                'success' => true,
                'data'    => new PropertyResource($property)
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Établissement non trouvé.'
            ], 404);
        }
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Resources\PropertyResource;
use App\Models\Airport;
use App\Services\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class SearchController extends Controller
{
    /**
     * Injection du service de recherche.
     */
    public function __construct(
        protected SearchService $searchService
    ) {}

/**
 * [PUBLIC] Rechercher des établissements disponibles selon des critères précis.
 * * URL attendue : /api/search?city=Paris&check_in=2026-07-01&check_out=2026-07-10&guests=2
 */
public function index(Request $request)
{
    // 1. Validation rapide des paramètres de requête (Query Parameters)
    $validator = Validator::make($request->all(), [
        'city'      => ['nullable', 'string', 'max:100'],
        'check_in'  => ['required_with:check_out', 'nullable', 'date', 'after_or_equal:today'],
        'check_out' => ['required_with:check_in', 'nullable', 'date', 'after:check_in'],
        'guests'    => ['nullable', 'integer', 'min:1'],
    ], [
        'check_out.after' => 'La date de départ doit être postérieure à la date d\'arrivée.',
        'check_in.after_or_equal' => 'La date d\'arrivée ne peut pas être dans le passé.',
    ]);

    if ($validator->fails()) {
        return response()->json([
            'success' => false,
            'message' => 'Critères de recherche invalides.',
            'errors'  => $validator->errors()
        ], 422);
    }

    // 2. Récupération des filtres validés
    $filters = $validator->validated();

    // Valeurs par défaut si absentes
    $filters['guests'] = (int) ($filters['guests'] ?? 1);
    $perPage = (int) $request->input('per_page', 15);

    try {
        // 3. Appel du SearchService pour le filtrage complexe et l'anti-overbooking
        $properties = $this->searchService->searchProperties($filters, $perPage);

        return PropertyResource::collection($properties);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Une erreur est survenue lors du traitement de votre recherche.',
            'error'   => $e->getMessage()
        ], 500);
    }
}

/**
 * Rechercher des aéroports en base de données locale.
 */
public function search(Request $request): JsonResponse
{
    // 1. On récupère le terme recherché (ex: /api/airports/search?q=lon)
    $query = $request->query('q');

    // Si la requête est vide, on renvoie une liste vide pour éviter de charger toute la table
    if (blank($query) || strlen($query) < 2) {
        return response()->json([]);
    }

    $searchTerm = strtolower($query);

    // 2. Recherche multicritère performante sur notre table locale
    $airports = Airport::query()
        ->where('airport_code', 'LIKE', $searchTerm . '%') // Priorité aux codes commençant par la recherche (ex: LON)
        ->orWhere('city', 'LIKE', '%' . $searchTerm . '%')   // Recherche dans la ville
        ->orWhere('airport_name', 'LIKE', '%' . $searchTerm . '%') // Recherche dans le nom
        ->take(10) // On limite à 10 résultats pour une autocomplétion ultra-fluide côté Front
        ->get(['airport_code', 'airport_name', 'city', 'country']); // On ne sélectionne que les champs nécessaires

    // 3. Retour au format JSON
    return response()->json([
        'success' => true,
        'count'   => $airports->count(),
        'results' => $airports
    ]);
}
}

<?php

namespace App\Http\Controllers\Host;

use App\Http\Controllers\Controller;
use App\Http\Resources\PropertyHostResource;
use App\Http\Resources\PropertyResource;
use App\Models\Amenity;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class HostPropertyController extends Controller
{


    /**
     * [HOST] Lister tous les établissements appartenant à l'hôte connecté.
     * @param Request $request
     * @return AnonymousResourceCollection
     */
    public function index(Request $request)
    {
        $properties = Property::where('host_id', $request->user()->id)
            ->withCount('rooms')
            ->with('media')
            ->latest()
            ->paginate(10);

        // Retourne directement la collection. Laravel injectera automatiquement
        // les clés 'meta' et 'links' pour la pagination.
        return PropertyHostResource::collection($properties);
    }

    /**
     * [HOST] Créer un nouvel établissement.
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Validation stricte
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'type' => 'required|string|in:hotel,resort,villa,apartment,guest_house',
            'country_code' => 'required|string|size:2',
            'city' => 'required|string|max:100',
            'state_province' => 'required|string|max:100',
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:20',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'check_in_after' => 'required|string',
            'check_out_before' => 'required|string',
            'cancellation_policy' => 'nullable|string',
            'amenities' => 'nullable|array',
            'amenities.*' => 'string|exists:amenities,slug',
            'images' => 'required|array',
            'images.*' => 'file|image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        try {
            DB::beginTransaction();

            $locale = app()->getLocale();
            $langs = ['fr', 'en', 'es'];

            $property = new Property();
            $property->host_id = $request->user()->id;
            $property->type = $validated['type'];

            // 2. Initialisation des données multilingues (structure prête pour le futur)
            // 1. On récupère le tableau initialisé
            $names = [];
            $descriptions = [];
            $cancellationPolicies = [];

// 2. On modifie les tableaux temporaires
            foreach ($langs as $locale){
                $names[$locale] = $validated['name'];
                $descriptions[$locale] = $validated['description'];

                if (!empty($validated['cancellation_policy'])) {
                    $cancellationPolicies[$locale] = $validated['cancellation_policy'];
                }
            }


// 3. On réassigne les tableaux complets au modèle
            $property->name = $names;
            $property->description = $descriptions;
            $property->cancellation_policy = $cancellationPolicies;

            // 3. Mapping des données géographiques
            $property->fill([
                'address_line_1' => $validated['address_line_1'],
                'address_line_2' => $validated['address_line_2'] ?? null,
                'city' => $validated['city'],
                'state_province' => $validated['state_province'],
                'postal_code' => $validated['postal_code'] ?? '00000',
                'country_code' => strtoupper($validated['country_code']),
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'check_in_after' => $validated['check_in_after'],
                'check_out_before' => $validated['check_out_before'],
                'commission_rate' => 10.00,
                'is_active' => false,
            ]);

            $property->save();

            // 4. Gestion des équipements
            if (!empty($validated['amenities'])) {
                $amenityIds = Amenity::whereIn('slug', $validated['amenities'])->pluck('id');
                $property->amenities()->sync($amenityIds);
            }

            // 5. Traitement des médias via Spatie
            foreach ($request->file('images') as $index => $imageFile) {
                $mediaAdder = $property->addMedia($imageFile);

                // Première image = Cover, autres = Gallery
                $collection = ($index === 0) ? 'cover' : 'gallery';
                $mediaAdder->toMediaCollection($collection);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Établissement enregistré avec succès !',
                'property_id' => $property->id
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Erreur enregistrement Property: " . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur technique lors de la création.',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * [HOST] Afficher les détails d'un établissement spécifique.
     */
    public function show(Request $request, Property $property)
    {
        // Sécurité stricte : Vérifier la propriété de l'établissement
        if ($property->host_id !== $request->user()->id) {
            abort(403, "Vous n'êtes pas autorisé à voir cet établissement.");
        }

        // Eager loading des chambres et fichiers médias (photos de l'hôtel)
        $property->load(['rooms', 'media']);

        return new PropertyHostResource($property);
    }

    /**
     * [HOST] Mettre à jour les informations d'un établissement.
     * @param Request $request
     * @param Property $property
     * @return JsonResponse
     * @throws \Illuminate\Validation\ValidationException
     */
    public function update(Request $request, Property $property): JsonResponse
    {
        // 1. Vérification des droits d'accès
        if ($property->host_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => "Vous n'êtes pas autorisé à modifier cet établissement."
            ], 403);
        }

        // 2. Validation : On attend du simple texte (string) pour les champs traduits
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'cancellation_policy' => ['nullable', 'string'],

            // Reste des validations standard
            'type' => ['required', 'string', 'in:hotel,resort,villa,apartment,guest_house'],
            'country_code' => ['required', 'string', 'size:2'],
            'state_province' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['required', 'numeric'],
            'longitude' => ['required', 'numeric'],
            'check_in_after' => ['required', 'string'],
            'check_out_before' => ['required', 'string'],
            'amenities' => 'nullable|array',
            'amenities.*' => 'string|exists:amenities,slug',
            'images' => ['nullable', 'array'],
            'images.*' => ['image', 'mimes:jpeg,png,jpg,webp', 'max:5120'],
            'remaining_server_cover' => ['nullable', 'string'],
            'remaining_server_gallery' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // 3. Détection de la langue courante de l'application (ex: 'fr' ou 'en')
        $locale = app()->getLocale();

        // 4. Mise à jour des attributs standards de localisation et de configuration
        $property->type = $validated['type'];
        $property->country_code = $validated['country_code'];
        $property->state_province = $validated['state_province'];
        $property->city = $validated['city'];
        $property->address_line_1 = $validated['address_line_1'];
        $property->address_line_2 = $validated['address_line_2'];
        $property->postal_code = $validated['postal_code']??'78222';
        $property->latitude = $validated['latitude'];
        $property->longitude = $validated['longitude'];
        $property->check_in_after = $validated['check_in_after'];
        $property->check_out_before = $validated['check_out_before'];

        // 5. Utilisation de setTranslation pour ne pas écraser les autres langues existantes en BDD
        $property->setTranslation('name', $locale, $validated['name']);
        $property->setTranslation('description', $locale, $validated['description']);

        if (isset($validated['cancellation_policy'])) {
            $property->setTranslation('cancellation_policy', $locale, $validated['cancellation_policy']);
        }

        // Sauvegarde en base de données
        $property->save();

        // 6. Gestion du nettoyage et de la mise à jour des médias (Spatie Media Library)
        if (empty($validated['remaining_server_cover'])) {
            $currentCoverMedia = $property->getFirstMedia('cover');
            if ($currentCoverMedia) {
                $currentCoverMedia->delete();
            }
        }

        if (isset($validated['remaining_server_gallery'])) {
            $remainingUrls = json_decode($validated['remaining_server_gallery'], true) ?? [];
            $serverGalleryMedia = $property->getMedia('gallery');

            foreach ($serverGalleryMedia as $mediaItem) {
                if (!in_array($mediaItem->getFullUrl(), $remainingUrls)) {
                    $mediaItem->delete();
                }
            }
        }

        if (!empty($validated['amenities'])) {
            $amenityIds = Amenity::whereIn('slug', $validated['amenities'])->pluck('id');
            $property->amenities()->sync($amenityIds);
        }
        // 7. Injection des nouvelles images ajoutées via Next.js
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                if (!$property->hasMedia('cover')) {
                    $property->addMedia($file)->toMediaCollection('cover');
                } else {
                    $property->addMedia($file)->toMediaCollection('gallery');
                }
            }
        }

        $property->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Établissement mis à jour avec succès.',
            'data' => $property
        ]);
    }

    /**
     * [HOST] Supprimer un établissement (Archivage ou Soft Delete recommandé en production).
     */
    public function destroy(Request $request, Property $property): JsonResponse
    {
        if ($property->host_id !== $request->user()->id) {
            abort(403, "Vous n'êtes pas autorisé à supprimer cet établissement.");
        }

        // Sécurité métier : Interdire la suppression s'il y a des réservations actives ou futures
        $hasActiveBookings = $property->rooms()->whereHas('items.booking', function ($query) {
            $query->whereIn('status', ['pending', 'confirmed']);
        })->exists();

        if ($hasActiveBookings) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer cet établissement car des réservations y sont actuellement rattachées.'
            ], 400);
        }

        $property->delete();

        return response()->json([
            'success' => true,
            'message' => 'Établissement supprimé définitivement.'
        ]);
    }
}

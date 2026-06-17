<?php

namespace App\Http\Controllers;

use App\Http\Resources\RoomResource;
use App\Models\Amenity;
use App\Models\Room;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RoomController extends Controller
{
    /**
     * Liste les chambres.
     * Si 'property' est fourni, filtre les chambres d'un hôtel spécifique.
     */
    public function index(Request $request, $propertyId = null): JsonResponse
    {
        $query = Room::query()->active()->with('property:id,name,city');

        if ($propertyId) {
            $query->where('property_id', $propertyId);
        }

        $rooms = $query->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $rooms
        ]);
    }

    /**
     * Liste les chambres pour l'espace Hôte.
     * Filtre obligatoirement par l'établissement si propertyId est fourni,
     * tout en s'assurant que l'établissement appartient bien à l'hôte connecté.
     * @param Request $request
     * @param null $propertyId
     * @return JsonResponse
     */
    public function indexbyHost(Request $request, $propertyId = null): JsonResponse
    {
        // On part des chambres dont la propriété appartient à l'hôte connecté
        $query = Room::query()
            ->whereHas('property', function ($q) use ($request) {
                $q->where('host_id', $request->user()->id);
            })
            ->with('media','property:id,name,city');

        // Si on demande les chambres d'un hôtel spécifique
        if ($propertyId) {
            $query->where('property_id', $propertyId);
        }

        // Note : On a retiré ->active() ici pour que l'hôte puisse voir
        // ses chambres masquées (is_active = false) dans son tableau de bord.

        $rooms = $query->latest()->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $rooms
        ]);
    }
    /**
     * Affiche les offres promotionnelles en cours sur les chambres.
     */
    public function offers(): JsonResponse
    {
        $offers = Room::withActiveOffers()
            ->with(['property:id,name,city'])
            ->latest()
            ->take(6)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $offers
        ]);
    }

    /**
     * Détail d'une chambre spécifique.
     * @param Room $room
     * @return RoomResource
     */
    public function show(Room $room)
    {
        // On charge les relations nécessaires pour la page détail
        $room->load(['property', 'amenities', 'media']);

        return new RoomResource($room);
    }

    /**
     * Enregistrer un type de chambre pour un établissement donné.
     * @param Request $request
     * @param $propertyId
     * @return JsonResponse
     */
    public function store(Request $request, $propertyId): JsonResponse
    {
        // 1. Vérifier si la propriété existe et appartient bien à l'hôte connecté
        $property = Property::where('id', $propertyId)
            ->where('host_id', $request->user()->id)
            ->firstOrFail();

        // 2. Validation stricte calquée sur les attributs du modèle Room
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'base_occupancy' => 'required|integer|min:1',
            'max_occupancy' => 'required|integer|min:1|gte:base_occupancy',
            'max_children' => 'required|integer|min:0',
            'total_inventory' => 'required|integer|min:1',
            'default_price_per_night' => 'required|numeric|min:0',

            'bed_type' => 'required|string',
            'bed_quantity' => 'required|integer|min:1',
            'superficie' => 'required|numeric|min:1',
            // Le front envoie "true"/"false" en chaînes (multipart/form-data) :
            // la règle native "boolean" ne les accepte pas, on élargit la liste acceptée.
            'is_smoking' => 'required|in:true,false,0,1',

            // Équipements et photos de la chambre
            'amenities' => 'nullable|array',
            'amenities.*' => 'string|exists:amenities,slug',
            'images' => 'required|array|min:1',
            'images.*' => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        try {
            DB::beginTransaction();

            $locale = app()->getLocale();
            $fallbackLocale = $locale === 'fr' ? 'en' : 'fr';

            // 3. Création de l'instance Room
            $room = new Room();
            $room->property_id = $property->id;

            // Casts array pour le multilingue JSON
            $room->name = [
                $locale => $validated['name'],
                $fallbackLocale => $validated['name'], // Doublon par défaut pour ne pas avoir de champ vide
            ];
            $room->description = [
                $locale => $validated['description'],
                $fallbackLocale => $validated['description'],
            ];

            $room->base_occupancy = $validated['base_occupancy'];
            $room->max_occupancy = $validated['max_occupancy'];
            $room->max_children = $validated['max_children'];
            $room->total_inventory = $validated['total_inventory'];
            $room->default_price_per_night = $validated['default_price_per_night'];

            $room->bed_type = $validated['bed_type'];
            $room->bed_quantity = $validated['bed_quantity'];
            $room->superficie = $validated['superficie'];
            $room->is_smooking = $request->boolean('is_smoking');

            $room->is_active = true; // Active par défaut à la création

            $room->save();

            // 4. Synchronisation des équipements spécifiques à la chambre (optionnel)
            if (!empty($validated['amenities'])) {
                $amenityIds = Amenity::whereIn('slug', $validated['amenities'])->pluck('id');
                $room->amenities()->sync($amenityIds);
            }

            // 5. Ajout des photos dans la collection Spatie 'room_photos'
            foreach ($request->file('images') as $imageFile) {
                $room->addMedia($imageFile)->toMediaCollection('room_photos');
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Type de chambre ajouté avec succès à votre établissement.',
                'room_id' => $room->id,
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Erreur création chambre : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur technique est survenue.',
                'error' => app()->environment('local', 'testing') ? $e->getMessage() : null,
            ], 500);
        }
    }

    /**
     * Mettre à jour une chambre spécifique.
     * URL: PUT /api/host/rooms/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        // 1. Récupérer la chambre et vérifier qu'elle appartient bien à l'hôte connecté
        $room = Room::where('id', $id)
            ->whereHas('property', function ($q) use ($request) {
                $q->where('host_id', $request->user()->id);
            })->firstOrFail();

        // 2. Validation des données
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'base_occupancy' => 'required|integer|min:1',
            'max_occupancy' => 'required|integer|min:1|gte:base_occupancy',
            'max_children' => 'required|integer|min:0',
            'total_inventory' => 'required|integer|min:1',
            'default_price_per_night' => 'required|numeric|min:0',
            'is_active' => 'required|boolean',
            'amenities' => 'nullable|array',
        ]);

        try {
            DB::beginTransaction();

            $locale = app()->getLocale(); // Détecté via le header 'Accept-Language'

            // 3. Mise à jour des données textuelles et multilingues
            // La notation JSON par point (->) met à jour uniquement la langue en cours !
            $room->update([
                "name->{$locale}" => $validated['name'],
                "description->{$locale}" => $validated['description'],
                'base_occupancy' => $validated['base_occupancy'],
                'max_occupancy' => $validated['max_occupancy'],
                'max_children' => $validated['max_children'],
                'total_inventory' => $validated['total_inventory'],
                'default_price_per_night' => $validated['default_price_per_night'],
                'is_active' => $validated['is_active'],
            ]);
// Dans ton Laravel RoomController.php -> méthode update()

            if ($request->has('deleted_images')) {
                foreach ($request->input('deleted_images') as $mediaId) {
                    // Supprime uniquement le média s'il est rattaché à cette chambre
                    $room->media()->where('id', $mediaId)->delete();
                }
            }

            if ($request->hasFile('images')) {
                foreach ($request->file('images') as $file) {
                    $room->addMedia($file)->toMediaCollection('room_photos');
                }
            }
            // 4. Synchronisation des équipements (Amenities)
            if (isset($validated['amenities'])) {
                $room->amenities()->sync($validated['amenities']);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'La chambre a été mise à jour avec succès.',
                'room' => $room
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Une erreur technique est survenue.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

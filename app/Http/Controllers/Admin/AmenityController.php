<?php


namespace App\Http\Controllers\Admin;


use App\Http\Controllers\Controller;
use App\Models\Amenity;
use Illuminate\Http\JsonResponse;

class AmenityController extends Controller
{
    public function index(): JsonResponse
    {
        // On récupère tous les équipements
        $amenities = Amenity::query()->where('category','property')->get();

        return response()->json([
            'success' => true,
            'data' => $amenities
        ]);
    }
    public function amenties(): JsonResponse
    {
        // On récupère tous les équipements
        $amenities = Amenity::query()->where('category','room')->get();

        return response()->json([
            'success' => true,
            'data' => $amenities
        ]);
    }
}

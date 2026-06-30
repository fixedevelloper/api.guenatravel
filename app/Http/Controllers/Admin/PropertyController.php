<?php


namespace App\Http\Controllers\Admin;

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Property;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        $properties = Property::with(['host', 'rooms'])
            ->latest()
            ->paginate(15);

        // Transformer la collection pour inclure les attributs personnalisés et l'image Spatie
        $properties->getCollection()->transform(function ($property) {
            return [
                'id' => $property->id,
                'name' => $property->getTranslation('name', app()->getLocale()), // Gère le multilingue Spatie
                'type' => $property->type,
                'city' => $property->city,
                'country_code' => $property->country_code,
                'is_active' => $property->is_active,
                'commission_rate' => $property->commission_rate,
                'min_price' => $property->min_price, // Appelle l'accesseur minPrice
                'max_price' => $property->max_price, // Appelle l'accesseur maxPrice
                'host_name' => $property->host?->name ?? 'Inconnu',
                'cover_url' => $property->getFirstMediaUrl('cover', 'thumbnail') ?: '/images/placeholder-property.jpg'
            ];
        });

        return response()->json($properties);
    }
}

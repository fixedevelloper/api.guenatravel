<?php


namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Review;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ReviewController extends Controller
{
    /**
     * Retourne les données pour le dashboard des avis
     */
    public function index()
    {
        $user = Auth::user();

        // 1. Avis déjà publiés
        $published = $user->reviews()
            ->with('property:id,name')
            ->latest()
            ->get()
            ->map(fn($review) => [
                'id' => $review->id,
                'property_name' => $review->property->name,
                'rating' => (float) $review->rating,
                'comment' => $review->comment_positive, // Adaptez selon votre modèle
                'created_at' => $review->created_at->format('d M Y')
            ]);

        // 2. Réservations terminées sans avis (Pending)
        // On récupère les réservations "confirmed" dont le check_out est passé
        // et qui n'ont pas encore d'entrée dans la table reviews
        $pending = Booking::where('guest_id', $user->id)
            ->where('status', 'confirmed')
            ->where('check_out', '<', now())
            ->whereDoesntHave('review') // Nécessite la relation 'review' dans le modèle Booking
            ->with('items.room.property:id,name')
            ->get()
            ->map(fn($booking) => [
                'booking_id' => $booking->id,
                'property_name' => $booking->items->first()->room->property->name ?? 'N/A',
                'check_out' => $booking->check_out->format('d M Y')
            ]);

        return response()->json([
            'status' => 'success',
            'data' => [
                'published' => $published,
                'pending' => $pending
            ]
        ]);
    }

    /**
     * Enregistrement d'un nouvel avis
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'booking_id' => 'required|exists:bookings,id',
            'rating' => 'required|numeric|min:1|max:5',
            'comment' => 'required|string|max:1000',
        ]);

        // Sécurité : Vérifier que l'utilisateur possède bien cette réservation
        $booking = Booking::where('id', $validated['booking_id'])
            ->where('guest_id', Auth::id())
            ->firstOrFail();

        // Création de l'avis
        $review = Review::create([
            'user_id' => Auth::id(),
            'booking_id' => $booking->id,
            'property_id' => $booking->items->first()->room->property_id,
            'rating' => $validated['rating'],
            'cleanliness_rating' => $validated['rating'], // Simplification pour l'exemple
            'location_rating' => $validated['rating'],
            'value_rating' => $validated['rating'],
            'comment_positive' => $validated['comment'],
        ]);

        return response()->json(['status' => 'success', 'data' => $review], 201);
    }
}

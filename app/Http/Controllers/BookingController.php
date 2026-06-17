<?php

namespace App\Http\Controllers;

use App\Http\Requests\BookingRequest;
use App\Http\Resources\BookingResource; // Optionnel : pour formater la sortie de la réservation
use App\Models\Booking;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    /**
     * Injection du service métier de réservation.
     */
    public function __construct(
        protected BookingService $bookingService
    ) {}

/**
 * [CUSTOMER] Liste de toutes les réservations du voyageur connecté.
 * @param Request $request
 * @return JsonResponse
 */
public function index(Request $request): JsonResponse
{
    $bookings = Booking::where('guest_id', $request->user()->id)
        ->with(['items.room.property']) // Eager loading pour optimiser les requêtes SQL
        ->latest()
        ->paginate(10);

    return response()->json([
        'success' => true,
        'data' => $bookings
    ]);
}

/**
 * [CUSTOMER] Étape 1 : Initialiser une commande de réservation au statut 'pending'.
 * Utilise le BookingRequest pour valider les dates et la présence des chambres.
 * @param BookingRequest $request
 * @return JsonResponse
 */
public function store(BookingRequest $request): JsonResponse
{
    try {
        // Appel au service pour vérifier l'inventaire et bloquer temporairement les stocks
        $booking = $this->bookingService->createPendingBooking(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Réservation initialisée avec succès. Veuillez procéder au paiement.',
            'data' => [
                'booking_id' => $booking->id,
                'booking_reference' => $booking->booking_reference,
                'total_amount' => (float) $booking->total_amount,
                'currency' => $booking->currency,
                'status' => $booking->status
            ]
        ], 201);

    } catch (\Illuminate\Validation\ValidationException $e) {
        // Renvoie une erreur propre en cas de rupture de stock sur le calendrier
        return response()->json([
            'success' => false,
            'message' => 'Rupture de stock détectée.',
            'errors' => $e->errors()
        ], 422);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Une erreur imprévue est survenue lors de l\'initialisation.',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * [CUSTOMER] Consulter le détail d'une réservation spécifique (sécurisé par Policy).
 */
public function show(Booking $booking): JsonResponse
{
    // Sécurité : On s'assure que le voyageur connecté est bien le propriétaire de la réservation
    if (auth()->id() !== $booking->guest_id) {
        abort(403, 'Vous n\'êtes pas autorisé à consulter cette réservation.');
    }

    $booking->load(['items.room.property', 'payments']);

    return response()->json([
        'success' => true,
        'data' => $booking
    ]);
}
}

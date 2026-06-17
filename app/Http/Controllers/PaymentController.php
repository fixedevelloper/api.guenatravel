<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    /**
     * Injection du service de paiement.
     */
    public function __construct(
        protected PaymentService $paymentService
    ) {}

/**
 * [CUSTOMER] Étape 2 : Initier le paiement d'une réservation 'pending'.
 * Génère l'URL de redirection vers la passerelle sécurisée (Stripe, Wave, etc.).
 * @param Request $request
 * @param Booking $booking
 * @return JsonResponse
 */
public function pay(Request $request, Booking $booking): JsonResponse
{
    // Sécurité : Vérifier que la réservation appartient bien au client connecté
    if ($booking->guest_id !== $request->user()->id) {
        abort(403, 'Vous n\'êtes pas autorisé à payer cette réservation.');
    }

    // Récupération de la passerelle choisie (Stripe par défaut)
    $gateway = $request->input('gateway', 'stripe');

    try {
        // Demande l'URL de paiement au service
        $checkoutUrl = $this->paymentService->initializeCheckoutSession($booking, $gateway);

        return response()->json([
            'success' => true,
            'message' => 'Session de paiement initialisée.',
            'checkout_url' => $checkoutUrl
        ]);

    } catch (\InvalidArgumentException $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage()
        ], 400);

    } catch (\Exception $e) {
        return response()->json([
            'success' => false,
            'message' => 'Impossible de générer la session de paiement.',
            'error' => $e->getMessage()
        ], 500);
    }
}

/**
 * [PUBLIC] Traitement du Webhook de la passerelle.
 * Cette route est appelée en tâche de fond par Stripe/Wave (asynchrone).
 * @param Request $request
 * @param string $gateway
 * @return JsonResponse
 */
public function webhook(Request $request, string $gateway): JsonResponse
{
    // Récupération du contenu brut et des en-têtes (nécessaires pour valider la signature)
    $payload = $request->all();
    $headers = $request->headers->all();

    // On passe les données au service pour validation et exécution du traitement comptable
    $isProcessed = $this->paymentService->handleWebhook($gateway, $payload, $headers);

    if ($isProcessed) {
        // Les banques exigent un statut HTTP 200 pour confirmer la bonne réception du webhook
        return response()->json(['status' => 'Webhook handeled successfully'], 200);
    }

    return response()->json(['status' => 'Webhook ignored or failed to process'], 400);
}
}

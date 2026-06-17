<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(
        protected BookingService $bookingService
    ) {}

/**
 * Étape 1 : Initialiser l'intention de paiement auprès de la passerelle (Ex: Stripe Checkout Session)
 * Cette méthode génère l'URL vers laquelle le voyageur sera redirigé pour payer.
 */
public function initializeCheckoutSession(Booking $booking, string $gateway): string
{
    // On s'assure que la réservation est bien en attente avant de lever des fonds
    if ($booking->status !== 'pending') {
        throw ValidationException::withMessages([
            'booking' => 'Cette réservation ne peut pas faire l\'objet d\'un paiement (Statut invalide).'
        ]);
    }

    // Exemple de logique selon la passerelle choisie
    switch ($gateway) {
        case 'stripe':
            return $this->createStripeSession($booking);

        case 'wave':
            return $this->createWaveSession($booking);

        default:
            throw new \InvalidArgumentException("La passerelle de paiement [{$gateway}] n'est pas supportée.");
    }
}

/**
 * Étape 2 : Traiter le Webhook d'une passerelle (Le retour asynchrone et sécurisé de la banque)
 * C'est ici que l'on sécurise l'application contre les fraudes.
 */
public function handleWebhook(string $gateway, array $payload, array $headers = []): bool
{
    try {
        switch ($gateway) {
            case 'stripe':
                return $this->processStripeWebhook($payload, $headers);

            case 'wave':
                return $this->processWaveWebhook($payload, $headers);

            default:
                Log::warning("Webhook reçu pour une passerelle inconnue: {$gateway}");
                return false;
        }
    } catch (\Exception $e) {
        Log::error("Erreur lors du traitement du Webhook {$gateway} : " . $e->getMessage());
        return false;
    }
}

/*
|--------------------------------------------------------------------------
| Implémentations spécifiques aux passerelles (Exemples)
|--------------------------------------------------------------------------
*/

/**
 * Simulation de création d'une session Stripe Checkout
 */
protected function createStripeSession(Booking $booking): string
{
    // En production, vous utiliseriez le SDK Stripe officiel :
    // \Stripe\Stripe::setApiKey(config('services.stripe.secret'));
    // $session = \Stripe\Checkout\Session::create([...]);

    Log::info("Création d'une session de paiement Stripe pour la réservation {$booking->booking_reference}");

    // On simule le retour d'une URL de redirection Stripe
    return "https://checkout.stripe.com/pay/cs_live_" . str_random(32);
}

/**
 * Traitement du webhook Stripe (Exemple de réception d'événement)
 */
protected function processStripeWebhook(array $payload, array $headers): bool
{
    // 1. Signer et valider la requête en production pour éviter que quelqu'un forge un faux paiement
    // \Stripe\Webhook::constructEvent($payload, $headers['stripe-signature'], $secret);

    $eventType = $payload['type'] ?? '';

    if ($eventType === 'checkout.session.completed') {
        $session = $payload['data']['object'];

        // On récupère la référence de réservation stockée dans les métadonnées Stripe
        $bookingReference = $session['metadata']['booking_reference'] ?? null;
        $transactionId = $session['payment_intent'] ?? null;

        if ($bookingReference) {
            $booking = Booking::where('booking_reference', $bookingReference)->first();

            if ($booking && $booking->status === 'pending') {
                // Le paiement est un succès ! On délègue la confirmation et la ventilation financière
                $this->bookingService->confirmBookingAndPayment(
                    $booking,
                    'stripe',
                    $transactionId,
                    $payload // On sauvegarde tout le JSON de Stripe pour l'audit
                );

                return true;
            }
        }
    }

    return false;
}

/**
 * Simulation pour une passerelle Mobile Money (Ex: Wave)
 */
protected function createWaveSession(Booking $booking): string
{
    Log::info("Création d'un lien de paiement Wave pour la réservation {$booking->booking_reference}");
    return "https://pay.wave.com/c/checkout_" . str_random(16);
}

protected function processWaveWebhook(array $payload, array $headers): bool
{
    // Logique similaire à Stripe pour valider les signatures et confirmer le paiement
    return true;
}
}

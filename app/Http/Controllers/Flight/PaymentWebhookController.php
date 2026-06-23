<?php


namespace App\Http\Controllers\Flight;

use App\Http\Controllers\Controller;
use App\Models\FlightBooking;
use App\Jobs\ProcessTravelportBooking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    public function handleGatewayCallback(Request $request)
    {
        // 1. Log et extraction des données envoyées par votre passerelle (ex: Monetbil, Campay)
        $paymentReference = $request->input('reference'); // L'ID du booking envoyé à l'étape 2
        $transactionStatus = $request->input('status');  // 'SUCCESS', 'FAILED', etc.
        $amountPaid = (float) $request->input('amount');

        Log::info('[Webhook] Notification de paiement reçue', ['booking_id' => $paymentReference, 'status' => $transactionStatus]);

        $booking = FlightBooking::find($paymentReference);
        if (!$booking) {
            return response()->json(['message' => 'Booking non trouvé'], 404);
        }

        // Si le paiement a échoué ou a été annulé par le client
        if ($transactionStatus !== 'SUCCESS') {
            $booking->update(['booking_status' => 'payment_failed', 'payment_status' => 'failed']);
            return response()->json(['message' => 'Statut mis à jour (Échec)']);
        }

        // Si la réservation est déjà en traitement, on ne fait rien (sécurité doublon)
        if ($booking->booking_status !== 'pending_payment') {
            return response()->json(['message' => 'Déjà traité']);
        }

        // 2. MISE À JOUR EN CAS DE SUCCÈS
        $booking->update([
            'booking_status' => 'paid_pending_gds',
            'payment_status' => ($booking->booking_type === 'hold') ? 'partially_paid' : 'paid',
            'amount_paid'    => $amountPaid
        ]);

        // 3. RÉCUPÉRATION DU COMPLÉMENT ET ENVOI AU JOB GDS
        $selectedFlight = Cache::get('flight_payload_' . $booking->id);
        $cardData = Cache::get('card_data_' . $booking->id);

        // Déclenchement du Job d'arrière-plan pour s'occuper de Travelport
        ProcessTravelportBooking::dispatch($booking->id, $selectedFlight, $cardData);

        return response()->json(['message' => 'Paiement validé, traitement GDS lancé.']);
    }
}

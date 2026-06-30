<?php


namespace App\Services\Travelport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentService
{
    protected $baseUrl;
    protected $apiKey;

    public function __construct()
    {
        // Centralisation des accès de l'agrégateur international dans le .env
        $this->baseUrl = env('INTERNATIONAL_GATEWAY_URL', 'https://api.passerelle-afrique.com');
        $this->apiKey  = env('INTERNATIONAL_GATEWAY_KEY');
    }

    /**
     * Initiateur de paiement international et multi-moyens (MoMo, OM, Wave, Cartes)
     * Gère les débits totaux ou les frais de réservation fixes (Hold).
     *
     * @param string $paymentMethod Méthode choisie ('momo', 'om', 'wave', 'card')
     * @param string|null $phoneNumber Numéro de téléphone du payeur
     * @param float $amountToDebit Le montant calculé (5000 XAF pour un Hold ou Totalité du vol)
     * @param int $bookingId L'ID unique de notre table flight_bookings
     * @param string $currency La devise cible (XAF, XOF, EUR, USD)
     * @return array|bool URL de redirection pour les cartes, ou Booléen de succès pour le Push USSD
     */
    public function initiateLocalPayment(string $paymentMethod, ?string $phoneNumber, float $amountToDebit, int $bookingId, string $currency = 'XAF', $cardData=[])
    {
        try {
            // 1. Détection automatique du pays (CM, CI, SN, GA)
            $countryCode = $this->detectCountry($phoneNumber, $currency);

            // 2. CAS NUMÉRO 1 : PAIEMENT PAR CARTE BANCAIRE
            if ($paymentMethod === 'card') {
                return $this->processCardRedirectionFlow($amountToDebit, $bookingId, $currency, $countryCode);
            }

            // 3. CAS NUMÉRO 2 : PAIEMENTS MOBILE MONEY (Push USSD Asynchrone)
            // Normalisation du numéro (ex: +237 6xx xx xx xx devient 2376xxxxxxxx)
            $formattedPhone = $this->formatPhoneNumberByCountry($phoneNumber, $countryCode);

            // Résolution du canal technique exact selon le pays
            $channel = $this->resolvePaymentChannel($paymentMethod, $countryCode);

            Log::info("[PaymentService] Envoi Push USSD", [
                'booking_id' => $bookingId,
                'pays' => $countryCode,
                'montant' => $amountToDebit,
                'canal' => $channel
            ]);

            // Appel API vers le switch de l'agrégateur
            /*$response = Http::withToken($this->apiKey)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->baseUrl . '/v1/payments/collect', [
                    'amount'             => (int) $amountToDebit,
                    'currency'           => $currency,
                    'country'            => $countryCode,
                    'phone_number'       => $formattedPhone,
                    'channel'            => $channel,
                    'external_reference' => (string) $bookingId, // Reçu par le Webhook au succès
                    'description'        => "Guen's Travel - Commande #" . $bookingId . " (" . ($amountToDebit == 5000 ? 'Frais Hold' : 'Billet') . ")"
                ]);

            if ($response->successful()) {
                return true;
            }

            Log::error("[PaymentService] Échec réponse API Mobile Money", [
                'booking_id' => $bookingId,
                'status'     => $response->status(),
                'body'       => $response->body()
            ]);*/

            return true;

        } catch (\Exception $e) {
            Log::critical("[PaymentService] Exception critique internationale", [
                'booking_id' => $bookingId,
                'message'    => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Génère un lien de paiement sécurisé (Hosted Checkout) pour les cartes bancaires
     */
    protected function processCardRedirectionFlow(float $amount, int $bookingId, string $currency, string $countryCode)
    {
        $response = Http::withToken($this->apiKey)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post($this->baseUrl . '/v1/checkout/initialize', [
                'amount'             => (int) $amount,
                'currency'           => $currency,
                'country'            => $countryCode,
                'external_reference' => (string) $bookingId,
                'payment_type'       => 'CARD',
                'return_url'         => url("/booking/waiting?id=" . $bookingId), // Page de retour React
                'cancel_url'         => url("/checkout?status=cancelled"),
            ]);

        if ($response->successful()) {
            $data = $response->json();
            return [
                'type'         => 'redirect',
                'redirect_url' => $data['payment_url'] ?? $data['checkout_url'] ?? null
            ];
        }

        Log::error("[PaymentService] Échec initialisation Carte", ['body' => $response->body()]);
        return false;
    }

    /**
     * Détecte le code ISO à 2 lettres du pays basé sur l'indicatif ou la devise
     */
    protected function detectCountry(?string $phone, string $currency): string
    {
        if (empty($phone)) {
            return $currency === 'XOF' ? 'CI' : 'CM';
        }

        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($cleanPhone, '225')) return 'CI'; // Côte d'Ivoire
        if (str_starts_with($cleanPhone, '221')) return 'SN'; // Sénégal
        if (str_starts_with($cleanPhone, '241')) return 'GA'; // Gabon
        if (str_starts_with($cleanPhone, '237')) return 'CM'; // Cameroun

        return 'CM'; // Fallback par défaut
    }

    /**
     * Formate et nettoie les numéros de téléphone selon les normes télécoms locales
     */
    protected function formatPhoneNumberByCountry(?string $phone, string $countryCode): string
    {
        $pureNumber = preg_replace('/[^0-9]/', '', $phone);

        // Si l'utilisateur saisit son numéro sans l'indicatif pays, on le rajoute dynamiquement
        if (strlen($pureNumber) === 9 && $countryCode === 'CM') return '237' . $pureNumber;
        if (strlen($pureNumber) === 10 && $countryCode === 'CI') return '225' . $pureNumber;
        if (strlen($pureNumber) === 9 && $countryCode === 'SN') return '221' . $pureNumber;

        return $pureNumber;
    }

    /**
     * Associe la clé générique front-end ('momo', 'om') au canal technique de l'opérateur local
     */
    protected function resolvePaymentChannel(string $method, string $countryCode): string
    {
        $matrix = [
            'CM' => ['momo' => 'MTN_CAMEROON', 'om' => 'ORANGE_CAMEROON'],
            'CI' => ['momo' => 'MTN_COTE_DIVOIRE', 'om' => 'ORANGE_COTE_DIVOIRE', 'wave' => 'WAVE_COTE_DIVOIRE'],
            'SN' => ['om' => 'ORANGE_SENEGAL', 'wave' => 'WAVE_SENEGAL'],
            'GA' => ['momo' => 'AIRTEL_GABON', 'om' => 'ORANGE_GABON'],
        ];

        return $matrix[$countryCode][$method] ?? strtoupper($method);
    }
}

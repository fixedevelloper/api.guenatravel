<?php

namespace App\Jobs;

use App\Models\FlightBooking;
use App\Services\TravelOproService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTraveloProBooking implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 20;

    public function __construct(
        protected int $bookingId,
        protected array $selectedFlight
    ) {}

public function handle(TravelOproService $travelOproService): void
{
    $booking = FlightBooking::with(['trips', 'passengers'])->find($this->bookingId);

    if (!$booking || in_array($booking->booking_status, ['ticketed', 'hold', 'gds_failed'])) {
        Log::info("[TravelOpro Job] Réservation #{$this->bookingId} ignorée.");
        return;
    }

    $booking->update(['booking_status' => 'processing_gds']);

    try {
        $selectedFlight = $this->selectedFlight;
        $fareSourceCode = null;
        $fareSourceCodeInbound = null;

        if (is_array($selectedFlight)) {
            $fareSourceCode = $selectedFlight['travelport']['gds_authority_value'] ?? null;
            $fareSourceCodeInbound = $selectedFlight['travelport']['gds_authority_value_inbound'] ?? null;
        } elseif (is_object($selectedFlight)) {
            $fareSourceCode = $selectedFlight->travelport['gds_authority_value'] ?? null;
            $fareSourceCodeInbound = $selectedFlight->travelport['gds_authority_value_inbound'] ?? null;
        }

        // --- 1. Initialisation des types et de la sous-structure colonnaire ---
// --- 1. Initialisation des types et de la sous-structure colonnaire complète ---
        $typeMapping = [
            'ADT' => 'adult',
            'CHD' => 'child',
            'INF' => 'infant'
        ];

// Structure par défaut alignée sur votre JSON d'exemple (incluant les clés de services/sièges vides pour éviter les crashs)
        $paxSubStructure = [
            'title'                => [],
            'firstName'            => [],
            'lastName'             => [],
            'dob'                  => [],
            'nationality'          => [],
            'passportNo'           => [],
            'passportIssueCountry' => [],
            'passportExpiryDate'   => [],
            // Sécurité pour le parseur TravelOpro : évite les erreurs d'index absents
            'ExtraServiceOutbound_1' => [],
            'ExtraServiceInbound_1'  => [],
            'SeatOutbound_1'         => [],
            'SeatInbound_1'          => [],
            'ExtraServiceOutbound'   => [], // Clés spécifiques enfants parfois utilisées
            'ExtraServiceInbound'    => []
        ];

// On ne prépare les structures QUE pour les types de passagers réellement présents
        $paxGrouped = [];

// --- 2. Remplissage dynamique des passagers (Multi-pax compatible) ---
        foreach ($booking->passengers as $passenger) {
            $dbType = strtoupper($passenger->passenger_type ?? 'ADT');
            $gdsKey = $typeMapping[$dbType] ?? 'adult';

            // Initialisation à la volée du type de passager (adult, child, infant) s'il n'existe pas encore
            if (!isset($paxGrouped[$gdsKey])) {
                // Copie de la structure colonnaire vierge
                $paxGrouped[$gdsKey] = $paxSubStructure;
            }

            // Sécurisation stricte des dates (ZÉRO null autorisé)
            $birthDate = '';
            if (!empty($passenger->birth_date)) {
                $birthDate = $passenger->birth_date instanceof \Carbon\Carbon
                    ? $passenger->birth_date->format('Y-m-d')
                    : date('Y-m-d', strtotime((string)$passenger->birth_date));
            }

            $passportExpiry = '';
            if (!empty($passenger->passport_expiry)) {
                $passportExpiry = $passenger->passport_expiry instanceof \Carbon\Carbon
                    ? $passenger->passport_expiry->format('Y-m-d')
                    : date('Y-m-d', strtotime((string)$passenger->passport_expiry));
            }

            // Nettoyage de la casse (ex: 'mr' -> 'Mr')
            $cleanTitle = ucfirst(strtolower(trim($passenger->title ?? 'Mr')));
            if (!in_array($cleanTitle, ['Mr', 'Mrs', 'Miss', 'Master'])) {
                $cleanTitle = ($gdsKey === 'child' || $gdsKey === 'infant') ? 'Miss' : 'Mr';
            }

            // Push des données dans les tableaux parallèles correspondants
            $paxGrouped[$gdsKey]['title'][]               = $cleanTitle;
            $paxGrouped[$gdsKey]['firstName'][]           = strtoupper(trim($passenger->first_name ?? ''));
            $paxGrouped[$gdsKey]['lastName'][]            = strtoupper(trim($passenger->last_name ?? ''));
            $paxGrouped[$gdsKey]['dob'][]                 = (string)$birthDate;
            $paxGrouped[$gdsKey]['nationality'][]         = strtoupper(trim($passenger->nationality ?? 'FR'));

            // Gestion des passeports synchronisée
            $hasPassport = !empty($passenger->passport_number);
            $paxGrouped[$gdsKey]['passportNo'][]          = $hasPassport ? strtoupper(trim($passenger->passport_number)) : '';
            $paxGrouped[$gdsKey]['passportIssueCountry'][]= $hasPassport ? strtoupper(trim($passenger->passport_issue_country ?? 'FR')) : '';
            $paxGrouped[$gdsKey]['passportExpiryDate'][]  = ($hasPassport && !empty($passportExpiry)) ? (string)$passportExpiry : '';

            // Note : Les tableaux de services optionnels restent vides [] pour ce passager, respectant le compte global
        }

// --- Nettoyage final du payload ---
// Supprime les clés d'options vides pour alléger le JSON si l'API ne les requiert pas strictement,
// tout en conservant les blocs adult/child/infant intacts.
        foreach ($paxGrouped as $key => $paxType) {
            foreach ($paxType as $subKey => $value) {
                if (empty($value) && in_array($subKey, ['ExtraServiceOutbound_1', 'ExtraServiceInbound_1', 'SeatOutbound_1', 'SeatInbound_1', 'ExtraServiceOutbound', 'ExtraServiceInbound'])) {
                    unset($paxGrouped[$key][$subKey]);
                }
            }
        }

// --- 3. Préparation et nettoyage des coordonnées ---
        $cleanPhone = preg_replace('/[^0-9]/', '', $booking->contact_phone);
        $areaCode   = substr($cleanPhone, 0, 3) ?: '010';
        $purePhone  = substr($cleanPhone, 3) ?: '00000000';

        $firstPassenger = $booking->passengers->first();
        $isPassportMandatory = $firstPassenger && !empty($firstPassenger->passport_number);

// --- 4. Reconstruction du Payload avec l'enveloppement strict [ { ... } ] ---
        $data = [
            'flightBookingInfo' => [
                'flight_session_id'        => $booking->session_identifier ?? $booking->session_id,
                'fare_source_code'         => $fareSourceCode,
                'IsPassportMandatory'      => $isPassportMandatory ? 'true' : 'false',
                'areaCode'                 => $areaCode,
                'countryCode'              => '33',
                'fareType'                 => 'Private',
                'fare_source_code_inbound' => $fareSourceCodeInbound,
            ],
            'paxInfo' => [
                'clientRef'     => uniqid('CREATIV_'),
                'postCode'      => '75001',
                'customerEmail' => $booking->contact_email ?? 'user@gmail.com',
                'customerPhone' => $purePhone,
                'bookingNote'   => 'Réservation via Queue Job - Type: ' . ($booking->booking_type ?? 'now'),
                'paxDetails'    => [
                    $paxGrouped // Regroupe 'adult', 'child', et 'infant' dans le même objet imbriqué
                ]
            ]
        ];

        Log::info("[TravelOpro Job] Payload colonnaire épuré généré. Envoi au GDS.", ['types_presents' => array_keys($paxGrouped)]);

        logger($data);
// --- 5. Exécution et validation finale ---
        $gdsResponse = $travelOproService->createBooking($data);

        // On vérifie le tableau standardisé renvoyé par votre service
        if (isset($gdsResponse['success']) && $gdsResponse['success'] === true && isset($gdsResponse['booking_reference'])) {

            $pnr = $gdsResponse['booking_reference']; // Contient le UniqueID extrait par le service
            $finalStatus = ($booking->booking_type === 'hold') ? 'hold' : 'ticketed';

            $booking->update([
                'pnr'              => $pnr,
                'booking_status'   => $finalStatus,
                'raw_gds_response' => json_encode($gdsResponse['data'] ?? $gdsResponse),
            ]);

            Log::info("[TravelOpro Job] Succès ! Vol confirmé avec le PNR: {$pnr}");
        } else {
            // Le service a déjà extrait le bon message d'erreur s'il y en a un
            $errorMessage = $gdsResponse['message'] ?? 'Erreur ou refus renvoyé par le GDS.';
            throw new \Exception($errorMessage);
        }

    } catch (\Throwable $exception) {
        Log::error("[TravelOpro Job] Échec tentative {$this->attempts()} : " . $exception->getMessage());
        $booking->update(['booking_status' => 'gds_failed']); // Optionnel : pour ne pas bloquer le statut en processing
        throw $exception;
    }
}

public function failed(Throwable $exception): void
{
    Log::critical("[TravelOpro Job] ÉCHEC DÉFINITIF pour la réservation #{$this->bookingId}.");
    $booking = FlightBooking::find($this->bookingId);
    if ($booking) {
        $booking->update(['booking_status' => 'gds_failed']);
    }
}
}

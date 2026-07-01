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
    // 🟢 CHARGEMENT DES SÉRIES ET DES SERVICES RATTACHÉS AUX PASSAGERS
    $booking = FlightBooking::with(['trips', 'passengers.services'])->find($this->bookingId);

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

        $typeMapping = [
            'ADT' => 'adult',
            'CHD' => 'child',
            'INF' => 'infant'
        ];

        // Structure de base incluant les nouvelles clés dynamiques pour l'API
        $paxSubStructure = [
            'title'                => [],
            'firstName'            => [],
            'lastName'             => [],
            'dob'                  => [],
            'nationality'          => [],
            'passportNo'           => [],
            'passportIssueCountry' => [],
            'passportExpiryDate'   => [],

            // Clés d'extras & Sièges colonnaires pour TravelOpro
            'ExtraServiceOutbound_1' => [],
            'ExtraServiceInbound_1'  => [],
            'SeatOutbound_1'         => [],
            'SeatInbound_1'          => [],
            'SeatOutboundCode_1'     => [],
            'SeatInboundCode_1'      => [],
            'SeatOutboundPrice_1'    => [],
            'SeatInboundPrice_1'     => [],
        ];

        $paxGrouped = [];

        // --- DEBUT DU TRAITEMENT DES PASSAGERS ---
        foreach ($booking->passengers as $passenger) {
            $dbType = strtoupper($passenger->passenger_type ?? 'ADT');
            $gdsKey = $typeMapping[$dbType] ?? 'adult';

            if (!isset($paxGrouped[$gdsKey])) {
                $paxGrouped[$gdsKey] = $paxSubStructure;
            }

            // Dates de base
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

            $cleanTitle = ucfirst(strtolower(trim($passenger->title ?? 'Mr')));
            if (!in_array($cleanTitle, ['Mr', 'Mrs', 'Miss', 'Master'])) {
                $cleanTitle = ($gdsKey === 'child' || $gdsKey === 'infant') ? 'Miss' : 'Mr';
            }

            $paxGrouped[$gdsKey]['title'][]               = $cleanTitle;
            $paxGrouped[$gdsKey]['firstName'][]           = strtoupper(trim($passenger->first_name ?? ''));
            $paxGrouped[$gdsKey]['lastName'][]            = strtoupper(trim($passenger->last_name ?? ''));
            $paxGrouped[$gdsKey]['dob'][]                 = (string)$birthDate;
            $paxGrouped[$gdsKey]['nationality'][]         = strtoupper(trim($passenger->nationality ?? 'FR'));

            $hasPassport = !empty($passenger->passport_number);
            $paxGrouped[$gdsKey]['passportNo'][]          = $hasPassport ? strtoupper(trim($passenger->passport_number)) : '';
            $paxGrouped[$gdsKey]['passportIssueCountry'][]= $hasPassport ? strtoupper(trim($passenger->passport_issue_country ?? 'FR')) : '';
            $paxGrouped[$gdsKey]['passportExpiryDate'][]  = ($hasPassport && !empty($passportExpiry)) ? (string)$passportExpiry : '';

            // 🟢 EXTRACTION EXTRA-SERVICES & REPAS DEPUIS LA RELATION
            $outboundExtras = [];
            $inboundExtras  = [];

            $outboundSeats  = [];
            $outboundCodes  = [];
            $outboundPrices = [];

            $inboundSeats   = [];
            $inboundCodes   = [];
            $inboundPrices  = [];

            foreach ($passenger->services as $service) {
                if (in_array($service->service_type, ['meal', 'baggage'])) {
                    $extraPayload = [
                        'serviceId' => $service->service_id,
                        'quantity'  => (string)$service->quantity,
                        'segment'   => (string)$service->segment_index,
                    ];
                    if ($service->direction === 'outbound') {
                        $outboundExtras[] = $extraPayload;
                    } else {
                        $inboundExtras[] = $extraPayload;
                    }
                }

                if ($service->service_type === 'seat') {
                    if ($service->direction === 'outbound') {
                        $outboundSeats[]  = $service->service_id;
                        $outboundCodes[]  = $service->seat_code ?? '';
                        $outboundPrices[] = (float)$service->amount;
                    } else {
                        $inboundSeats[]   = $service->service_id;
                        $inboundCodes[]   = $service->seat_code ?? '';
                        $inboundPrices[]  = (float)$service->amount;
                    }
                }
            }

            // Remplissage colonnaire (les tableaux d'extras/sièges sont encapsulés au premier niveau pour chaque passager)
            $paxGrouped[$gdsKey]['ExtraServiceOutbound_1'][] = $outboundExtras;
            $paxGrouped[$gdsKey]['ExtraServiceInbound_1'][]  = $inboundExtras;

            $paxGrouped[$gdsKey]['SeatOutbound_1'][]         = $outboundSeats;
            $paxGrouped[$gdsKey]['SeatInbound_1'][]          = $inboundSeats;

            $paxGrouped[$gdsKey]['SeatOutboundCode_1'][]     = $outboundCodes;
            $paxGrouped[$gdsKey]['SeatInboundCode_1'][]      = $inboundCodes;

            $paxGrouped[$gdsKey]['SeatOutboundPrice_1'][]    = $outboundPrices;
            $paxGrouped[$gdsKey]['SeatInboundPrice_1'][]     = $inboundPrices;
        }

        // Nettoyage final pour ne pas envoyer de clés vides superflues si un groupe entier n'a pas d'options
        foreach ($paxGrouped as $key => $paxType) {
            foreach ($paxType as $subKey => $value) {
                // Si l'intégralité du groupe de passagers n'a sélectionné aucun extra, on nettoie pour alléger le JSON
                if (in_array($subKey, ['ExtraServiceOutbound_1', 'ExtraServiceInbound_1', 'SeatOutbound_1', 'SeatInbound_1', 'SeatOutboundCode_1', 'SeatInboundCode_1', 'SeatOutboundPrice_1', 'SeatInboundPrice_1'])) {
                    // On vérifie si tous les sous-tableaux sont strictement vides
                    $allEmpty = collect($value)->every(function($item) {
                        return empty($item);
                    });
                    if ($allEmpty) {
                        unset($paxGrouped[$key][$subKey]);
                    }
                }
            }
        }

        // Coordonnées de contact
        $cleanPhone = preg_replace('/[^0-9]/', '', $booking->contact_phone);
        $areaCode   = substr($cleanPhone, 0, 3) ?: '010';
        $purePhone  = substr($cleanPhone, 3) ?: '00000000';

        $firstPassenger = $booking->passengers->first();
        $isPassportMandatory = $firstPassenger && !empty($firstPassenger->passport_number);

        // Construction définitive du Payload
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
                    $paxGrouped
                ]
            ]
        ];

        Log::info("[TravelOpro Job] Payload complet avec services généré. Envoi au GDS.", ['types_presents' => array_keys($paxGrouped)]);
        logger($data);

        // Envoi au GDS via ton service
        $gdsResponse = $travelOproService->createBooking($data);

        if (isset($gdsResponse['success']) && $gdsResponse['success'] === true && isset($gdsResponse['booking_reference'])) {
            $pnr = $gdsResponse['booking_reference'];
            $finalStatus = ($booking->booking_type === 'hold') ? 'hold' : 'ticketed';

            $booking->update([
                'pnr'              => $pnr,
                'booking_status'   => $finalStatus,
                'raw_gds_response' => json_encode($gdsResponse['data'] ?? $gdsResponse),
            ]);

            Log::info("[TravelOpro Job] Succès ! Vol confirmé avec le PNR: {$pnr}");
        } else {
            $errorMessage = $gdsResponse['message'] ?? 'Erreur ou refus renvoyé par le GDS.';
            throw new \Exception($errorMessage);
        }

    } catch (\Throwable $exception) {
        Log::error("[TravelOpro Job] Échec tentative {$this->attempts()} : " . $exception->getMessage());
        $booking->update(['booking_status' => 'gds_failed']);
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

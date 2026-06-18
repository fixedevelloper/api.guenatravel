<?php


namespace App\Services\Travelport;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TicketService
{
    protected string $baseUrl;
    protected string $pcc;
    protected TravelportService $travelportService;

    public function __construct(TravelportService $travelportService)
    {
        $this->travelportService = $travelportService;
        $this->baseUrl = env('TRAVELPORT_BASE_URL', 'https://developer.travelport.com/_mock/apis/flights/');
        $this->pcc = env('TRAVELPORT_PCC', 'DU7_1G');
    }

    /**
     * Charge une réservation existante (PNR) dans le Workbench via son Locator
     * Endpoint GDS: POST /v1/air/book/session/reservationworkbench/buildfromlocator
     * @param string $locator
     * @param string $sessionIdentifier
     * @return array
     * @throws \Exception
     */
    public function loadReservationFromLocator(string $locator, string $sessionIdentifier): array
    {
        $token = $this->travelportService->getAccessToken();

        // Paramètres de requête de l'URL pour une vue complète des détails du billet
        $queryParams = [
            "Locator"                  => strtoupper($locator),
            "source"                   => "GDS", // Source par défaut pour Travelport+
            "detailViewInd"            => "true",
            "viewBrandCompleteInfoInd" => "true",
            "viewBaggageDetailInd"     => "true"
        ];

        $url = $this->baseUrl . "v1/air/book/session/reservationworkbench/buildfromlocator?" . http_build_query($queryParams);

        // Appel unifié au GDS Travelport+
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding'                 => 'gzip, deflate',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'LoadLocator_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $sessionIdentifier
            ])
            ->post($url); // Note : C'est bien une méthode POST malgré les Query Params

        if ($response->failed()) {
            Log::error('Travelport Build From Locator Failed', [
                'locator' => $locator,
                'body'    => $response->body()
            ]);
            throw new \Exception('Impossible de récupérer le dossier de réservation spécifié auprès du GDS.');
        }

        return $response->json();
    }
    /**
     * ÉTAPE FINALE : Émettre officiellement les billets électroniques (Ticket Issuance)
     * Endpoint GDS: POST /v1/air/book/reservation/reservations/{identifier}
     */
    public function commitAndIssueTicket(string $reservationResourceIdentifier, string $receivedFrom = 'CREATIV_API'): array
    {
        $token = $this->travelportService->getAccessToken();

        // Paramètres de requête d'URL (Query String) requis par Travelport+ pour l'émission
        $queryParams = [
            "autoDeleteDate" => now()->addDays(7)->format('Y-m-d'), // Repousse l'auto-delete après émission
            "Issuance"       => "Ticket",
            "DocumentValue"  => "Retain",
            "payLaterInd"    => "true" // Utile si paiement déjà collecté en amont via canal agence (Momo)
        ];

        // Payload de configuration de l'émission et gestion des files d'attente (Queues)
        $payload = [
            "Notification" => [
                [
                    "QueueNumber" => [
                        [
                            "value" => 10,
                            "category" => "CAE",
                            "subCategory" => "Issue Confirmation",
                            "overridePCC" => $this->pcc
                        ]
                    ],
                    "Date" => now()->format('Y-m-d'),
                    "Comment" => "Ticket issued via Creativ Solutions Platform"
                ]
            ],
            "scheduleChangeAcceptedInd"          => true,
            "errorWhenOfferPriceCancelledInd"    => true,
            "inhibitResidualDocumentIssuanceInd" => true,
            "enableTwoStepCommitInd"             => true, // Sécurise l'écriture synchrone dans le GDS
            "overrideMCTInd"                     => true,
            "errorWhenScheduleChangesInd"        => true,
            "scheduleChangeReprice"              => "AcceptOfferPriceDifference",
            "ReceivedFrom"                       => $receivedFrom,
            "errorWhenOfferPriceChangesInd"      => true
        ];

        $url = $this->baseUrl . "v1/air/book/reservation/reservations/{$reservationResourceIdentifier}?" . http_build_query($queryParams);

        // Appel de validation de l'émission via le client HTTP de Laravel
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding'                 => 'gzip, deflate',
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'IssueCommit_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($url);

        if ($response->failed()) {
            Log::critical('Travelport Ticket Issuance Commitment Failed', [
                'resource_id' => $reservationResourceIdentifier,
                'body'        => $response->body()
            ]);
            throw new \Exception('La génération des billets électroniques a échoué auprès de la compagnie aérienne.');
        }

        return $response->json();
    }
    /**
     * ÉTAPE 2.9 : Déclarer le mode de paiement (Cash/Agence) dans le Workbench avant émission
     * Endpoint GDS: POST /v1/air/payment/reservationworkbench/{reservationResourceIdentifier}/formofpayment
     */
    public function addCashFormOfPayment(string $reservationResourceIdentifier, array $selectedFlight): array
    {
        $token = $this->travelportService->getAccessToken();
        $gdsAuthorityValue = $selectedFlight['gds_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E';

        // Paramètre d'URL pour forcer l'autorisation immédiate du paiement déclaré
        $queryParams = [
            "authorizePaymentInd" => "true"
        ];

        // Structure propre de type FormOfPaymentCash pour l'encaissement Mobile Money converti
        $payload = [
            "@type" => "FormOfPaymentCash",
            "id" => "fop_cash_" . uniqid(),
            "FormOfPaymentRef" => "fop_cash_1",
            "Identifier" => [
                "value" => $gdsAuthorityValue,
                "authority" => "TVPT"
            ],
            "activeInd"             => true,
            "agentNonRefundableInd" => true,
            "Comment"               => "Paid via local Mobile Money Gateway",
            "FreeText"              => "CREATIV_TRIPS_MOMO_COLLECT",
            "miscellaneousInd"      => true
        ];

        $url = $this->baseUrl . "v1/air/payment/reservationworkbench/{$reservationResourceIdentifier}/formofpayment?" . http_build_query($queryParams);

        // Envoi de la requête via le client HTTP de Laravel
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding'                 => 'gzip, deflate',
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'AddFopCash_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($url);

        if ($response->failed()) {
            Log::error('Travelport Add Form Of Payment Cash Failed', [
                'resource_id' => $reservationResourceIdentifier,
                'body'        => $response->body()
            ]);
            throw new \Exception('Échec de l\'enregistrement de la garantie de paiement auprès du GDS.');
        }

        return $response->json();
    }
    /**
     * ÉTAPE 2.95 : Associer la ventilation comptable (Base, Taxes, Frais) au Workbench
     * Endpoint GDS: POST /v1/air/paymentoffer/reservationworkbench/{reservationResourceIdentifier}/payments
     */
    public function attachPaymentDetails(string $reservationResourceIdentifier, array $selectedFlight, array $passengers): array
    {
        $token = $this->travelportService->getAccessToken();
        $gdsAuthorityValue = $selectedFlight['gds_authority_value'] ?? 'A0656EFF-FAF4-456F-B061-0161008D7C4E';
        $offerId = $selectedFlight['offer_id'] ?? 'offer_1';

        // Extraction dynamique des montants de votre store ou de l'offre calculée
        $currency = $selectedFlight['price_details']['currency'] ?? 'XAF';
        $baseAmount = $selectedFlight['price_details']['base_fare'] ?? 0;
        $totalTaxes = $selectedFlight['price_details']['taxes'] ?? 0;
        $agencyFees = $selectedFlight['price_details']['agency_fees'] ?? 0;
        $totalAmount = $baseAmount + $totalTaxes + $agencyFees;

        // Préparation des références passagers liées au paiement
        $travelerRefs = [];
        foreach ($passengers as $index => $passenger) {
            $travelerRefs[] = [
                "passengerTypeCode" => ($passenger['age'] ?? 30) < 12 ? 'CHD' : 'ADT',
                "id" => "traveler_" . ($index + 1)
            ];
        }

        // Structure du payload nettoyée des dysfonctionnements de guillemets cURL
        $payload = [
            "@type" => "Payment",
            "id" => "pmt_" . uniqid(),
            "PaymentRef" => "pmt_ref_1",
            "Identifier" => [
                "value" => $gdsAuthorityValue,
                "authority" => "TVPT"
            ],
            "Amount" => [
                "value" => (float)$totalAmount,
                "code" => $currency,
                "minorUnit" => ($currency === 'XAF') ? 0 : 2, // 0 pour le XAF, 2 pour USD/EUR
                "currencySource" => "Supplier",
                "approximateInd" => false
            ],
            "FormOfPaymentIdentifier" => [
                "@type" => "FormOfPaymentPaymentCash",
                "id" => "fop_cash_ref",
                "FormOfPaymentRef" => "fop_cash_1", // Doit correspondre au Ref injecté à l'étape précédente
                "Identifier" => [
                    "value" => $gdsAuthorityValue,
                    "authority" => "TVPT"
                ],
                "activeInd" => true
            ],
            "OfferIdentifier" => [
                [
                    "id" => $offerId,
                    "offerRef" => $offerId,
                    "Identifier" => [
                        "value" => $gdsAuthorityValue,
                        "authority" => "TVPT"
                    ]
                ]
            ],
            "Fees" => [
                "@type" => "FeesDetail",
                "TotalFees" => (float)$agencyFees,
                "TotalAdditionalFeesPayableLocally" => 0.0
            ],
            "Taxes" => [
                "@type" => "TaxesDetail",
                "TotalTaxes" => (float)$totalTaxes
            ],
            "TravelerIdentifierRef" => $travelerRefs,
            "BaseAmount" => [
                "value" => (float)$baseAmount,
                "code" => $currency,
                "minorUnit" => ($currency === 'XAF') ? 0 : 2,
                "currencySource" => "Supplier",
                "approximateInd" => false
            ],
            "depositInd" => false,
            "guaranteeInd" => true
        ];

        $url = $this->baseUrl . "v1/air/paymentoffer/reservationworkbench/{$reservationResourceIdentifier}/payments";

        // Exécution de la requête POST
        $response = Http::withToken($token)
            ->withHeaders([
                'Accept-Encoding'                 => 'gzip, deflate',
                'Content-Type'                    => 'application/json',
                'TVP-PCC-Core'                    => $this->pcc,
                'TraceId'                         => 'AttachPayment_' . uniqid(),
                'XAUTH_TRAVELPORT_ACCESSGROUP'    => env('TRAVELPORT_ACCESS_GROUP', '19Y88702-C27A-4E5D-829A-89D7016688B1'),
                'travelportPlusSessionIdentifier' => $reservationResourceIdentifier
            ])
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('Travelport Attach Payment Details Failed', [
                'resource_id' => $reservationResourceIdentifier,
                'body'        => $response->body()
            ]);
            throw new \Exception('Échec de la validation de la ventilation tarifaire auprès du système de réservation.');
        }

        return $response->json();
    }
    /**
     * Exemple de structure pour l'émission finale du billet (Ticket Issuance)
     * Une fois le workbench chargé via le locator, vous appellerez cet endpoint
     */
    public function issueTicket(string $sessionIdentifier): array
    {
        $token = $this->travelportService->getAccessToken();

        // Logique future de génération du billet (POST v1/air/book/ticket/tickets/issue)
        // ...
        return [];
    }
}

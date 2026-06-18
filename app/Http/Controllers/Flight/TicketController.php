<?php


namespace App\Http\Controllers\Flight;

use App\Http\Controllers\Controller;
use App\Services\Travelport\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TicketController extends Controller
{
    protected TicketService $ticketService;

    /**
     * Injection du service de billetterie via le constructeur
     * @param TicketService $ticketService
     */
    public function __construct(TicketService $ticketService)
    {
        $this->ticketService = $ticketService;
    }

    /**
     * ACTION 1 : Récupérer et inspecter un PNR existant via son Locator (6 caractères)
     * Utile pour afficher les détails du vol, les bagages ou l'état actuel sur Next.js
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function fetchAndInspect(Request $request)
    {
        // Validation rigoureuse des paramètres requis
        $validated = $request->validate([
            'locator'            => 'required|string|size:6',
            'session_identifier' => 'required|string' // Requis pour lier le Reservation Workbench
        ]);

        try {
            // Chargement du dossier depuis les serveurs de la compagnie aérienne
            $reservationData = $this->ticketService->loadReservationFromLocator(
                strtoupper($validated['locator']),
                $validated['session_identifier']
            );

            return response()->json([
                'status'  => 'success',
                'message' => 'Dossier de réservation récupéré avec succès depuis Travelport+.',
                'data'    => $reservationData
            ]);

        } catch (\Exception $e) {
            Log::error('Erreur lors de l\'inspection du PNR ' . $validated['locator'], [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de récupérer les détails de cette réservation. Vérifiez le code GDS.',
                'debug'   => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * ACTION 2 : Émettre manuellement ou valider en différé les e-tickets d'un dossier
     * Pratique si tu sépares la réservation (Hold PNR) de l'encaissement asynchrone
     */
    public function issue(Request $request)
    {
        $validated = $request->validate([
            'locator'            => 'required|string|size:6',
            'session_identifier' => 'required|string'
        ]);

        $sessionIdentifier = $validated['session_identifier'];
        $locator = strtoupper($validated['locator']);

        try {
            Log::info("Début du processus d'émission manuelle/différée pour le PNR: {$locator}");

            // 1. Recharger impérativement le PNR dans la session active du Workbench
            $this->ticketService->loadReservationFromLocator($locator, $sessionIdentifier);

            // 2. Déclencher l'ordre d'émission synchrone et final des billets électroniques
            $issuanceResult = $this->ticketService->commitAndIssueTicket($sessionIdentifier, 'CREATIV_MANUAL_ADMIN');

            // Extraction des e-tickets générés par le GDS
            $tickets = $issuanceResult['AirTicket'] ?? [];

            return response()->json([
                'status'  => 'success',
                'message' => 'Les billets électroniques ont été générés et rattachés au PNR avec succès.',
                'data'    => [
                    'pnr'     => $locator,
                    'tickets' => $tickets
                ]
            ]);

        } catch (\Exception $e) {
            Log::critical("Échec critique de l'émission différée pour le PNR: {$locator}", [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Le processus d\'émission des e-tickets a échoué auprès de Travelport+.',
                'debug'   => env('APP_DEBUG') ? $e->getMessage() : null
            ], 500);
        }
    }
}

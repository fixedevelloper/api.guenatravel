<?php


namespace App\Http\Controllers\Flight;


use App\Http\Controllers\Controller;
use App\Services\SabreService;
use App\Services\TravelportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class Flight2Controller extends Controller
{
    protected $sabreService;
    protected  $travelportService;
    // Injection du service Sabre dans le constructeur
    public function __construct(TravelportService $travelportService,SabreService $sabreService)
    {
        $this->sabreService = $sabreService;
        $this->travelportService=$travelportService;
    }

    public function search(Request $request)
    {
        $validatedData = $request->validate([
            'origin'         => 'required|string|size:3',
            'destination'    => 'required|string|size:3',
            'departure_date' => 'required|date|after_or_equal:today',
            'adults'         => 'required|integer|min:1',
        ]);

        try {
            // 1. Appel GDS Sabre (Données brutes très complexes)
            $rawResults = $this->sabreService->searchFlights($validatedData);

            // 2. Nettoyage et simplification du JSON
            $cleanedData = $this->sabreService->formatSabreResponse($rawResults);

            // 3. Application de vos frais d'agence (Markup)
            foreach ($cleanedData['flights'] as &$flight) {
                $totalSabre = $flight['price_details']['total_sabre'];

                // Exemple : Vos frais fixes d'agence de voyage en ligne (ex: 15 000 XAF)
                $fraisAgence = 15000;

                $flight['price_details']['agency_fees'] = $fraisAgence;
                $flight['price_details']['final_price_to_pay'] = $totalSabre + $fraisAgence;
            }

            // Retourne un JSON ultra-propre, prêt à être consommé par votre Frontend
            return response()->json($cleanedData);

        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function searchTest(Request $request)
    {
        // 1. Validation stricte du payload étendu de Next.js
        $validatedData = $request->validate([
            'trip_type'               => 'required|string|in:one_way,round_trip,multi_city',
            'return_date'             => 'nullable|date|after_or_equal:segments.0.departure_date',
            'passengers.adults'       => 'required|integer|min:1',
            'passengers.children'     => 'required|integer|min:0',
            'passengers.infants'      => 'required|integer|min:0',
            'segments'                => 'required|array|min:1',
            'segments.*.origin'       => 'required|string|size:3',
            'segments.*.destination'  => 'required|string|size:3',
            'segments.*.departure_date'=> 'required|date|after_or_equal:today',

            // Paramètres de lecture rapide (doublons à la racine pour Wakanow-style)
            'origin'                  => 'required|string|size:3',
            'destination'             => 'required|string|size:3',
            'departure_date'          => 'required|date|after_or_equal:today',
        ]);

        try {
            // Option A: Interroger Travelport en direct
             $rawResults = $this->travelportService->searchAirAvailability($request->all());

            // Option B: Mode Simulation (Fichier JSON de Mock Travelport)
            //$path = storage_path('app/travelport_mock_response.json');
           // $rawResults = json_decode(File::get($path), true);

            // Appel du formateur unifié de Travelport
            $cleanedData = $this->travelportService->formatAirAvailabilityResponse($rawResults);

            // Application automatique de votre Markup Agence (15 000 XAF)
            foreach ($cleanedData['flights'] as $key => &$flight) {
                $totalGds = $flight['price_details']['total_sabre'] ?? 0;
                $fraisAgence = 15000;

                $flight['price_details']['agency_fees'] = (float)$fraisAgence;
                $flight['price_details']['final_price_to_pay'] = (float)($totalGds + $fraisAgence);
                $flight['id'] = 'fl_travelport_' . md5($key . time());
            }

            return response()->json($cleanedData);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la simulation de recherche Travelport.',
                'debug' => $e->getMessage()
            ], 500);
        }
    }
    public function searchTest2(Request $request)
    {
        // 1. Validation stricte du payload étendu de Next.js
        $validatedData = $request->validate([
            'trip_type'               => 'required|string|in:one_way,round_trip,multi_city',
            'return_date'             => 'nullable|date|after_or_equal:segments.0.departure_date',
            'passengers.adults'       => 'required|integer|min:1',
            'passengers.children'     => 'required|integer|min:0',
            'passengers.infants'      => 'required|integer|min:0',
            'segments'                => 'required|array|min:1',
            'segments.*.origin'       => 'required|string|size:3',
            'segments.*.destination'  => 'required|string|size:3',
            'segments.*.departure_date'=> 'required|date|after_or_equal:today',

            // Paramètres de lecture rapide (doublons à la racine pour Wakanow-style)
            'origin'                  => 'required|string|size:3',
            'destination'             => 'required|string|size:3',
            'departure_date'          => 'required|date|after_or_equal:today',
        ]);

        try {
            // 2. Chargement du fichier de simulation (OTA_AirLowFareSearchRS brut)
            $path = storage_path('app/sabre_mock_response.json');

            if (!File::exists($path)) {
                return response()->json(['error' => 'Fichier de simulation Sabre introuvable.'], 404);
            }

            $jsonContent = File::get($path);
            $rawResults = json_decode($jsonContent, true);

            // 3. Formatage de base via votre méthode existante
            //$cleanedData = $this->sabreService->formatSabreResponse($rawResults);

            // 4. Post-traitement et ajustement dynamique basé sur la requête réelle
            $multiplicateurPassagers = $request->input('passengers.adults', 1)
                + $request->input('passengers.children', 0);
            // Note : Généralement les bébés (infants) ne paient pas de siège complet, ou à 10%
            $cleanedData=$rawResults;
            logger($rawResults['flights']);
            foreach ($cleanedData['flights'] as $key => &$flight) {

                // FILTRE MOCK : Si l'utilisateur demande un "one_way" mais que le mock contient un retour ("inbound"),
                // on retire le retour pour coller à la demande de l'utilisateur.
                /*                if ($validatedData['trip_type'] === 'one_way') {
                                    $flight['itinerary'] = array_filter($flight['itinerary'], function($journey) {
                                        return $journey['direction'] === 'outbound';
                                    });
                                    // Réindexation du tableau après filtrage
                                    $flight['itinerary'] = array_values($flight['itinerary']);
                                }*/

                // Calcul des tarifs Sabre bruts multipliés par le nombre de voyageurs
                $totalSabreBrut = ($flight['price_details']['total_sabre'] ?? 0) * $multiplicateurPassagers;
                $baseSabreBrut  = ($flight['price_details']['base_price'] ?? 0) * $multiplicateurPassagers;
                $taxesSabreBrut = ($flight['price_details']['taxes'] ?? 0) * $multiplicateurPassagers;

                // Application de votre marge d'agence fixe (ex: 15 000 XAF par billet)
                $fraisAgenceParBillet = 15000;
                $totalFraisAgence = $fraisAgenceParBillet * $multiplicateurPassagers;

                // Reconstruction propre du bloc tarifaire attendu par Next.js
                $flight['price_details'] = [
                    'base_price'         => (float)$baseSabreBrut,
                    'taxes'              => (float)$taxesSabreBrut,
                    'agency_fees'        => (float)$totalFraisAgence,
                    'total_sabre'        => (float)$totalSabreBrut,
                    'final_price_to_pay' => (float)($totalSabreBrut + $totalFraisAgence),
                    'currency'           => $flight['price_details']['currency'] ?? 'XAF'
                ];

                // Injection d'un ID unique requis par les clés React du frontend
                $flight['id'] = 'fl_' . md5($flight['price_details']['final_price_to_pay'] . $key . time());
            }
            unset($flight);

            // Mise à jour du compteur global après filtrages potentiels
            $cleanedData['results_count'] = count($cleanedData['flights']);

            return response()->json($cleanedData);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la simulation de recherche Sabre.',
                'debug' => $e->getMessage()
            ], 500);
        }
    }
    private function applyMarkup(array $data): array
    {
        // Votre logique commerciale type Wakanow (+10 000 XAF, etc.)
        return $data;
    }
}

<?php


namespace App\Http\Controllers\Flight;


use App\Http\Controllers\Controller;
use App\Models\FlightBooking;
use App\Services\Travelport\FlightBookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CustomerFlightBookingController extends Controller
{
    protected $flightService;

    public function __construct(FlightBookingService $flightService)
    {
        $this->flightService = $flightService;
    }

    public function index()
    {
        // 1. Eager loading optimisé sur les relations existantes de ton modèle
        $bookings = FlightBooking::with(['trips', 'passengers'])
            ->where('user_id', Auth::id())
            ->orderBy('created_at', 'desc')
            ->get();

        // 2. Transformation pour correspondre au typage TypeScript du Next.js
        $formattedBookings = $bookings->map(function ($booking) {

            $allSegments = [];

            foreach ($booking->trips as $trip) {
                $allSegments[] = [
                    'id'                => (string) $trip->id,
                    'airline_name'      => $booking->raw_flight_data['airline_names'][$trip->airline_code] ?? $trip->airline_code,
                    'airline_code'      => $trip->airline_code,
                    'flight_number'     => $trip->flight_number,
                    'booking_class'     => $trip->brand_value ?? 'Économique', // Utilise brand_value pour la classe/gamme
                    'departure_airport' => $trip->origin,
                    'departure_city'    => $booking->raw_flight_data['cities'][$trip->origin] ?? $trip->origin,
                    'departure_time'    => $trip->departure_time ? $trip->departure_time->toIso8601String() : null,
                    'arrival_airport'   => $trip->destination,
                    'arrival_city'      => $booking->raw_flight_data['cities'][$trip->destination] ?? $trip->destination,
                    'arrival_time'      => $trip->arrival_time ? $trip->arrival_time->toIso8601String() : null,
                    'duration'          => $booking->raw_flight_data['durations'][$trip->id] ?? 'N/A',
                ];
            }

            // Récupération sécurisée des extrémités du voyage pour l'en-tête du ticket principal
            $firstSegment = $allSegments[0] ?? null;
            $lastSegment  = $allSegments[count($allSegments) - 1] ?? null;

            return [
                'id'                => (string) $booking->id,
                'pnr'               => strtoupper($booking->pnr ?? 'HOLD'),
                'departure_city'    => $firstSegment ? $firstSegment['departure_city'] : 'N/A',
                'arrival_city'      => $lastSegment ? $lastSegment['arrival_city'] : 'N/A',
                'departure_date'    => $firstSegment ? $firstSegment['departure_time'] : $booking->created_at->toIso8601String(),
                'total_price'       => (float) $booking->total_amount,
                'status'            => $this->mapStatus($booking->booking_status),
                'segments'          => $allSegments,
                'passengers'        => $booking->passengers->map(function ($passenger) {
                    return [
                        'first_name'     => $passenger->first_name,
                        'last_name'      => $passenger->last_name,
                        'passenger_type' => $passenger->passenger_type ?? 'ADT',
                        'ticket_number'  => $passenger->ticket_number ?? null,
                    ];
                })->toArray(),
                'baggage_allowance' => $booking->raw_flight_data['baggage_allowance'] ?? '2 PC (2x23KG)'
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $formattedBookings
        ]);
    }
    /**
     * Normalisation des statuts pour le composant Front
     */
    private function mapStatus(?string $status): string
    {
        return match (strtolower($status)) {
        'confirmed', 'ticketed', 'paid', 'issued' => 'confirmed',
            'hold', 'pending', 'initialized'          => 'pending',
            'cancelled', 'expired', 'voided'          => 'cancelled',
            default                                   => 'pending',
        };
    }

    /**
     * Optionnel : Permet de forcer une synchronisation live depuis le GDS si nécessaire.
     * Endpoint: GET /api/customer/flights/bookings/{pnr}/sync
     */
    public function syncLiveGds($pnr)
    {
        $booking = FlightBooking::where('user_id', Auth::id())
            ->where('pnr', strtoupper($pnr))
            ->firstOrFail();

        try {
            // Appel à ta méthode buildfromlocator
            $gdsData = $this->flightService->getReservationDetail($booking->pnr);

            // Logique de mise à jour locale optionnelle si des changements ont eu lieu sur le GDS
            // ...

            return response()->json([
                'success' => true,
                'gds'     => $gdsData
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => "Erreur lors de la synchronisation avec Travelport."
            ], 500);
        }
    }

}

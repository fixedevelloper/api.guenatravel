<?php


namespace App\Http\Controllers\Flight;

use App\Events\UserAutoRegistered;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerBookingResource;
use App\Models\HotelBooking;
use App\Models\HotelCity;
use App\Models\User;
use App\Services\HotelService;
use App\Services\Travelport\PaymentService;
use http\Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class HotelController extends Controller
{
    protected $paymentService;

    /**
     * HotelController constructor.
     * @param $paymentService
     */
    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    public function search(Request $request, HotelService $service): JsonResponse
    {
        // 1. Validation stricte et corrigée
        $validated = $request->validate([
            'checkin'                    => 'required|date|after_or_equal:today', // Corrigé : Permet de réserver aujourd'hui
            'checkout'                   => 'required|date|after:checkin',
            'latitude'                   => 'required|numeric',
            'longitude'                  => 'required|numeric',
            'nationality'                => 'required|string|size:2',
            'currency'                   => 'sometimes|string|size:3',
            'radius'                     => 'sometimes|integer|min:1|max:100',
            'max_result'                 => 'sometimes|integer|min:1|max:100',
            'city_name'                  => 'sometimes|string',
            'country_name'               => 'sometimes|string',
            'hotel_codes'                => 'sometimes|array',
            'hotel_codes.*'              => 'string',
            'occupancy'                  => 'required|array|min:1',
            'occupancy.*.room_no'        => 'required|integer|min:1',
            'occupancy.*.adult'          => 'required|integer|min:1',
            'occupancy.*.child'          => 'sometimes|integer|min:0',
            'occupancy.*.child_age'      => 'sometimes|array',
            'occupancy.*.child_age.*'    => 'integer|min:0|max:17',
        ]);

        // 2. Sécurité : On passe uniquement les données validées au service
        $result = $service->searchHotels($validated);

        // 3. Gestion sécurisée des erreurs du service
        if (!isset($result['success']) || !$result['success']) {
            $errorType = $result['type'] ?? 'server_error';

            $status = match($errorType) {
            'validation_error' => 422,
            'authentication_error' => 401,
            default            => 500,
        };

        return response()->json([
            'message'    => $result['error_message'] ?? 'An unexpected error occurred.',
            'error_code' => $result['error_code'] ?? null,
            'type'       => $errorType,
        ], $status);
    }

        return response()->json([
            'status' => $result['status'] ?? 'success',
            'hotels' => $result['hotels'] ?? [],
        ], 200);
    }
    public function getCities(int $from = 1, int $to = 100): array
    {
        $cities = HotelCity::orderBy('city_name')
            ->offset($from - 1)
            ->limit($to - $from + 1)
            ->get(['id', 'city_name', 'country_name', 'latitude', 'longitude'])
            ->toArray();

        return [
            'success' => true,
            'cities'  => $cities,
        ];
    }
    public function searchCities(Request $request): JsonResponse
    {
        // 1. Récupérer et nettoyer le terme de recherche (?term=...)
        $term = trim($request->query('term', ''));
        $limit = (int) $request->query('limit', 10);

        // 2. Sécurité : si le terme est vide, retourner un tableau vide immédiatement
        if (empty($term)) {
            return response()->json([
                'success' => true,
                'cities'  => []
            ]);
        }

        // 3. Exécuter la requête (avec la correction des parenthèses logiques)
        $cities = HotelCity::where(function ($query) use ($term) {
            $query->where('city_name', 'LIKE', "%{$term}%")
                ->orWhere('country_name', 'LIKE', "%{$term}%");
        })
            ->orderByRaw("city_name LIKE ? DESC", ["{$term}%"])
            ->limit($limit)
            ->get(['id', 'city_name', 'country_name', 'latitude', 'longitude']);

        // 4. Retourner une réponse JSON propre
        return response()->json([
            'success' => true,
            'cities'  => $cities,
        ]);
    }
    public function getRoomRates(Request $request, HotelService $service): JsonResponse
    {
        // 1. Validation stricte des Query Params issus du GET Axios
        $validated = $request->validate([
            'session_id' => 'required|string',
            'product_id' => 'required|string',
            'token_id'   => 'required|string',
            'hotel_id'   => 'required|string',
        ]);

        // 2. Appel du service avec les données validées et nettoyées
        $result = $service->getRoomRates(
            $validated['session_id'],
            $validated['product_id'],
            $validated['token_id'],
            $validated['hotel_id']
        );

        // 3. Gestion ultra-sécurisée des échecs (Fournisseur API / Token expiré)
        if (!isset($result['success']) || !$result['success']) {
            $errorType = $result['type'] ?? 'api_error';

            $status = match($errorType) {
            'validation_error'    => 422,
            'authentication_error' => 401,
            'token_expired'        => 410, // Utile si l'utilisateur a trop attendu sur la page
            default                => 500,
        };

        return response()->json([
            'message'    => $result['error_message'] ?? 'Unable to retrieve room rates.',
            'error_code' => $result['error_code'] ?? null,
            'type'       => $errorType,
        ], $status);
    }

        // 4. Retour propre conforme à l'interface 'RoomRatesResponse' attendue par Next.js
        return response()->json([
            'session_id' => $result['session_id'] ?? $validated['session_id'],
            'room_rates' => $result['room_rates'] ?? [],
        ], 200);
    }
    public function getHotelDetails(Request $request, HotelService $service): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
            'hotel_id'   => 'required|string',
            'product_id' => 'required|string',
            'token_id'   => 'required|string',
        ]);

        $result = $service->getHotelDetails(
            $request->input('session_id'),
            $request->input('hotel_id'),
            $request->input('product_id'),
            $request->input('token_id'),
    );

        if (!$result['success']) {
            $status = match($result['type']) {
            'validation_error' => 422,
            default            => 500,
        };

        return response()->json([
            'message'    => $result['error_message'],
            'error_code' => $result['error_code'] ?? null,
            'type'       => $result['type'],
        ], $status);
    }

        return response()->json($result['hotel'], 200);
    }

    public function bookHotel(Request $request): JsonResponse
    {
        // 1. Validation stricte des données d'entrée
        $validated = $request->validate([
            'session_id'                          => 'required|string',
            'product_id'                          => 'required|string',
            'token_id'                            => 'required|string',
            'rate_basis_id'                       => 'required|string',
            'client_ref'                          => 'required|string',
            'customer_email'                      => 'required|email',
            'customer_phone'                      => 'required|string',
            'booking_note'                        => 'sometimes|string|nullable',

            // Champs obligatoires pour la table locale (à ajouter à la validation ou à envoyer du front)
            'hotel_id'                            => 'required|string',
            'check_in'                            => 'required|date_format:Y-m-d',
            'check_out'                           => 'required|date_format:Y-m-d',
            'days'                                => 'required|integer|min:1',
            'currency'                            => 'required|string|size:3',
            'net_price'                           => 'required|numeric|min:0',
            'fare_type'                           => 'required|string',
            'payment_method'                      => 'required|string', // requis pour l'initiation du paiement

            'rooms'                               => 'required|array|min:1',
            'rooms.*.room_no'                     => 'required|integer|min:1',
            'rooms.*.adults'                      => 'required|array|min:1',
            'rooms.*.adults.*.title'              => 'required|string',
            'rooms.*.adults.*.first_name'         => 'required|string',
            'rooms.*.adults.*.last_name'          => 'required|string',
            'rooms.*.children'                    => 'sometimes|array',
            'rooms.*.children.*.title'            => 'required_with:rooms.*.children|string',
            'rooms.*.children.*.first_name'       => 'required_with:rooms.*.children|string',
            'rooms.*.children.*.last_name'        => 'required_with:rooms.*.children|string',
        ]);

        // On utilise un bloc DB::transaction pour sécuriser la création simultanée User + Booking
        $response = DB::transaction(function () use ($validated) {

            // 2. Gestion de l'utilisateur (Connexion ou Inscription automatique)
            $userId = auth()->id();

            if (!$userId) {
                $user = User::where('email', $validated['customer_email'])->first();

                if (!$user) {
                    // Extraction sécurisée du premier passager adulte pour le nom du profil
                    $primaryPassenger = $validated['rooms'][0]['adults'][0] ?? null;
                    $firstName = $primaryPassenger ? $primaryPassenger['first_name'] : 'Client';
                    $lastName = $primaryPassenger ? $primaryPassenger['last_name'] : 'Voyage';

                    $temporaryPassword = Str::random(10);

                    $user = User::create([
                        'name'     => ucfirst($firstName) . ' ' . strtoupper($lastName),
                        'email'    => $validated['customer_email'],
                        'phone'    => $validated['customer_phone'],
                        'password' => Hash::make($temporaryPassword),
                    ]);

                    // Déclenchement de l'événement d'envoi d'email avec les identifiants
                    event(new UserAutoRegistered($user, $temporaryPassword));
                }

                $userId = $user->id;
            }

            // 3. Sauvegarde initiale au statut PENDING_PAYMENT
            $booking = HotelBooking::create([
                'user_id'             => $userId,
                'client_ref_num'      => $validated['client_ref'],
                'product_id'          => $validated['product_id'],
                'hotel_id'            => $validated['hotel_id'],
                'session_id'          => $validated['session_id'],
                'token_id'            => $validated['token_id'],
                'rate_basis_id'       => $validated['rate_basis_id'],
                'check_in'            => $validated['check_in'],
                'check_out'           => $validated['check_out'],
                'days'                => $validated['days'],
                'currency'            => $validated['currency'],
                'net_price'           => $validated['net_price'],
                'fare_type'           => $validated['fare_type'],
                'cancellation_policy' => [],
                'status'              => 'PENDING_PAYMENT', // <-- Changement de statut de PENDING à PENDING_PAYMENT
                'customer_email'      => $validated['customer_email'],
                'customer_phone'      => $validated['customer_phone'],
                'booking_note'        => $validated['booking_note'] ?? null,
                'rooms_booked'        => [],
                'pax_details'         => $validated['rooms'],
                // AJOUT CHAMP JSON : Sauvegarde brute de la requête pour exécution future par le Job
                'api_request_payload' => $validated
            ]);

            // 4. Appel à la passerelle de paiement locale (Mobile Money / Carte locale)
            $paymentResult = $this->paymentService->initiateLocalPayment(
                $validated['payment_method'],
                $validated['customer_phone'],
                $validated['net_price'],
                $booking->id,
                $validated['currency']
            );

            if (!$paymentResult) {
                // Le statut passe en échec d'initiation si la passerelle MM/bancaire est injoignable
                $booking->update(['status' => 'PAYMENT_INITIATION_FAILED']);

                return response()->json([
                    'status'  => 'error',
                    'message' => 'La passerelle de paiement locale n\'a pas pu générer la demande de débit.'
                ], 400);
            }

            // 5. Formulation de la réponse dynamique pour Next.js / React
            if (is_array($paymentResult) && isset($paymentResult['type']) && $paymentResult['type'] === 'redirect') {
                return response()->json([
                    'status'       => 'redirect_required',
                    'message'      => 'Redirection vers l\'interface bancaire sécurisée.',
                    'redirect_url' => $paymentResult['redirect_url'],
                    'booking_id'   => $booking->id
                ], 200);
            }

            return response()->json([
                'status'     => 'waiting_confirmation',
                'message'    => 'Demande de paiement envoyée. Veuillez valider le prompt USSD de confirmation sur votre téléphone.',
                'booking_id' => $booking->id,
                'session_id'=>$booking->session_id
            ], 200);
        });

        return $response;
    }
    /**
     * Récupère le statut en temps réel d'une réservation d'hôtel pour le polling du Front-end.
     *
     * @param string|int $id
     * @return JsonResponse
     */
    public function getBookingStatus($id): JsonResponse
    {
        try {
            // 1. Recherche de la réservation par son ID ou sa référence
            $booking = HotelBooking::where('id', $id)
                ->orWhere('id', $id)
                ->first();

            // 2. Si la réservation n'existe pas en base de données
            if (!$booking) {
                return response()->json([
                    'booking_status' => 'initiation_failed',
                    'message'        => 'Réservation introuvable ou identifiant invalide.',
                    'pnr'            => null
                ], 404);
            }

            // 3. Mapping sémantique des statuts (pour matcher parfaitement avec votre composant Next.js)
            // Convertit les statuts BDD si nécessaire ou renvoie le statut brut nettoyé en minuscule
            $status = strtolower($booking->status);

            // Correspondance des messages utilisateurs selon l'état actuel
            $message = match($booking->status) {
            'PENDING_PAYMENT', 'WAITING_PIN' => 'En attente de votre validation PIN sur votre terminal mobile.',
            'PROCESSING'                     => 'Paiement reçu ! Sécurisation de vos chambres auprès de l\'hôtel...',
            'CONFIRMED', 'TICKETED'          => 'Votre séjour a été confirmé avec succès auprès de l’établissement.',
            'PAYMENT_INITIATION_FAILED'      => 'La passerelle de paiement locale n’a pas pu initier la demande de débit.',
            'FAILED'                         => 'L’opération a échoué. L\'hôtel n’a pas pu valider la disponibilité des chambres.',
            default                          => 'Traitement de votre dossier en cours.'
        };

        // 4. Réponse JSON propre pour le polling
        return response()->json([
            'booking_id'     => $booking->id,
            'booking_status' => $booking->status, // Renvoie par ex: PENDING_PAYMENT, PROCESSING, CONFIRMED, FAILED
            'pnr'            => $booking->reference_num, // Le numéro de confirmation pour le voyageur
            'message'        => $message,
            'currency'       => $booking->currency,
            'net_price'      => $booking->net_price,
            'updated_at'     => $booking->updated_at->toIso8601String(),
        ], 200);

    } catch (Exception $e) {
            Log::error("Erreur lors du polling du statut de réservation #{$id} : " . $e->getMessage());

            return response()->json([
                'booking_status' => 'FAILED',
                'message'        => 'Une erreur technique interne est survenue lors de la récupération du statut.',
                'pnr'            => null
            ], 500);
        }
    }

    /**
     * Récupère la liste des réservations d'hôtels de l'utilisateur connecté.
     *
     * @return JsonResponse
     */
    public function getCustomerBookings(): JsonResponse
    {
        try {
            // 1. Récupération de l'utilisateur authentifié
            $user = Auth::user();

            // 2. Requête sur les réservations de l'utilisateur (trié par date de création)
            $bookings = HotelBooking::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();

            // 3. Transformation via le Resource API pour correspondre au typage Next.js
            return response()->json([
                'success' => true,
                'data'    => CustomerBookingResource::collection($bookings)
            ], 200);

        } catch (Exception $e) {
            Log::error("Erreur récupération customer bookings pour l'user #" . Auth::id() . " : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur interne est survenue lors de la récupération de vos réservations.',
                'data'    => []
            ], 500);
        }
    }

}

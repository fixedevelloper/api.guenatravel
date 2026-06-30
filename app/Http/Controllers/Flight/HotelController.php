<?php


namespace App\Http\Controllers\Flight;

use App\Events\UserAutoRegistered;
use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerBookingResource;
use App\Http\Resources\PropertyDetailResource;
use App\Http\Resources\RoomResource;
use App\Models\HotelBooking;
use App\Models\HotelCity;
use App\Models\Property;
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
            'checkin' => 'required|date|after_or_equal:today', // Corrigé : Permet de réserver aujourd'hui
            'checkout' => 'required|date|after:checkin',
            'latitude' => 'required|numeric',
            'longitude' => 'required|numeric',
            'nationality' => 'required|string|size:2',
            'currency' => 'sometimes|string|size:3',
            'radius' => 'sometimes|integer|min:1|max:100',
            'travel_class' => 'sometimes|string|in:Economy,PremiumEconomy,Business,First',
            'max_result' => 'sometimes|integer|min:1|max:100',
            'city_name' => 'sometimes|string',
            'country_name' => 'sometimes|string',
            'hotel_codes' => 'sometimes|array',
            'hotel_codes.*' => 'string',
            'occupancy' => 'required|array|min:1',
            'occupancy.*.room_no' => 'required|integer|min:1',
            'occupancy.*.adult' => 'required|integer|min:1',
            'occupancy.*.child' => 'sometimes|integer|min:0',
            'occupancy.*.child_age' => 'sometimes|array',
            'occupancy.*.child_age.*' => 'integer|min:0|max:17',
        ]);

        // 2. Sécurité : On passe uniquement les données validées au service
        $result = $service->searchHotels($validated);

        // 3. Gestion sécurisée des erreurs du service
        if (!isset($result['success']) || !$result['success']) {
            $errorType = $result['type'] ?? 'server_error';

            $status = match($errorType){
            'validation_error' => 422,
            'invalid_response' => 404,
             'authentication_error' => 401,
            default            => 500,
        };

        return response()->json([
            'message' => $result['error_message'] ?? 'An unexpected error occurred.',
            'error_code' => $result['error_code'] ?? null,
            'type' => $errorType,
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
            'cities' => $cities,
        ];
    }

    public function searchCities(Request $request): JsonResponse
    {
        // 1. Récupérer et nettoyer le terme de recherche (?term=...)
        $term = trim($request->query('term', ''));
        $limit = (int)$request->query('limit', 10);

        // 2. Sécurité : si le terme est vide, retourner un tableau vide immédiatement
        if (empty($term)) {
            return response()->json([
                'success' => true,
                'cities' => []
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
            'cities' => $cities,
        ]);
    }
    public function getMoreResults(Request $request, HotelService $service): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
            'next_token' => 'required|string',
            'max_result' => 'sometimes|integer|min:1|max:100',
        ]);

        $result = $service->getMoreResults(
            $request->input('session_id'),
            $request->input('next_token'),
            $request->integer('max_result', 20),
    );

        if (!$result['success'] && $result['type'] === 'no_more_results') {
            return response()->json([
                'success' => false,
                'type'    => 'no_more_results',
                'message' => $result['error_message'],
                'hotels'  => [],
                'status'  => $result['status'],
            ], 200);
        }

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

        return response()->json($result, 200);
    }
    public function getRoomRates(Request $request, HotelService $service): JsonResponse
    {
        // 1. Validation stricte des Query Params issus du GET Axios
        $validated = $request->validate([
            'session_id' => 'required|string',
            'product_id' => 'required|string',
            'token_id' => 'required|string',
            'hotel_id' => 'required|string',
            'is_local'   => 'nullable|string',
        ]);

        if (filter_var($request->input('is_local'), FILTER_VALIDATE_BOOLEAN)) {
            $property = Property::find($request->input('hotel_id'));

            if (!$property) {
                return response()->json([
                    'message' => 'Hôtel local introuvable.',
                    'type'    => 'not_found'
                ], 404);
            }

            return response()->json([
                'session_id' => $validated['session_id'],
                'room_rates' => RoomResource::collection($property->rooms) ?? [],
            ], 200);
        }
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

            $status = match($errorType){
            'validation_error'    => 422,
            'authentication_error' => 401,
            'token_expired'        => 410, // Utile si l'utilisateur a trop attendu sur la page
            default                => 500,
        };

        return response()->json([
            'message' => $result['error_message'] ?? 'Unable to retrieve room rates.',
            'error_code' => $result['error_code'] ?? null,
            'type' => $errorType,
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
        // 1. Validation stricte des données d'entrée
        $validated = $request->validate([
            'session_id' => 'required|string',
            'hotel_id'   => 'required|string',
            'product_id' => 'required|string',
            'token_id'   => 'required|string',
            'is_local'   => 'nullable|string', // Précisé en string pour le check 'true'
        ]);

        // 2. CAS LOCAL : Récupération depuis la base de données locale
        // Utilisation de filter_var pour gérer proprement le booléen sous forme de chaîne "true"
        if (filter_var($request->input('is_local'), FILTER_VALIDATE_BOOLEAN)) {
            $property = Property::find($request->input('hotel_id'));

            if (!$property) {
                return response()->json([
                    'message' => 'Hôtel local introuvable.',
                    'type'    => 'not_found'
                ], 404);
            }

            return response()->json(new PropertyDetailResource($property), 200);
        }

        // 3. CAS API EXTERNE : Appel du service de réservation
        $result = $service->getHotelDetails(
            $request->input('session_id'),
            $request->input('hotel_id'),
            $request->input('product_id'),
            $request->input('token_id')
        );

        // 4. Gestion des erreurs du service externe
        if (!$result['success']) {
            $status = match($result['type'] ?? 'default') {
            'validation_error' => 422,
            'not_found'        => 404,
            default            => 500,
        };

        return response()->json([
            'message'    => $result['error_message'] ?? 'Une erreur externe est survenue.',
            'error_code' => $result['error_code'] ?? null,
            'type'       => $result['type'] ?? 'server_error',
        ], $status);
    }

        // 5. Retour de l'hôtel externe standardisé
        // Si $result['hotel'] est un modèle ou un tableau compatible, passe-le dans ta ressource
        // pour que le front Next.js reçoive EXACTEMENT la même structure qu'en local.
        return response()->json($result['hotel'], 200);
    }

    public function bookHotel(Request $request): JsonResponse
    {
        // 1. Validation stricte des données d'entrée
        $validated = $request->validate([
            'session_id' => 'required|string',
            'product_id' => 'required|string',
            'is_local' => 'required|string',
            'token_id' => 'required|string',
            'rate_basis_id' => 'nullable|string',
            'client_ref' => 'required|string',
            'customer_email' => 'required|email',
            'customer_phone' => 'required|string',
            'booking_note' => 'sometimes|string|nullable',

            // Champs de séjour et tarification
            'hotel_id' => 'required|string',
            'check_in' => 'required|date_format:Y-m-d',
            'check_out' => 'required|date_format:Y-m-d',
            'days' => 'required|integer|min:1',
            'currency' => 'required|string|size:3',
            'net_price' => 'required|numeric|min:0',
            'fare_type' => 'required|string',

            // --- VALIDATION SÉCURISÉE DES MOYENS DE PAIEMENT DYNAMIQUES ---
            'payment_method' => 'required|in:card,momo',
            'mobile_operator' => 'required_if:payment_method,momo|nullable|in:orange,mtn',
            'payment_phone' => 'required_if:payment_method,momo|nullable|string',

            // Validation des détails de la carte (uniquement si payment_method est 'card')
            'card_details' => 'required_if:payment_method,card|array|nullable',
            'card_details.number' => 'required_if:payment_method,card|string|min:13|max:19',
            'card_details.expiry' => 'required_if:payment_method,card|string|size:5', // MM/AA
            'card_details.cvc' => 'required_if:payment_method,card|string|min:3|max:4',

            // Structure des voyageurs (inchangée)
            'rooms' => 'required|array|min:1',
            'rooms.*.room_no' => 'required|integer|min:1',
            'rooms.*.adults' => 'required|array|min:1',
            'rooms.*.adults.*.title' => 'required|string',
            'rooms.*.adults.*.first_name' => 'required|string',
            'rooms.*.adults.*.last_name' => 'required|string',
            'rooms.*.children' => 'sometimes|array',
            'rooms.*.children.*.title' => 'required_with:rooms.*.children|string',
            'rooms.*.children.*.first_name' => 'required_with:rooms.*.children|string',
            'rooms.*.children.*.last_name' => 'required_with:rooms.*.children|string',
        ]);

        // Utilisation d'un bloc DB::transaction
        $response = DB::transaction(function () use ($validated) {

            // 2. Gestion de l'utilisateur (Connexion ou Inscription automatique)
            $userId = auth()->id();

            if (!$userId) {
                $user = User::where('email', $validated['customer_email'])->first();

                if (!$user) {
                    $primaryPassenger = $validated['rooms'][0]['adults'][0] ?? null;
                    $firstName = $primaryPassenger ? $primaryPassenger['first_name'] : 'Client';
                    $lastName = $primaryPassenger ? $primaryPassenger['last_name'] : 'Voyage';

                    $temporaryPassword = Str::random(10);

                    $user = User::create([
                        'name' => ucfirst($firstName) . ' ' . strtoupper($lastName),
                        'email' => $validated['customer_email'],
                        'phone' => $validated['customer_phone'],
                        'password' => Hash::make($temporaryPassword),
                    ]);

                    event(new UserAutoRegistered($user, $temporaryPassword));
                }

                $userId = $user->id;
            }

            // 3. Sauvegarde initiale au statut PENDING_PAYMENT
            $booking = HotelBooking::create([
                'user_id' => $userId,
                'client_ref_num' => $validated['client_ref'],
                'product_id' => $validated['product_id'],
                'hotel_id' => $validated['hotel_id'],
                'session_id' => $validated['session_id'],
                'token_id' => $validated['token_id'],
                'rate_basis_id' => $validated['rate_basis_id'] ?? '0200',
                'check_in' => $validated['check_in'],
                'check_out' => $validated['check_out'],
                'days' => $validated['days'],
                'currency' => $validated['currency'],
                'net_price' => $validated['net_price'],
                'fare_type' => $validated['fare_type'],
                'cancellation_policy' => [],
                'status' => 'PENDING_PAYMENT',
                'customer_email' => $validated['customer_email'],
                'customer_phone' => $validated['customer_phone'],
                'booking_note' => $validated['booking_note'] ?? null,
                'rooms_booked' => [],
                'pax_details' => $validated['rooms'],
                'api_request_payload' => $validated
            ]);

            // 4. Détermination des variables de routage du paiement
            // Si c'est MoMo, on utilise le 'payment_phone' (souvent spécifique), sinon le numéro client
            $phoneToDebit = $validated['payment_method'] === 'momo'
                ? $validated['payment_phone']
                : $validated['customer_phone'];

            // 5. Appel à la passerelle de paiement mis à jour
            $paymentResult = $this->paymentService->initiateLocalPayment(
                $validated['payment_method'], // 'card' ou 'momo'
                $phoneToDebit,
                $validated['net_price'],
                $booking->id,
                $validated['currency'],
                [
                    'mobile_operator' => $validated['mobile_operator'] ?? null,
                    'card_details'    => $validated['card_details'] ?? null
                ]
            );

            if (!$paymentResult) {
                $booking->update(['status' => 'PAYMENT_INITIATION_FAILED']);

                return response()->json([
                    'status' => 'error',
                    'message' => 'La passerelle de paiement locale n\'a pas pu traiter la transaction.'
                ], 400);
            }

            // 6. Formulation de la réponse dynamique pour Next.js
            // Cas A : Redirection requise (Toujours le cas pour une Carte, ou certaines interfaces MoMo)
            if (is_array($paymentResult) && isset($paymentResult['type']) && $paymentResult['type'] === 'redirect') {
                return response()->json([
                    'status' => 'redirect_required',
                    'message' => 'Redirection vers l\'interface sécurisée du prestataire.',
                    'redirect_url' => $paymentResult['redirect_url'],
                    'booking_id' => $booking->id
                ], 200);
            }

            // Cas B : Paiement asynchrone Direct MoMo (Push USSD)
            return response()->json([
                'status' => 'waiting_confirmation',
                'message' => 'Demande de paiement envoyée. Veuillez valider le prompt USSD de confirmation sur votre téléphone.',
                'booking_id' => $booking->id,
                'session_id' => $booking->session_id
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
                    'message' => 'Réservation introuvable ou identifiant invalide.',
                    'pnr' => null
                ], 404);
            }

            // 3. Mapping sémantique des statuts (pour matcher parfaitement avec votre composant Next.js)
            // Convertit les statuts BDD si nécessaire ou renvoie le statut brut nettoyé en minuscule
            $status = strtolower($booking->status);

            // Correspondance des messages utilisateurs selon l'état actuel
            $message = match($booking->status){
            'PENDING_PAYMENT', 'WAITING_PIN' => 'En attente de votre validation PIN sur votre terminal mobile.',
            'PROCESSING'                     => 'Paiement reçu ! Sécurisation de vos chambres auprès de l\'hôtel...',
            'CONFIRMED', 'TICKETED'          => 'Votre séjour a été confirmé avec succès auprès de l’établissement.',
            'PAYMENT_INITIATION_FAILED'      => 'La passerelle de paiement locale n’a pas pu initier la demande de débit.',
            'FAILED'                         => 'L’opération a échoué. L\'hôtel n’a pas pu valider la disponibilité des chambres.',
            default                          => 'Traitement de votre dossier en cours.'
        };

        // 4. Réponse JSON propre pour le polling
        return response()->json([
            'booking_id' => $booking->id,
            'booking_status' => $booking->status, // Renvoie par ex: PENDING_PAYMENT, PROCESSING, CONFIRMED, FAILED
            'pnr' => $booking->reference_num, // Le numéro de confirmation pour le voyageur
            'message' => $message,
            'currency' => $booking->currency,
            'net_price' => $booking->net_price,
            'updated_at' => $booking->updated_at->toIso8601String(),
        ], 200);

    } catch (Exception $e) {
            Log::error("Erreur lors du polling du statut de réservation #{$id} : " . $e->getMessage());

            return response()->json([
                'booking_status' => 'FAILED',
                'message' => 'Une erreur technique interne est survenue lors de la récupération du statut.',
                'pnr' => null
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
                'data' => CustomerBookingResource::collection($bookings)
            ], 200);

        } catch (Exception $e) {
            Log::error("Erreur récupération customer bookings pour l'user #" . Auth::id() . " : " . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Une erreur interne est survenue lors de la récupération de vos réservations.',
                'data' => []
            ], 500);
        }
    }

    public function filterHotels(Request $request, HotelService $service): JsonResponse
    {
        $request->validate([
            'session_id' => 'required|string',
            'max_result' => 'sometimes|integer|min:1|max:100',
            'filters' => 'sometimes|array',
            'filters.price' => 'sometimes|array',
            'filters.price.min' => 'sometimes|numeric|min:0',
            'filters.price.max' => 'sometimes|numeric|gt:filters.price.min',
            'filters.rating' => 'sometimes|array',
            'filters.rating.*' => 'integer|between:1,5',
            'filters.tripadvisor_rating' => 'sometimes|array',
            'filters.tripadvisor_rating.*' => 'numeric|between:1,5',
            'filters.hotel_name' => 'sometimes|string',
            'filters.fare_type' => 'sometimes|string|in:Refundable,Non-Refundable',
            'filters.property_type' => 'sometimes|string',
            'filters.facilities' => 'sometimes|array',
            'filters.facilities.*' => 'string',
            'filters.sorting' => 'sometimes|string|in:price-low-high,price-high-low',
            'filters.locality' => 'sometimes|array',
            'filters.locality.*' => 'string',
        ]);

        $result = $service->filterHotels($request->all());

        // Cas "no_results" → 200 avec liste vide (pas une erreur HTTP)
        if (!$result['success'] && $result['type'] === 'no_results') {
            return response()->json([
                'success' => false,
                'type' => 'no_results',
                'message' => $result['error_message'],
                'hotels' => [],
                'status' => $result['status'],
            ], 200);
        }

        if (!$result['success']) {
            $status = match($result['type']){
            'validation_error' => 422,
            default            => 500,
        };

        return response()->json([
            'message' => $result['error_message'],
            'error_code' => $result['error_code'] ?? null,
            'type' => $result['type'],
        ], $status);
    }

        return response()->json($result, 200);

    }
    public function getBookingDetails(Request $request, HotelService $service): JsonResponse
    {
        $request->validate([
            'supplier_confirmation_num' => 'required|string',
            'reference_num'             => 'required|string',
        ]);

        $result = $service->getBookingDetails(
            $request->input('supplier_confirmation_num'),
            $request->input('reference_num'),
    );

        if (!$result['success']) {
            $status = match($result['type']) {
            'validation_error' => 422,
            'api_error'        => 400,
            default            => 500,
        };

        return response()->json([
            'message' => $result['error_message'],
            'type'    => $result['type'],
        ], $status);
    }

        return response()->json($result['booking'], 200);
    }
}

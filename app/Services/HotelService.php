<?php

namespace App\Services;

use App\Helpers\AppHelper;
use http\Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HotelService
{
    protected string $baseUrl;
    protected array $authData;

    public function __construct()
    {
        $this->baseUrl  = config('travelopro.base_url');
        $this->authData = [
            'user_id'       => config('travelopro.user_id'),
            'user_password' => config('travelopro.user_password'),
            'access'        => config('travelopro.access'),
            'ip_address'    => config('travelopro.ip_address'),
        ];
    }
    public function getStaticHotel(array $params): array
    {
        try {
            $response = Http::timeout(20)->get(
                'https://travelnext.works/api/hotel-api-v6/hotelDetails',
                array_merge($this->authData, [
                    'from'         => $params['from'] ?? '1',
                    'to'           => $params['to'] ?? '100',
                    'city_name'    => $params['city_name'],
                    'country_name' => $params['country_name'],
                ])
            );

            if ($response->failed()) {
                Log::error("Échec de la récupération des hôtels statiques: " . $response->body());
                return ['success' => false, 'message' => 'Impossible de contacter l’API externe.'];
            }

            $data = $response->json();
            $hotels = $data['hotels'] ?? [];

            if (empty($hotels)) {
                return [
                    'success' => true,
                    'total'   => $data['total'] ?? 0,
                    'count'   => 0,
                    'message' => 'Aucun hôtel trouvé pour ces critères.'
                ];
            }

            return [
                'success' => true,
                'total'   => $data['total'] ?? count($hotels),
                'count'   => count($hotels),
                'message' => count($hotels) . ' hôtels mis à jour ou insérés avec succès.'
            ];

        } catch (Exception $e) {
            Log::error("Erreur critique getStaticHotel : " . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Fonction de parsing : Nettoie, sécurise et formate un hôtel brut reçu de l'API.
     *
     * @param array $raw
     * @return array
     */
    private function parseHotel(array $raw): array
    {
        return [
            'hotel_external_id' => (string) ($raw['hotelId'] ?? ''),
            'name'              => trim($raw['name'] ?? 'Hôtel sans nom'),
            'city'              => trim($raw['city'] ?? ''),
            'state'             => !empty($raw['state']) ? trim($raw['state']) : null,
            'country'           => trim($raw['country'] ?? ''),
            'address'           => !empty($raw['address']) ? trim($raw['address']) : null,
            'latitude'          => !empty($raw['latitude']) ? (float) $raw['latitude'] : null,
            'longitude'         => !empty($raw['longitude']) ? (float) $raw['longitude'] : null,
            'hotel_type'        => trim($raw['hotelType'] ?? 'Hotels'),
            'rating'            => isset($raw['rating']) ? (float) $raw['rating'] : 0.0,
            'email'             => !empty($raw['email']) ? strtolower(trim($raw['email'])) : null,
            'phone'             => !empty($raw['phone']) ? trim($raw['phone']) : null,
            'description'       => !empty($raw['description']) ? trim($raw['description']) : null,

            // Sécurisation du tableau d'images encodé en JSON pour la BDD
            'images'            => json_encode(is_array($raw['images'] ?? null) ? $raw['images'] : []),

            'created_at'        => now(),
            'updated_at'        => now(),
        ];
    }
    public function searchHotels(array $params): array
    {
        // 1. Récupération du contexte client global (IP, Langue, Pays)
        $context = AppHelper::getClientContext();

        // 2. Détermination dynamique de la devise par défaut selon le pays détecté
        // Exemple : Si le client vient du Cameroun (CM), du Gabon (GA), etc., on lui propose le XAF
        $defaultCurrency = match ($context['country_code']) {
        'CM', 'GA', 'CG', 'TD', 'CF', 'GQ' => 'XAF',
        'CI', 'SN', 'BF', 'ML', 'NE', 'TG', 'BJ', 'GW' => 'XOF',
        'FR', 'DE', 'IT', 'ES', 'BE' => 'EUR',
        default => 'USD'
    };

    // 3. Construction du payload avec les fallbacks intelligents issus du contexte
    $payload = array_merge($this->authData, [
        // Si le paramètre 'currency' n'est pas envoyé par le front-end, on prend la devise du pays détecté
        'requiredCurrency' => $params['currency'] ?? $defaultCurrency,

        // Si le voyageur ne spécifie pas sa nationalité, on assume que c'est celle de son pays de connexion
        'nationality'      => $params['nationality'] ?? $context['country_code'],

        'checkin'          => $params['checkin'],
        'checkout'         => $params['checkout'],
        'latitude'         => $params['latitude'],
        'longitude'        => $params['longitude'],
        'radius'           => $params['radius'] ?? 20,
        'maxResult'        => $params['max_result'] ?? 20,
        'city_name'        => $params['city_name'] ?? '',
        'country_name'     => $params['country_name'] ?? '',
        'hotelCodes'       => $params['hotel_codes'] ?? [],
        'occupancy'        => $this->buildOccupancy($params['occupancy']),
    ]);

    // 4. Exécution de la requête avec un timeout de sécurité (très important pour les recherches d'hôtels)
    $response = Http::timeout(30)->post(
        'https://travelnext.works/api/hotel-api-v6/hotel_search',
        $payload
    );

    if ($response->failed()) {
        return [
            'success'       => false,
            'type'          => 'network_error',
            'error_message' => 'Le serveur de recherche d\'hôtels est temporairement indisponible.',
        ];
    }

    $data = $response->json();

    logger($data);
    // Erreur de validation (structure plate de l'API externe)
    if (isset($data['Errors'])) {
        return [
            'success'       => false,
            'type'          => 'validation_error',
            'error_code'    => $data['Errors']['ErrorCode'] ?? null,
            'error_message' => $data['Errors']['ErrorMessage'] ?? 'Erreur inconnue',
        ];
    }

    if (!isset($data['itineraries'])) {
        return [
            'success'       => false,
            'type'          => 'invalid_response',
            //'error_message' => 'Réponse inattendue de l\'API.',
            'error_message' => $data['status']['error'],
        ];
    }

    return [
        'success' => true,
        'status'  => $this->parseSearchStatus($data['status'] ?? []),
        'hotels'  => $this->parseHotels($data['itineraries']),
    ];
}
    public function getHotelDetails(
        string $sessionId,
        string $hotelId,
        string $productId,
        string $tokenId
    ): array {
        $response = Http::timeout(20)->get(
            'https://travelnext.works/api/hotel-api-v6/hotelDetails',
            array_merge($this->authData, [
                'sessionId' => $sessionId,
                'hotelId'   => $hotelId,
                'productId' => $productId,
                'tokenId'   => $tokenId,
            ])
        );

        $data = $response->json();

        if (isset($data['Errors'])) {
            return [
                'success'       => false,
                'type'          => 'validation_error',
                'error_code'    => $data['Errors']['ErrorCode']    ?? null,
                'error_message' => $data['Errors']['ErrorMessage'] ?? 'Erreur inconnue',
            ];
        }

        if (!isset($data['hotelId'])) {
            return [
                'success'       => false,
                'type'          => 'invalid_response',
                'error_message' => 'Réponse inattendue de l\'API.',
            ];
        }

        return [
            'success' => true,
            'hotel'   => $this->parseHotelDetails($data),
        ];
    }

    private function parseHotelDetails(array $data): array
    {
        return [
            'hotel_id'    => $data['hotelId'],
            'name'        => $data['name'],
            'address'     => $data['address'],
            'city'        => $data['city'],
            'postal_code' => $data['postalCode'] ?? null,
            'latitude'    => (float) $data['latitude'],
            'longitude'   => (float) $data['longitude'],
            'rating'      => (int)   $data['hotelRating'],
            'description' => $data['description']['content'] ?? null,
            'facilities'  => $data['facilities']             ?? [],
            'images'      => $this->parseHotelImages($data['hotelImages'] ?? []),
        ];
    }

    private function parseHotelImages(array $images): array
    {
        return array_map(fn($image) => [
            'caption' => $image['caption'] ?? null,
            'url'     => $image['url'],
        ], array_filter($images, fn($img) => !empty($img['url'])));
    }
    public function getRoomRates(
        string $sessionId,
        string $productId,
        string $tokenId,
        string $hotelId
    ): array {
        $payload = array_merge($this->authData, [
            'sessionId' => $sessionId,
            'productId' => $productId,
            'tokenId'   => $tokenId,
            'hotelId'   => $hotelId,
        ]);

        $response = Http::timeout(20)->post(
            'https://travelnext.works/api/hotel-api-v6/get_room_rates',
            $payload
        );

        $data = $response->json();

        // Erreur de validation (structure plate)
        if (isset($data['Errors'])) {
            return [
                'success'       => false,
                'type'          => 'validation_error',
                'error_code'    => $data['Errors']['ErrorCode']    ?? null,
                'error_message' => $data['Errors']['ErrorMessage'] ?? 'Erreur inconnue',
            ];
        }

        if (!isset($data['roomRates']['perBookingRates'])) {
            return [
                'success'       => false,
                'type'          => 'invalid_response',
                'error_message' => 'Réponse inattendue de l\'API.',
            ];
        }

        return [
            'success'    => true,
            'session_id' => $data['sessionId'],
            'hotel_id'   => $data['hotelId'],
            'token_id'   => $data['tokenId'],
            'room_rates' => $this->parseRoomRates($data['roomRates']['perBookingRates']),
        ];
    }
    public function bookHotel(array $params): array
    {
        $payload = array_merge($this->authData, [
            'sessionId'     => $params['session_id'],
            'productId'     => $params['product_id'],
            'tokenId'       => $params['token_id'],
            'rateBasisId'   => $params['rate_basis_id'],
            'clientRef'     => $params['client_ref'],
            'customerEmail' => $params['customer_email'],
            'customerPhone' => $params['customer_phone'],
            'bookingNote'   => $params['booking_note'] ?? '',
            'paxDetails'    => $this->buildPaxDetails($params['rooms']),
        ]);

        $response = Http::timeout(30)->post(
            'https://travelnext.works/api/hotel-api-v6/hotel_book',
            $payload
        );

        $data = $response->json();

        logger($data);
        if (isset($data['Errors'])) {
            return [
                'success'       => false,
                'type'          => 'validation_error',
                'error_code'    => $data['Errors']['ErrorCode']    ?? null,
                'error_message' => $data['Errors']['ErrorMessage'] ?? 'Erreur inconnue',
            ];
        }

        if (!empty($data['error'])) {
            return [
                'success'       => false,
                'type'          => 'booking_failed',
                'error_message' => $data['error'],
            ];
        }

        if (($data['status'] ?? '') !== 'CONFIRMED') {
            return [
                'success'       => false,
                'type'          => 'not_confirmed',
                'status'        => $data['status'] ?? null,
                'error_message' => 'La réservation n\'a pas été confirmée.',
            ];
        }

        return [
            'success' => true,
            'booking' => $this->parseBookingResponse($data),
        ];
    }
    public function filterHotels(array $params): array
    {
        $payload = [
            'sessionId' => $params['session_id'],
            'maxResult' => $params['max_result'] ?? 20,
            'filters'   => $this->buildFilters($params['filters'] ?? []),
        ];

        $response = Http::timeout(20)->post(
            'https://travelnext.works/api/hotel-api-v6/filterResults',
            $payload
        );

        $data = $response->json();

        // Erreur de validation (structure plate)
        if (isset($data['Errors'])) {
            return [
                'success'       => false,
                'type'          => 'validation_error',
                'error_code'    => $data['Errors']['ErrorCode']    ?? null,
                'error_message' => $data['Errors']['ErrorMessage'] ?? 'Erreur inconnue',
            ];
        }

        // Erreur dans status (ex: "No Results found...")
        if (isset($data['status']['error']) && !empty($data['status']['error'])) {
            return [
                'success'       => false,
                'type'          => 'no_results',
                'error_message' => $data['status']['error'],
                'hotels'        => [],   // Tableau vide pour éviter les erreurs côté client
                'status'        => [
                    'session_id'   => null,
                    'more_results' => false,
                    'next_token'   => null,
                    'filter_key'   => null,
                ],
            ];
        }

        // Réponse sans itineraries
        if (!isset($data['itineraries'])) {
            return [
                'success'       => false,
                'type'          => 'invalid_response',
                'error_message' => 'Réponse inattendue de l\'API.',
                'hotels'        => [],
            ];
        }

        return [
            'success'    => true,
            'status'     => $this->parseSearchStatus($data['status'] ?? []),
            'hotels'     => $this->parseHotels($data['itineraries']),
            'filter_key' => $data['status']['filterKey'] ?? null,
        ];
    }
    public function getBookingDetails(string $supplierConfirmationNum, string $referenceNum): array
    {
        $response = Http::timeout(20)->post(
            'https://travelnext.works/api/hotel-api-v6/bookingDetails',
            array_merge($this->authData, [
                'supplierConfirmationNum' => $supplierConfirmationNum,
                'referenceNum'            => $referenceNum,
            ])
        );

        $data = $response->json();

        if (isset($data['Errors'])) {
            return [
                'success'       => false,
                'type'          => 'validation_error',
                'error_code'    => $data['Errors']['ErrorCode']    ?? null,
                'error_message' => $data['Errors']['ErrorMessage'] ?? 'Erreur inconnue',
            ];
        }

        if (!empty($data['error'])) {
            return [
                'success'       => false,
                'type'          => 'api_error',
                'error_message' => $data['error'],
            ];
        }

        if (!isset($data['roomBookDetails'])) {
            return [
                'success'       => false,
                'type'          => 'invalid_response',
                'error_message' => 'Réponse inattendue de l\'API.',
            ];
        }

        return [
            'success' => true,
            'booking' => $this->parseBookingDetails($data),
        ];
    }

    private function parseBookingDetails(array $data): array
    {
        $details = $data['roomBookDetails'];

        return [
            'status'                    => $data['status'],
            'supplier_confirmation_num' => $data['supplierConfirmationNum'],
            'reference_num'             => $data['referenceNum'],
            'client_ref_num'            => $data['clientRefNum'],
            'product_id'                => $data['productId'],
            'hotel'                     => [
                'hotel_id'    => $details['hotelId'],
                'name'        => $details['hotelName'],
                'address'     => $details['address'],
                'city'        => $details['city'],
                'country'     => $details['country'],
                'postal_code' => $details['postalCode']  ?? null,
                'latitude'    => $details['latitude']    ? (float) $details['latitude']  : null,
                'longitude'   => $details['longitude']   ? (float) $details['longitude'] : null,
                'email'       => $details['email']       ?? null,
                'phone'       => $this->cleanPhone($details['phone'] ?? ''),
                'image'       => !empty($details['image']) ? $details['image'] : null,
                'rating'      => $details['rating']      ?? null,
            ],
            'check_in'             => $details['checkIn'],
            'check_out'            => $details['checkOut'],
            'days'                 => (int) $details['days'],
            'currency'             => $details['currency'],
            'net_price'            => (float) $details['NetPrice'],
            'fare_type'            => $details['fareType'],
            'cancellation_policy'  => !empty($details['cancellationPolicy'])
                ? $this->parseCancellationPolicy($details['cancellationPolicy'])
                : [],
            'cancel_reference_num' => $details['cancelReferenceNum'] ?? null,
            'customer_email'       => $details['customerEmail']      ?? null,
            'customer_phone'       => $details['customerPhone']      ?? null,
            'booking_date_time'    => $details['bookingDateTime']    ?? null,
            'rooms'                => $this->parseBookedRooms($details['rooms'] ?? []),
        ];
    }

// Nettoie les <br> dans les numéros de téléphone
    private function cleanPhone(string $phone): ?string
    {
        if (empty($phone)) return null;
        $numbers = array_filter(
            array_map('trim', explode('<br>', $phone)),
            fn($p) => !empty($p)
        );
        return implode(' / ', array_unique($numbers));
    }
    private function buildFilters(array $filters): array
    {
        $built = [];

        if (isset($filters['price'])) {
            $built['price'] = [
                'min' => $filters['price']['min'] ?? 0,
                'max' => $filters['price']['max'] ?? 999999,
            ];
        }

        if (!empty($filters['rating'])) {
            $built['rating'] = is_array($filters['rating'])
                ? implode(',', $filters['rating'])
                : $filters['rating'];
        }

        if (!empty($filters['tripadvisor_rating'])) {
            $built['tripadvisorRating'] = is_array($filters['tripadvisor_rating'])
                ? implode(',', $filters['tripadvisor_rating'])
                : $filters['tripadvisor_rating'];
        }

        if (!empty($filters['hotel_name'])) {
            $built['hotelName'] = $filters['hotel_name'];
        }

        if (!empty($filters['fare_type'])) {
            $built['faretype'] = $filters['fare_type'];
        }

        if (!empty($filters['property_type'])) {
            $built['propertyType'] = $filters['property_type'];
        }

        if (!empty($filters['facilities'])) {
            $built['facility'] = is_array($filters['facilities'])
                ? implode(',', $filters['facilities'])
                : $filters['facilities'];
        }

        if (!empty($filters['sorting'])) {
            $built['sorting'] = $filters['sorting'];
        }

        if (!empty($filters['locality'])) {
            $built['locality'] = is_array($filters['locality'])
                ? implode(',', $filters['locality'])
                : $filters['locality'];
        }

        return $built;
    }
    private function buildPaxDetails(array $rooms): array
    {
        return array_map(function ($room) {
            $pax = ['room_no' => $room['room_no']];

            // Adultes
            if (!empty($room['adults'])) {
                $pax['adult'] = [
                    'title'     => array_column($room['adults'], 'title'),
                    'firstName' => array_column($room['adults'], 'first_name'),
                    'lastName'  => array_column($room['adults'], 'last_name'),
                ];
            }

            // Enfants
            if (!empty($room['children'])) {
                $pax['child'] = [
                    'title'     => array_column($room['children'], 'title'),
                    'firstName' => array_column($room['children'], 'first_name'),
                    'lastName'  => array_column($room['children'], 'last_name'),
                ];
            }

            return $pax;
        }, $rooms);
    }

    private function parseBookingResponse(array $data): array
    {
        $details = $data['roomBookDetails'];

        return [
            'status'                   => $data['status'],
            'supplier_confirmation_num'=> $data['supplierConfirmationNum'],
            'reference_num'            => $data['referenceNum'],
            'client_ref_num'           => $data['clientRefNum'],
            'product_id'               => $data['productId'],
            'hotel_id'                 => $details['hotelId'],
            'check_in'                 => $details['checkIn'],
            'check_out'                => $details['checkOut'],
            'days'                     => $details['days'],
            'currency'                 => $details['currency'],
            'net_price'                => (float) $details['NetPrice'],
            'fare_type'                => $details['fareType'],
            'cancellation_policy'      => $this->parseCancellationPolicy(
                $details['cancellationPolicy']
            ),
            'customer_email'           => $details['customerEmail'],
            'customer_phone'           => $details['customerPhone'],
            'rooms'                    => $this->parseBookedRooms($details['rooms']),
        ];
    }

    private function parseBookedRooms(array $rooms): array
    {
        return array_map(fn($room) => [
            'name'        => $room['name'],
            'description' => $room['description'],
            'board_type'  => $room['boardType'],
            'guests'      => $room['paxDetails']['name'] ?? [],
        ], $rooms);
    }
    private function parseRoomRates(array $rates): array
    {
        return array_map(fn($rate) => [
            'product_id'            => $rate['productId'],
            'room_type'             => $rate['roomType'],
            'description'           => $rate['description'],
            'room_code'             => $rate['roomCode'],
            'fare_type'             => $rate['fareType'],
            'rate_basis_id'         => $rate['rateBasisId'],
            'currency'              => $rate['currency'],
            'net_price'             => (float) $rate['netPrice'],
            'board_type'            => $rate['boardType'],
            'max_occupancy'         => (int) $rate['maxOccupancyPerRoom'],
            'inventory_type'        => $rate['inventoryType'],
            'cancellation_policy'   => $this->parseCancellationPolicy($rate['cancellationPolicy']),
            'room_images'           => $rate['roomImages'] ?? [],
            'facilities'            => $rate['facilities']  ?? [],
        ], $rates);
    }

    private function parseCancellationPolicy(string $policy): array
    {
        // Sépare les règles par le délimiteur |t|
        return collect(explode('|t|', $policy))
            ->map(fn($rule) => trim($rule))
            ->filter()
            ->values()
            ->all();
    }
    public function getCities(int $from = 1, int $to = 100): array
    {
        $response = Http::get('https://travelnext.works/api/hotel-api-v6/cities', [
            'from'          => $from,
            'to'            => $to,
            'user_id'       => config('travelopro.user_id'),
            'user_password' => config('travelopro.user_password'),
            'ip_address'    => config('travelopro.ip_address'),
            'access'        => config('travelopro.access'),
        ]);

        $data = $response->json();

        if (!isset($data['cities'])) {
            return [
                'success'       => false,
                'type'          => 'invalid_response',
                'error_message' => 'Réponse inattendue de l\'API.',
            ];
        }

        return [
            'success' => true,
            'cities'  => $this->parseCities($data['cities']),
        ];
    }

    private function parseCities(array $cities): array
    {
        return array_map(fn($city) => [
            'id'           => $city['id'],
            'city_name'    => $city['city_name'],
            'country_name' => $city['country_name'],
            'latitude'     => (float) $city['latitude'],
            'longitude'    => (float) $city['longitude'],
        ], $cities);
    }
    private function buildOccupancy(array $occupancy): array
    {
        return array_map(function ($room) {
            return [
                'room_no'   => $room['room_no'],
                'adult'     => $room['adult'],
                'child'     => $room['child']     ?? 0,
                'child_age' => $room['child_age'] ?? [0],
            ];
        }, $occupancy);
    }

    private function parseSearchStatus(array $status): array
    {
        return [
            'session_id'    => $status['sessionId']    ?? null,
            'more_results'  => $status['moreResults']  ?? false,
            'next_token'    => $status['nextToken']     ?? null,
            'total_results' => $status['totalResults'] ?? 0,
        ];
    }

    private function parseHotels(array $itineraries): array
    {
        return array_map(function ($hotel) {
            return [
                'hotel_id'           => $hotel['hotelId'],
                'twx_hotel_id'       => $hotel['twxHotelId'],
                'product_id'         => $hotel['productId'],
                'token_id'           => $hotel['tokenId'],
                'name'               => $hotel['hotelName'],
                'rating'             => $hotel['hotelRating'],
                'property_type'      => $hotel['propertyType'],
                'fare_type'          => $hotel['fareType'],
                'total'              => (float) $hotel['total'],
                'currency'           => $hotel['currency'],
                'city'               => $hotel['city'],
                'locality'           => $hotel['locality'],
                'country'            => $hotel['country'],
                'address'            => $hotel['address'],
                'postal_code'        => $hotel['postalCode'] ?? null,
                'phone'              => $hotel['phone']      ?? null,
                'email'              => $hotel['email']      ?? null,
                'latitude'           => (float) $hotel['latitude'],
                'longitude'          => (float) $hotel['longitude'],
                'distance'           => [
                    'value' => $hotel['distanceValue'],
                    'unit'  => $hotel['distanceUnit'],
                ],
                'thumbnail'          => $hotel['thumbNailUrl'] ?? null,
                'facilities'         => $hotel['facilities']   ?? [],
                'trip_advisor'       => [
                    'rating'  => $hotel['tripAdvisorRating']  ?? null,
                    'reviews' => $hotel['tripAdvisorReview']  ?? null,
                ],
            ];
        }, $itineraries);
    }

}

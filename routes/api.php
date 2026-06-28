<?php

use App\Http\Controllers\Admin\AmenityController;
use App\Http\Controllers\CustomerDashboardController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\Flight\CustomerFlightBookingController;
use App\Http\Controllers\Flight\FlightController;
use App\Http\Controllers\Flight\FlightTravelOproController;
use App\Http\Controllers\Flight\HotelController;
use App\Http\Controllers\Flight\TicketController;
use App\Http\Controllers\Host\HostBookingController;
use App\Http\Controllers\Host\HostDashboardController;
use App\Http\Controllers\Host\HostPayoutController;
use App\Http\Controllers\Host\HostRegisterController;
use App\Http\Controllers\Host\HostSettingsController;
use App\Http\Controllers\Host\RoomCalendarController;
use App\Http\Controllers\ReviewController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\{
    AuthController, SearchController, BookingController, PaymentController,
    WalletController, WithdrawalController, PropertyController, RoomController
};
use App\Http\Controllers\Admin\AdminWithdrawalController;
use App\Http\Controllers\Host\HostPropertyController;

/*
|--------------------------------------------------------------------------
| 1. AUTHENTICATION (Public)
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->name('login');
Route::post('/host/register', [HostRegisterController::class, 'register']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', fn(Request $request) => response()->json(['user' => $request->user()]));
});

/*
|--------------------------------------------------------------------------
| 2. CATALOGUE PUBLIC (Lecture seule)
|--------------------------------------------------------------------------
*/
// Recherche et découverte
Route::get('/search', [SearchController::class, 'index']);
Route::get('/hotels/search', [HotelController::class, 'search']);
Route::get('/hotels/cities', [HotelController::class, 'getCities']);
Route::get('/hotels/cities/search', [HotelController::class, 'searchCities']);
Route::get('/hotels/room-rates', [HotelController::class, 'getRoomRates']);
Route::get('/hotels/details', [HotelController::class, 'getHotelDetails']);
Route::get('/hotels/bookings-status/{id}', [HotelController::class, 'getBookingStatus']);
Route::get('/airports/search', [SearchController::class, 'search']);
Route::post('/hotels/book', [HotelController::class, 'bookHotel']);
Route::prefix('flights')->group(function () {
    Route::post('/booking/session/init', [FlightController::class, 'CreateInitSession']);
    Route::post('/booking/passengers', [FlightController::class, 'addPassengers']);
    // ÉTAPE 1 : Rechercher des offres de vols (Next.js -> Laravel -> Travelport)
   // Route::post('/search', [FlightController::class, 'search'])->name('api.flights.search');
    Route::post('/search', [FlightTravelOproController::class, 'search'])->name('api.flights.search');
    Route::post('/extra-services', [FlightTravelOproController::class, 'getExtraServices']);
    Route::post('/revalidate', [FlightTravelOproController::class, 'revalidate']);
     Route::post('/fare-rules', [FlightTravelOproController::class, 'fareRules']);
    // ÉTAPE 2 & 3 : Vérification de l'inventaire, paiement Mobile Money et émission immédiate
    Route::post('/verify-and-pay', [FlightTravelOproController::class, 'verifyAndPay'])->name('api.flights.verify_pay');
    Route::get('/my-bookings-status/{id}', [FlightController::class, 'getBookingStatus']);
});

// Groupement dédié à la gestion des billets et du cycle de vie des PNR existants
Route::prefix('tickets')->group(function () {

    // Inspecter un PNR existant (via son code de réservation à 6 caractères / Locator)
    Route::post('/inspect', [TicketController::class, 'fetchAndInspect'])->name('api.tickets.inspect');

    // Émettre manuellement ou en différé les e-tickets d'un dossier déjà réservé
    Route::post('/issue', [TicketController::class, 'issue'])->name('api.tickets.issue');

});
// Accès aux propriétés et chambres
Route::get('/properties', [PropertyController::class, 'index']);
Route::get('/properties/{property}', [PropertyController::class, 'show']);

// Accès aux chambres
Route::get('/rooms', [RoomController::class, 'index']); // Liste globale
// Route dédiée à la recherche avancée
Route::get('/rooms/search', [RoomController::class, 'search']);
Route::get('/rooms/offers', [RoomController::class, 'offers']); // Offres promotionnelles
Route::get('/rooms/{room}', [RoomController::class, 'show']); // Détail d'une chambre précise
Route::get('/amenities', [AmenityController::class, 'index']);
Route::get('/amenities/room', [AmenityController::class, 'amenties']);
/*
|--------------------------------------------------------------------------
| 3. TUNNEL DE RÉSERVATION (Voyageurs)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('/bookings', [BookingController::class, 'store']);
    Route::get('/my-bookings', [BookingController::class, 'index']);
    Route::get('/customer/flights/bookings', [CustomerFlightBookingController::class, 'index']);

    // Route optionnelle pour rafraîchir ou voir le détail live du PNR
    Route::get('/customer/flights/bookings/{pnr}/sync', [CustomerFlightBookingController::class, 'syncLiveGds']);
    Route::post('/bookings/{booking}/pay', [PaymentController::class, 'pay']);
    Route::get('/customer/dashboard-data', [CustomerDashboardController::class, 'index']);
   // Route::get('/customer/bookings', [CustomerDashboardController::class, 'bookings']);
    Route::get('/customer/bookings', [HotelController::class, 'getCustomerBookings']);
    Route::get('/customer/favorites', [FavoriteController::class, 'index']);
    Route::post('/customer/favorites', [FavoriteController::class, 'store']);
    Route::delete('/customer/favorites/{propertyId}', [FavoriteController::class, 'destroy']);

    Route::get('/customer/reviews', [ReviewController::class, 'index']);
    Route::post('/customer/reviews', [ReviewController::class, 'store']);
    Route::get('/customer/wallet', [WalletController::class, 'show']);
});


/*
|--------------------------------------------------------------------------
| 4. ESPACE HÔTE (Gestion catalogue & Finance)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:host'])->prefix('host')->group(function () {
    Route::get('/dashboard-metrics', [HostDashboardController::class, 'getMetrics']);
    Route::get('/rooms/{room}/calendar', [RoomCalendarController::class, 'index']);
    Route::post('/rooms/{room}/calendar/bulk-update', [RoomCalendarController::class, 'bulkUpdate']);
    // 1. Gestion des propriétés
    Route::apiResource('properties', HostPropertyController::class);

    // 2. Route additionnelle pour lister TOUTES les chambres de l'hôte (tous hôtels confondus)
    Route::get('rooms', [RoomController::class, 'index']);

    // 3. Gestion des chambres imbriquées (uniquement index, store grâce au shallow)
    Route::apiResource('properties.rooms', RoomController::class)->shallow()->except(['index']);

    // NB: On extrait l'index du shallow pour le lier manuellement afin de supporter
    // le paramètre optionnel $propertyId de ton contrôleur
    Route::get('properties/{property}/rooms', [RoomController::class, 'indexbyHost']);

    // 4. NOUVELLES ROUTES POUR LES RÉSERVATIONS & FINANCES
    Route::get('/bookings', [HostBookingController::class, 'index']);
    Route::patch('/bookings/{id}/status', [HostBookingController::class, 'updateStatus']);

    Route::get('/payouts-data', [HostPayoutController::class, 'getPayoutsData']);
    Route::post('/withdrawals', [HostPayoutController::class, 'requestWithdrawal']);

    Route::get('/settings', [HostSettingsController::class, 'index']);
    Route::put('/settings/profile', [HostSettingsController::class, 'updateProfile']);
    Route::put('/settings/payout-preference', [HostSettingsController::class, 'updatePayoutPreference']);
});

/*
|--------------------------------------------------------------------------
| 5. ADMINISTRATION (Modération & Finance)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('/withdrawals', [AdminWithdrawalController::class, 'index']);
    Route::patch('/withdrawals/{withdrawal}/process', [AdminWithdrawalController::class, 'process']);
    Route::get('/commissions', [AdminWithdrawalController::class, 'commissionsDashboard']);
});

/*
|--------------------------------------------------------------------------
| 6. WEBHOOKS (Passerelles de paiement)
|--------------------------------------------------------------------------
*/
Route::post('/payments/webhook/{gateway}', [PaymentController::class, 'webhook']);

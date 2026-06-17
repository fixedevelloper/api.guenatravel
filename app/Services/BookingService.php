<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Room;
use App\Models\RoomCalendar;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * Étape 1 : Initialiser une réservation au statut 'pending'
     * Cette méthode vérifie les disponibilités réelles et bloque temporairement l'inventaire.
     */
    public function createPendingBooking(User $guest, array $bookingData): Booking
    {
        $checkIn = Carbon::parse($bookingData['check_in']);
        $checkOut = Carbon::parse($bookingData['check_out']);
        $roomsRequested = $bookingData['rooms']; // Tableau de ['room_id' => X, 'quantity' => Y]

        return DB::transaction(function () use ($guest, $checkIn, $checkOut, $roomsRequested, $bookingData) {

            // 1. Instanciation de la réservation parente
            $booking = new Booking();
            $booking->booking_reference = 'BK-' . date('Ymd') . '-' . strtoupper(Str::random(6));
            $booking->guest_id = $guest->id;
            $booking->check_in = $checkIn;
            $booking->check_out = $checkOut;
            $booking->status = 'pending';
            $booking->payment_status = 'unpaid';
            $booking->guest_notes = $bookingData['guest_notes'] ?? null;

            // Variables de calcul financier global
            $totalSubtotal = 0.00;
            $totalCommission = 0.00;

            // Tableau temporaire pour stocker les lignes de commande (items) à insérer
            $itemsToCreate = [];

            // 2. Parcourir chaque type de chambre demandé
            foreach ($roomsRequested as $item) {
                $room = Room::with('property')->findOrFail($item['room_id']);
                $quantity = (int) $item['quantity'];

                // Vérification stricte des stocks et récupération de la grille tarifaire dynamique
                $nightlyDetails = $this->verifyAvailabilityAndGetPrices($room, $checkIn, $checkOut, $quantity);

                // Calculs financiers pour cet item
                $itemSubtotal = array_sum(array_column($nightlyDetails, 'price')) * $quantity;

                // Récupération du taux de commission configuré sur l'établissement
                $commissionRate = $room->property->commission_rate;
                $itemCommission = ($itemSubtotal * $commissionRate) / 100;

                $totalSubtotal += $itemSubtotal;
                $totalCommission += $itemCommission;

                // Préparation de l'enregistrement de la ligne de commande
                $itemsToCreate[] = new BookingItem([
                    'room_id' => $room->id,
                    'quantity_ordered' => $quantity,
                    'commission_rate_applied' => $commissionRate,
                    'commission_amount' => $itemCommission,
                    'nightly_prices' => $nightlyDetails // Sauvegarde du JSON pour l'historique de facturation
                ]);

                // 3. Incrémenter le compteur de chambres louées dans la table RoomCalendar (Ajustement des stocks)
                $this->incrementRoomCalendarStock($room, $checkIn, $checkOut, $quantity);
            }

            // 4. Finalisation des calculs financiers globaux de la réservation
            // (Ajoutez vos taxes gouvernementales ou frais de service de plateforme ici si nécessaire)
            $booking->subtotal_amount = $totalSubtotal;
            $booking->tax_amount = 0.00;
            $booking->service_fee = 0.00;
            $booking->total_amount = $totalSubtotal;

            // Calcul des flux Fintech pour les ventilations futures
            $booking->total_commission_amount = $totalCommission;
            $booking->host_payout_amount = $totalSubtotal - $totalCommission; // La part nette qui ira au portefeuille de l'hôte
            $booking->currency = $bookingData['currency'] ?? 'EUR';

            $booking->save();

            // 5. Attacher les lignes d'items à la réservation parente
            $booking->items()->saveMany($itemsToCreate);

            return $booking;
        });
    }

    /**
     * Étape 2 : Confirmer la réservation suite à un webhook de paiement réussi (Stripe, Paypal...)
     */
    public function confirmBookingAndPayment(Booking $booking, string $gateway, string $transactionRef, array $rawResponse): Booking
    {
        return DB::transaction(function () use ($booking, $gateway, $transactionRef, $rawResponse) {

            // 1. Mettre à jour les statuts de la réservation
            $booking->update([
                'status' => 'confirmed',
                'payment_status' => 'paid'
            ]);

            // 2. Journaliser l'encaissement bancaire réel
            $payment = $booking->payments()->create([
                'gateway' => $gateway,
                'transaction_reference' => $transactionRef,
                'amount' => $booking->total_amount,
                'status' => 'succeeded',
                'gateway_response_raw' => $rawResponse
            ]);

            // 3. Générer les lignes de commissions B2B comptables pour l'administration
            foreach ($booking->items as $item) {
                $booking->commission()->create([
                    'property_id' => $item->room->property_id,
                    'base_amount' => $item->quantity_ordered * array_sum(array_column($item->nightly_prices, 'price')),
                    'rate_applied' => $item->commission_rate_applied,
                    'commission_amount' => $item->commission_amount,
                    'status' => 'calculated' // Prêt à être facturé en fin de mois ou au check-out
                ]);
            }

            // 4. Déclencher le versement des fonds sur le portefeuille virtuel de l'hôte
            // On fait appel au FinanceService pour exécuter la logique Fintech de crédit de la balance
            app(FinanceService::class)->creditHostAfterPayment($payment);

            return $booking;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Fonctions Logiques de calcul et d'inventaire internes
    |--------------------------------------------------------------------------
    */

    /**
     * Parcourt chaque nuit du séjour pour s'assurer qu'il reste du stock physique
     * et extrait le tarif journalier (dynamique ou de base).
     */
    protected function verifyAvailabilityAndGetPrices(Room $room, Carbon $checkIn, Carbon $checkOut, int $quantityRequested): array
    {
        $nightlyDetails = [];
        $totalNights = $checkIn->diffInDays($checkOut);

        for ($i = 0; $i < $totalNights; $i++) {
            $currentDate = $checkIn->copy()->addDays($i);

            // Récupérer la ligne de calendrier pour ce jour précis
            $calendar = RoomCalendar::where('room_id', $room->id)
                ->where('date', $currentDate->format('Y-m-d'))
                ->first();

            // Si aucune ligne n'existe, la chambre utilise ses configurations de base
            $isBlocked = $calendar ? $calendar->is_blocked : false;
            $roomsBooked = $calendar ? $calendar->rooms_booked : 0;
            $priceForNight = $calendar ? $calendar->price_actual : $room->default_price_per_night;

            // Algorithme anti-overbooking : Vérifier si (Déjà loué + Demande actuelle) dépasse le stock total de l'hôtel
            if ($isBlocked || ($roomsBooked + $quantityRequested) > $room->total_inventory) {
                throw ValidationException::withMessages([
                    'dates' => "Désolé, la chambre '{$room->name}' n'est plus disponible pour la nuit du {$currentDate->format('d/m/Y')}."
                ]);
            }

            // Structuration du tableau pour l'historique JSON de la facture
            $nightlyDetails[] = [
                'date' => $currentDate->format('Y-m-d'),
                'price' => (float) $priceForNight
            ];
        }

        return $nightlyDetails;
    }

    /**
     * Verrouille physiquement les stocks en mettant à jour le livre des disponibilités journalières.
     * @param int $roomId
     * @param Carbon $checkIn
     * @param Carbon $checkOut
     * @param int $quantity
     */
    protected function incrementRoomCalendarStock(Room $room, Carbon $checkIn, Carbon $checkOut, int $quantity): void
    {
        $totalNights = $checkIn->diffInDays($checkOut);

        // 1. Déterminer une valeur de secours si price_actual est null
        // On cherche d'abord 'price_actual', puis 'price', puis 'default_price_per_night', sinon 0.
        $roomPrice = $room->price_actual
            ?? $room->price
            ?? $room->default_price_per_night
            ?? 0;

        for ($i = 0; $i < $totalNights; $i++) {
            $currentDate = $checkIn->copy()->addDays($i)->format('Y-m-d');

            // Met à jour la ligne existante ou en crée une nouvelle si elle n'existait pas encore
            RoomCalendar::updateOrCreate(
                ['room_id' => $room->id, 'date' => $currentDate],
                [
                    // On utilise DB::raw uniquement pour l'incrémentation
                    // Pour une insertion (create), MySQL appliquera 0 + $quantity si rooms_booked n'existait pas (assurez-vous d'avoir une valeur par défaut à 0 sur cette colonne en DB)
                    'rooms_booked' => DB::raw("rooms_booked + {$quantity}"),
                    'price_actual' => $roomPrice
                ]
            );
        }
    }
}

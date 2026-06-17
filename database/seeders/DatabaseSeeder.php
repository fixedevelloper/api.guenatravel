<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Property;
use App\Models\Room;
use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Commission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with full financial mappings.
     */
    public function run(): void
    {
        // ------------------------------------------------------------------
        // 1. UTILISATEURS (Admins, Hosts, Customers)
        // ------------------------------------------------------------------

        $admin = User::create([
            'name' => 'Admin FinTech',
            'email' => 'admin@platform.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'wallet_balance' => 0.00,
        ]);

        $hassan = User::create([
            'name' => 'Hassan Diop',
            'email' => 'hassan@host.com',
            'password' => Hash::make('password'),
            'role' => 'host',
            'wallet_balance' => 472.50, // Reçoit le 'host_payout_amount' de la réservation passée
        ]);

        $marie = User::create([
            'name' => 'Marie Dupont',
            'email' => 'marie@host.com',
            'password' => Hash::make('password'),
            'role' => 'host',
            'wallet_balance' => 0.00,
        ]);

        // Nouveaux hôtes pour accompagner l'augmentation des établissements
        $fatou = User::create([
            'name' => 'Fatou Ndiaye',
            'email' => 'fatou@host.com',
            'password' => Hash::make('password'),
            'role' => 'host',
            'wallet_balance' => 0.00,
        ]);

        $amadou = User::create([
            'name' => 'Amadou Sow',
            'email' => 'amadou@host.com',
            'password' => Hash::make('password'),
            'role' => 'host',
            'wallet_balance' => 0.00,
        ]);

        $jean = User::create([
            'name' => 'Jean Voyageur',
            'email' => 'jean@customer.com',
            'password' => Hash::make('password'),
            'role' => 'customer',
            'wallet_balance' => 0.00,
        ]);


        // ------------------------------------------------------------------
        // 2. ÉTABLISSEMENTS (Properties avec Traduction JSON & Adresse complète)
        // ------------------------------------------------------------------

        // [1] Grand Hôtel de la Plage (Hassan)
        $hotelPlage = Property::create([
            'host_id' => $hassan->id,
            'type' => 'hotel',
            'name' => [
                'fr' => 'Grand Hôtel de la Plage',
                'en' => 'Grand Beach Hotel'
            ],
            'description' => [
                'fr' => 'Un magnifique hôtel 4 étoiles avec vue panoramique sur l’océan, piscine à débordement et spa privé.',
                'en' => 'A beautiful 4-star hotel with panoramic ocean views, infinity pool and private spa.'
            ],
            'cancellation_policy' => [
                'fr' => 'Remboursement intégral jusqu\'à 24h avant l\'arrivée.',
                'en' => 'Full refund up to 24 hours before check-in.'
            ],
            'address_line_1' => 'Route de la Corniche Ouest',
            'address_line_2' => 'Secteur Fann Residence, Lot 45',
            'city' => 'Dakar',
            'state_province' => 'Dakar Region',
            'postal_code' => '11500',
            'country_code' => 'SN',
            'latitude' => 14.69370000,
            'longitude' => -17.47250000,
            'commission_rate' => 12.50, // 12.5% de frais de plateforme
            'check_in_after' => '15:00:00',
            'check_out_before' => '11:00:00',
            'is_active' => true,
        ]);

        // [2] Villa Horizon & Spa (Marie)
        $villaHorizon = Property::create([
            'host_id' => $marie->id,
            'type' => 'villa',
            'name' => [
                'fr' => 'Villa Horizon & Spa',
                'en' => 'Horizon Villa & Spa'
            ],
            'description' => [
                'fr' => 'Superbe villa contemporaine avec 4 chambres, jardin tropical et accès direct à la plage.',
                'en' => 'Superb contemporary villa with 4 bedrooms, tropical garden and direct beach access.'
            ],
            'cancellation_policy' => [
                'fr' => 'Non remboursable.',
                'en' => 'Non-refundable.'
            ],
            'address_line_1' => '12 Rue des Alizés',
            'address_line_2' => null,
            'city' => 'Saly',
            'state_province' => 'Thiès',
            'postal_code' => '23000',
            'country_code' => 'SN',
            'latitude' => 14.44110000,
            'longitude' => -16.98560000,
            'commission_rate' => 10.00,
            'check_in_after' => '16:00:00',
            'check_out_before' => '12:00:00',
            'is_active' => true,
        ]);

        // [3] NOUVEAU : Éco-Lodge du Sine Saloum (Fatou)
        $ecoLodge = Property::create([
            'host_id' => $fatou->id,
            'type' => 'lodge',
            'name' => [
                'fr' => 'Éco-Lodge des Bolongs',
                'en' => 'Bolongs Eco-Lodge'
            ],
            'description' => [
                'fr' => 'Immersion totale en pleine nature. Cases traditionnelles de confort sur pilotis face aux mangroves.',
                'en' => 'Total immersion in nature. Comfortable traditional huts on stilts facing the mangroves.'
            ],
            'cancellation_policy' => [
                'fr' => 'Remboursement à 50% jusqu\'à 7 jours avant l\'arrivée.',
                'en' => '50% refund up to 7 days before check-in.'
            ],
            'address_line_1' => 'Piste de Ndangane',
            'address_line_2' => 'Bord de fleuve',
            'city' => 'Ndangane',
            'state_province' => 'Fatick',
            'postal_code' => '61000',
            'country_code' => 'SN',
            'latitude' => 14.07220000,
            'longitude' => -16.71140000,
            'commission_rate' => 8.00, // Taux préférentiel éco-tourisme
            'check_in_after' => '14:00:00',
            'check_out_before' => '10:00:00',
            'is_active' => true,
        ]);

        // [4] NOUVEAU : Appartement d'affaires à Dakar Plateau (Hassan)
        $appatPlateau = Property::create([
            'host_id' => $hassan->id,
            'type' => 'apartment',
            'name' => [
                'fr' => 'Le Plateau Executive Suite',
                'en' => 'The Plateau Executive Suite'
            ],
            'description' => [
                'fr' => 'Appartement moderne de haut standing au cœur du quartier des affaires. Idéal pour les professionnels.',
                'en' => 'High-standing modern apartment in the heart of the business district. Ideal for professionals.'
            ],
            'cancellation_policy' => [
                'fr' => 'Annulation gratuite jusqu\'à 48h avant.',
                'en' => 'Free cancellation up to 48 hours before.'
            ],
            'address_line_1' => 'Avenue Léopold Sédar Senghor',
            'address_line_2' => 'Immeuble Horizon, 4ème étage',
            'city' => 'Dakar',
            'state_province' => 'Dakar Region',
            'postal_code' => '11000',
            'country_code' => 'SN',
            'latitude' => 14.66610000,
            'longitude' => -17.43280000,
            'commission_rate' => 15.00, // Taux standard corporate
            'check_in_after' => '15:00:00',
            'check_out_before' => '12:00:00',
            'is_active' => true,
        ]);

        // [5] NOUVEAU : Campement de Brousse en Casamance (Amadou)
        $campementCasamance = Property::create([
            'host_id' => $amadou->id,
            'type' => 'guesthouse',
            'name' => [
                'fr' => 'Campement Villageois de Oussouye',
                'en' => 'Oussouye Village Guesthouse'
            ],
            'description' => [
                'fr' => 'Découvrez la culture Diola dans un campement géré par la communauté. Authentique et solidaire.',
                'en' => 'Discover Diola culture in a community-managed camp. Authentic and supportive.'
            ],
            'cancellation_policy' => [
                'fr' => 'Remboursement intégral jusqu\'à 5 jours avant.',
                'en' => 'Full refund up to 5 days before.'
            ],
            'address_line_1' => 'Quartier Escale',
            'address_line_2' => 'Près de la case du Roi',
            'city' => 'Oussouye',
            'state_province' => 'Ziguinchor',
            'postal_code' => '27000',
            'country_code' => 'SN',
            'latitude' => 12.48620000,
            'longitude' => -16.54580000,
            'commission_rate' => 5.00, // Taux très bas solidaire
            'check_in_after' => '12:00:00',
            'check_out_before' => '11:00:00',
            'is_active' => true,
        ]);


        // ------------------------------------------------------------------
        // 3. CHAMBRES (Rooms)
        // ------------------------------------------------------------------

        // Chambres pour : [1] Grand Hôtel de la Plage
        $roomStandard = Room::create([
            'property_id' => $hotelPlage->id,
            'name' => ['fr' => 'Chambre Double Standard', 'en' => 'Standard Double Room'],
            'description' => ['fr' => 'Lit Queen size, bureau et Wi-Fi haut débit.', 'en' => 'Queen size bed, desk and high-speed Wi-Fi.'],
            'base_occupancy' => 2, 'max_occupancy' => 2, 'max_children' => 0,
            'total_inventory' => 10, 'default_price_per_night' => 75.00, 'is_active' => true,
        ]);

        $roomSuite = Room::create([
            'property_id' => $hotelPlage->id,
            'name' => ['fr' => 'Suite Prestige Vue Mer', 'en' => 'Prestige Ocean View Suite'],
            'description' => ['fr' => 'Salon séparé, terrasse privée et lit King size.', 'en' => 'Separate living room, private terrace and King size bed.'],
            'base_occupancy' => 2, 'max_occupancy' => 3, 'max_children' => 1,
            'total_inventory' => 3, 'default_price_per_night' => 180.00, 'is_active' => true,
        ]);

        // Chambres pour : [3] Éco-Lodge du Sine Saloum
        $caseMangrove = Room::create([
            'property_id' => $ecoLodge->id,
            'name' => ['fr' => 'Case Traditionnelle Pilotis', 'en' => 'Traditional Stilt Hut'],
            'description' => ['fr' => 'Ventilation naturelle, lit moustiquaire, salle d\'eau solaire privée.', 'en' => 'Natural ventilation, mosquito net, private solar bathroom.'],
            'base_occupancy' => 2, 'max_occupancy' => 4, 'max_children' => 2,
            'total_inventory' => 5, 'default_price_per_night' => 60.00, 'is_active' => true,
        ]);

        // Chambres pour : [4] Le Plateau Executive Suite
        $loftCorporate = Room::create([
            'property_id' => $appatPlateau->id,
            'name' => ['fr' => 'Loft Studio Premium', 'en' => 'Premium Loft Studio'],
            'description' => ['fr' => 'Cuisine équipée, Smart TV, espace de travail ergonomique, climatisé.', 'en' => 'Equipped kitchen, Smart TV, ergonomic workspace, air-conditioned.'],
            'base_occupancy' => 1, 'max_occupancy' => 2, 'max_children' => 0,
            'total_inventory' => 2, 'default_price_per_night' => 110.00, 'is_active' => true,
        ]);

        // Chambres pour : [5] Campement Villageois de Oussouye
        $caseCase = Room::create([
            'property_id' => $campementCasamance->id,
            'name' => ['fr' => 'Case en terre battue - Familiale', 'en' => 'Clay Hut - Family Room'],
            'description' => ['fr' => 'Architecture locale en banco, lits simples, sanitaires communs propres.', 'en' => 'Local banco architecture, single beds, clean shared facilities.'],
            'base_occupancy' => 4, 'max_occupancy' => 6, 'max_children' => 4,
            'total_inventory' => 4, 'default_price_per_night' => 25.00, 'is_active' => true,
        ]);


        // ------------------------------------------------------------------
        // 4. RÉSERVATION COMPLÈTE & SPLIT FINANCIER (Booking, Items & Commissions)
        // ------------------------------------------------------------------

        // (Le scénario de Jean à la Suite Prestige reste inchangé pour préserver vos calculs)
        $booking = Booking::create([
            'booking_reference' => 'BK-' . strtoupper(Str::random(6)),
            'guest_id' => $jean->id,
            'check_in' => now()->subDays(5)->format('Y-m-d'),
            'check_out' => now()->subDays(2)->format('Y-m-d'),
            'subtotal_amount' => 540.00,
            'tax_amount' => 54.00,
            'service_fee' => 15.00,
            'total_amount' => 609.00,
            'currency' => 'EUR',
            'total_commission_amount' => 67.50,
            'host_payout_amount' => 472.50,
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'guest_notes' => 'Demande une suite au dernier étage avec lit bébé si possible.',
        ]);

        BookingItem::create([
            'booking_id' => $booking->id,
            'room_id' => $roomSuite->id,
            'quantity_ordered' => 1,
            'commission_rate_applied' => $hotelPlage->commission_rate, // 12.50%
            'commission_amount' => 67.50,
            'nightly_prices' => [
                ['date' => now()->subDays(5)->format('Y-m-d'), 'price' => 180.00],
                ['date' => now()->subDays(4)->format('Y-m-d'), 'price' => 180.00],
                ['date' => now()->subDays(3)->format('Y-m-d'), 'price' => 180.00],
            ],
        ]);

        Commission::create([
            'booking_id' => $booking->id,
            'property_id' => $hotelPlage->id,
            'base_amount' => 540.00,
            'rate_applied' => $hotelPlage->commission_rate,
            'commission_amount' => 67.50,
            'status' => 'calculated',
            'processed_at' => now()->subDays(5),
        ]);
        $this->call([
            AmenitySeeder::class,
        ]);
    }
}

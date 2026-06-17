<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AmenitySeeder extends Seeder
{
    public function run(): void
    {
        $amenities = [
            // ÉQUIPEMENTS ÉTABLISSEMENT (Property)
            ['slug' => 'wifi', 'icon' => 'Wifi', 'name' => ['fr' => 'Wi-Fi gratuit', 'en' => 'Free Wi-Fi'], 'category' => 'property'],
            ['slug' => 'parking', 'icon' => 'Parking', 'name' => ['fr' => 'Parking gratuit', 'en' => 'Free Parking'], 'category' => 'property'],
            ['slug' => 'pool', 'icon' => 'Waves', 'name' => ['fr' => 'Piscine', 'en' => 'Swimming Pool'], 'category' => 'property'],
            ['slug' => 'restaurant', 'icon' => 'Utensils', 'name' => ['fr' => 'Restaurant', 'en' => 'Restaurant'], 'category' => 'property'],
            ['slug' => 'fitness-center', 'icon' => 'Dumbbell', 'name' => ['fr' => 'Salle de sport', 'en' => 'Fitness Center'], 'category' => 'property'],
            ['slug' => 'spa', 'icon' => 'Sparkles', 'name' => ['fr' => 'Spa et Bien-être', 'en' => 'Spa and Wellness'], 'category' => 'property'],
            ['slug' => 'breakfast', 'icon' => 'Coffee', 'name' => ['fr' => 'Petit-déjeuner inclus', 'en' => 'Breakfast included'], 'category' => 'property'],
            ['slug' => 'bar', 'icon' => 'Martini', 'name' => ['fr' => 'Bar / Lounge', 'en' => 'Bar / Lounge'], 'category' => 'property'],
            ['slug' => 'shuttle', 'icon' => 'Bus', 'name' => ['fr' => 'Navette aéroport', 'en' => 'Airport Shuttle'], 'category' => 'property'],
            ['slug' => 'room-service', 'icon' => 'BellConcierge', 'name' => ['fr' => 'Service en chambre', 'en' => 'Room Service'], 'category' => 'property'],
            ['slug' => 'reception-24h', 'icon' => 'Clock', 'name' => ['fr' => 'Réception 24h/24', 'en' => '24-hour Front Desk'], 'category' => 'property'],
            ['slug' => 'laundry', 'icon' => 'Shirt', 'name' => ['fr' => 'Blanchisserie', 'en' => 'Laundry Service'], 'category' => 'property'],
            ['slug' => 'meeting-rooms', 'icon' => 'Users', 'name' => ['fr' => 'Salles de réunion', 'en' => 'Meeting Rooms'], 'category' => 'property'],
            ['slug' => 'elevator', 'icon' => 'ArrowUp', 'name' => ['fr' => 'Ascenseur', 'en' => 'Elevator'], 'category' => 'property'],
            ['slug' => 'garden', 'icon' => 'Tree', 'name' => ['fr' => 'Jardin', 'en' => 'Garden'], 'category' => 'property'],
            ['slug' => 'beach-access', 'icon' => 'Umbrella', 'name' => ['fr' => 'Accès plage', 'en' => 'Beach Access'], 'category' => 'property'],
            ['slug' => 'pets-allowed', 'icon' => 'Dog', 'name' => ['fr' => 'Animaux acceptés', 'en' => 'Pets Allowed'], 'category' => 'property'],
            ['slug' => 'luggage-storage', 'icon' => 'Briefcase', 'name' => ['fr' => 'Bagagerie', 'en' => 'Luggage Storage'], 'category' => 'property'],
            ['slug' => 'security', 'icon' => 'Shield', 'name' => ['fr' => 'Sécurité 24h/24', 'en' => '24h Security'], 'category' => 'property'],
            ['slug' => 'atm', 'icon' => 'CreditCard', 'name' => ['fr' => 'Distributeur de billets', 'en' => 'ATM on site'], 'category' => 'property'],
            ['slug' => 'accessibility', 'icon' => 'Accessibility', 'name' => ['fr' => 'Accessible aux PMR', 'en' => 'Wheelchair Accessible'], 'category' => 'property'],
            ['slug' => 'charging-station', 'icon' => 'Zap', 'name' => ['fr' => 'Borne de recharge électrique', 'en' => 'EV Charging Station'], 'category' => 'property'],
            ['slug' => 'kids-club', 'icon' => 'Baby', 'name' => ['fr' => 'Club enfants', 'en' => 'Kids Club'], 'category' => 'property'],
            ['slug' => 'terrace', 'icon' => 'Sun', 'name' => ['fr' => 'Terrasse', 'en' => 'Terrace'], 'category' => 'property'],
            ['slug' => 'valet-parking', 'icon' => 'Car', 'name' => ['fr' => 'Service voiturier', 'en' => 'Valet Parking'], 'category' => 'property'],

            // ÉQUIPEMENTS CHAMBRE (Room)
            ['slug' => 'air-conditioning', 'icon' => 'Fan', 'name' => ['fr' => 'Climatisation', 'en' => 'Air conditioning'], 'category' => 'room'],
            ['slug' => 'tv', 'icon' => 'Tv', 'name' => ['fr' => 'Télévision écran plat', 'en' => 'Flat-screen TV'], 'category' => 'room'],
            ['slug' => 'minibar', 'icon' => 'GlassWater', 'name' => ['fr' => 'Minibar', 'en' => 'Minibar'], 'category' => 'room'],
            ['slug' => 'safe', 'icon' => 'Lock', 'name' => ['fr' => 'Coffre-fort', 'en' => 'Safe'], 'category' => 'room'],
            ['slug' => 'hairdryer', 'icon' => 'Wind', 'name' => ['fr' => 'Sèche-cheveux', 'en' => 'Hairdryer'], 'category' => 'room'],
            ['slug' => 'coffee-machine', 'icon' => 'Coffee', 'name' => ['fr' => 'Machine à café', 'en' => 'Coffee Machine'], 'category' => 'room'],
            ['slug' => 'desk', 'icon' => 'Lamp', 'name' => ['fr' => 'Bureau', 'en' => 'Desk'], 'category' => 'room'],
            ['slug' => 'balcony', 'icon' => 'LayoutGrid', 'name' => ['fr' => 'Balcon privé', 'en' => 'Private Balcony'], 'category' => 'room'],
            ['slug' => 'bathtub', 'icon' => 'Bath', 'name' => ['fr' => 'Baignoire', 'en' => 'Bathtub'], 'category' => 'room'],
            ['slug' => 'shower', 'icon' => 'Droplets', 'name' => ['fr' => 'Douche italienne', 'en' => 'Rain Shower'], 'category' => 'room'],
            ['slug' => 'iron', 'icon' => 'Shirt', 'name' => ['fr' => 'Fer à repasser', 'en' => 'Iron & Board'], 'category' => 'room'],
            ['slug' => 'refrigerator', 'icon' => 'Refrigerator', 'name' => ['fr' => 'Réfrigérateur', 'en' => 'Refrigerator'], 'category' => 'room'],
            ['slug' => 'kitchenette', 'icon' => 'CookingPot', 'name' => ['fr' => 'Kitchenette', 'en' => 'Kitchenette'], 'category' => 'room'],
            ['slug' => 'soundproofing', 'icon' => 'VolumeX', 'name' => ['fr' => 'Insonorisation', 'en' => 'Soundproofing'], 'category' => 'room'],
            ['slug' => 'wake-up-call', 'icon' => 'AlarmClock', 'name' => ['fr' => 'Service réveil', 'en' => 'Wake-up service'], 'category' => 'room'],
            ['slug' => 'sea-view', 'icon' => 'Waves', 'name' => ['fr' => 'Vue sur mer', 'en' => 'Sea view'], 'category' => 'room'],
            ['slug' => 'bathtub-hydromassage', 'icon' => 'Bath', 'name' => ['fr' => 'Baignoire balnéo', 'en' => 'Hydromassage tub'], 'category' => 'room'],
            ['slug' => 'dressing-room', 'icon' => 'Shirt', 'name' => ['fr' => 'Dressing', 'en' => 'Dressing room'], 'category' => 'room'],
            ['slug' => 'satellite-channels', 'icon' => 'Satellite', 'name' => ['fr' => 'Chaînes satellite', 'en' => 'Satellite channels'], 'category' => 'room'],
            ['slug' => 'extra-long-beds', 'icon' => 'Bed', 'name' => ['fr' => 'Lits grande longueur', 'en' => 'Extra long beds'], 'category' => 'room'],
            ['slug' => 'blackout-curtains', 'icon' => 'Moon', 'name' => ['fr' => 'Rideaux occultants', 'en' => 'Blackout curtains'], 'category' => 'room'],
            ['slug' => 'heating', 'icon' => 'Flame', 'name' => ['fr' => 'Chauffage', 'en' => 'Heating'], 'category' => 'room'],
            ['slug' => 'sofa', 'icon' => 'Armchair', 'name' => ['fr' => 'Canapé', 'en' => 'Sofa'], 'category' => 'room'],
            ['slug' => 'private-entrance', 'icon' => 'Key', 'name' => ['fr' => 'Entrée privée', 'en' => 'Private entrance'], 'category' => 'room'],
            ['slug' => 'fan', 'icon' => 'Fan', 'name' => ['fr' => 'Ventilateur', 'en' => 'Fan'], 'category' => 'room'],
            [
                'slug' => 'generator',
                'icon' => 'Battery',
                'name' => ['fr' => 'Groupe électrogène', 'en' => 'Backup Generator'],
                'category' => 'property'
            ],
            [
                'slug' => 'solar-power',
                'icon' => 'Sun',
                'name' => ['fr' => 'Énergie solaire', 'en' => 'Solar Power'],
                'category' => 'property'
            ],
            [
                'slug' => 'water-reserve',
                'icon' => 'Droplets',
                'name' => ['fr' => 'Réserve d\'eau', 'en' => 'Water Reserve'],
                'category' => 'property'
            ],
            [
                'slug' => 'hot-water',
                'icon' => 'Flame',
                'name' => ['fr' => 'Eau chaude', 'en' => 'Hot Water'],
                'category' => 'room'
            ],
            [
                'slug' => 'high-speed-wifi',
                'icon' => 'Wifi',
                'name' => ['fr' => 'Wi-Fi haut débit', 'en' => 'High-Speed Wi-Fi'],
                'category' => 'room'
            ],
            [
                'slug' => 'city-view',
                'icon' => 'Building2',
                'name' => ['fr' => 'Vue sur la ville', 'en' => 'City View'],
                'category' => 'room'
            ],
            [
                'slug' => 'garden-view',
                'icon' => 'Trees',
                'name' => ['fr' => 'Vue jardin', 'en' => 'Garden View'],
                'category' => 'room'
            ],
            [
                'slug' => 'mountain-view',
                'icon' => 'Mountain',
                'name' => ['fr' => 'Vue montagne', 'en' => 'Mountain View'],
                'category' => 'room'
            ],
            [
                'slug' => 'lake-view',
                'icon' => 'Waves',
                'name' => ['fr' => 'Vue lac', 'en' => 'Lake View'],
                'category' => 'room'
            ],
            [
                'slug' => 'workspace',
                'icon' => 'Laptop',
                'name' => ['fr' => 'Espace de travail', 'en' => 'Workspace'],
                'category' => 'room'
            ],
            [
                'slug' => 'usb-chargers',
                'icon' => 'Plug',
                'name' => ['fr' => 'Prises USB', 'en' => 'USB Chargers'],
                'category' => 'room'
            ],
            [
                'slug' => 'smart-tv',
                'icon' => 'Monitor',
                'name' => ['fr' => 'Smart TV', 'en' => 'Smart TV'],
                'category' => 'room'
            ],
            [
                'slug' => 'streaming-services',
                'icon' => 'PlayCircle',
                'name' => ['fr' => 'Netflix / Streaming', 'en' => 'Streaming Services'],
                'category' => 'room'
            ],
            [
                'slug' => 'mosquito-net',
                'icon' => 'Shield',
                'name' => ['fr' => 'Moustiquaire', 'en' => 'Mosquito Net'],
                'category' => 'room'
            ],
            [
                'slug' => 'concierge',
                'icon' => 'Bell',
                'name' => ['fr' => 'Service de conciergerie', 'en' => 'Concierge Service'],
                'category' => 'property'
            ],
            [
                'slug' => 'currency-exchange',
                'icon' => 'Banknote',
                'name' => ['fr' => 'Change de devises', 'en' => 'Currency Exchange'],
                'category' => 'property'
            ],
            [
                'slug' => 'tour-desk',
                'icon' => 'Map',
                'name' => ['fr' => 'Bureau d\'excursions', 'en' => 'Tour Desk'],
                'category' => 'property'
            ],
            [
                'slug' => 'car-rental',
                'icon' => 'CarFront',
                'name' => ['fr' => 'Location de voitures', 'en' => 'Car Rental'],
                'category' => 'property'
            ],
            [
                'slug' => 'business-center',
                'icon' => 'BriefcaseBusiness',
                'name' => ['fr' => 'Centre d\'affaires', 'en' => 'Business Center'],
                'category' => 'property'
            ],
            [
                'slug' => 'casino',
                'icon' => 'Dice5',
                'name' => ['fr' => 'Casino', 'en' => 'Casino'],
                'category' => 'property'
            ],
            [
                'slug' => 'nightclub',
                'icon' => 'Music',
                'name' => ['fr' => 'Discothèque', 'en' => 'Night Club'],
                'category' => 'property'
            ],
            [
                'slug' => 'water-sports',
                'icon' => 'Waves',
                'name' => ['fr' => 'Sports nautiques', 'en' => 'Water Sports'],
                'category' => 'property'
            ],
            [
                'slug' => 'tennis-court',
                'icon' => 'Trophy',
                'name' => ['fr' => 'Court de tennis', 'en' => 'Tennis Court'],
                'category' => 'property'
            ],
            [
                'slug' => 'playground',
                'icon' => 'ToyBrick',
                'name' => ['fr' => 'Aire de jeux', 'en' => 'Playground'],
                'category' => 'property'
            ],
            [
                'slug' => 'cctv',
                'icon' => 'Camera',
                'name' => ['fr' => 'Vidéosurveillance', 'en' => 'CCTV'],
                'category' => 'property'
            ],
            [
                'slug' => 'fire-extinguishers',
                'icon' => 'Flame',
                'name' => ['fr' => 'Extincteurs', 'en' => 'Fire Extinguishers'],
                'category' => 'property'
            ],
            [
                'slug' => 'smoke-detectors',
                'icon' => 'AlarmSmoke',
                'name' => ['fr' => 'Détecteurs de fumée', 'en' => 'Smoke Detectors'],
                'category' => 'property'
            ],

        ];

        foreach ($amenities as $amenity) {
            DB::table('amenities')->updateOrInsert(
                ['slug' => $amenity['slug']],
                [
                    'icon' => $amenity['icon'],
                    'name' => json_encode($amenity['name']),
                    'category' => $amenity['category'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }
}

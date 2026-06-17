<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. UTILISATEURS (Clients, Hôtes/Partenaires, Admins)
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('role')->default('customer')->index(); // 'client', 'host', 'admin'
            // Solde disponible pour les retraits
            $table->decimal('wallet_balance', 12, 2)->default(0.00);
            // Optionnel : l'argent bloqué temporairement (ex: le client est dans l'hôtel, l'hôte touchera l'argent au check-out)
            $table->decimal('wallet_escrow', 12, 2)->default(0.00);
            $table->string('phone_number')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
        Schema::create('profile_host', function (Blueprint $table) {
            $table->id();
            $table->string('phone')->nullable();
            $table->string('country_code')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->boolean('is_particular')->default(true);
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            // Informations de paiement
            $table->string('bank_name')->nullable();
            $table->string('account_holder_name')->nullable();
            $table->string('rib_iban')->nullable();
            $table->string('swift_bic')->nullable();

            // Informations fiscales
            $table->string('tax_identification_number')->nullable(); // TIN / NIF
            $table->string('business_registration_number')->nullable(); // SIRET / Registre commerce
            $table->string('company_name')->nullable(); // Si pro, nom de l'entreprise
            $table->string('vat_number')->nullable(); // Numéro de TVA intracommunautaire

            // Statut de vérification
            $table->enum('verification_status', ['pending', 'verified', 'rejected'])->default('pending');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
// 2. JOURNAL DES TRANSACTIONS DU PORTEFEUILLE (Livre de comptes)
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('restrict'); // L'hôte concerné

            // Permet de lier la transaction à un Paiement OU à un Retrait (Relation polymorphe)
            $table->nullableMorphs('source'); // crée source_id et source_type

            $table->enum('type', ['credit', 'debit'])->index(); // credit = entrée d'argent, debit = sortie/retrait
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');
            $table->string('description'); // Ex: "Crédit pour la réservation BK-2026-A8Z9" ou "Retrait Wave/Stripe"

            $table->timestamps();
        });

        // 3. SUIVI DES RETRAITS (Withdrawals)
        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique()->index(); // WD-2026-XXXX
            $table->foreignId('user_id')->constrained()->onDelete('restrict');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3)->default('EUR');

            // Méthode de retrait préférée du pays (Stripe Connect, virement bancaire, Orange Money, Wave...)
            $table->string('payment_method');
            $table->json('bank_details_snapshot'); // Copie figée des coordonnées bancaires/numéro de téléphone au moment de la demande

            // États du workflow de retrait
            // 'pending' : Initié par l'hôte, en attente de validation admin
            // 'processing' : Envoyé à la banque/passerelle
            // 'completed' : Argent transféré avec succès
            // 'failed' : Rejeté par la banque
            // 'rejected' : Refusé par l'administrateur de votre plateforme
            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'rejected'])->default('pending')->index();

            $table->string('gateway_transaction_id')->nullable()->unique(); // ID de transaction de la banque (ex: Stripe Transfer ID)
            $table->text('admin_notes')->nullable(); // Raison en cas de rejet

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
        // 4. ÉQUIPEMENTS (Amenities - i18n natif JSON)
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('icon')->nullable(); // Classes FontAwesome ou SVG Heroicons
            $table->json('name'); // {"fr": "Climatisation", "en": "Air conditioning"}
            $table->string('category')->index(); // 'property', 'room'
            $table->timestamps();
        });

        // 3. SPATIE MEDIA LIBRARY (Table officielle polymorphe pour les photos)
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('model'); // Liera l'image à une Property ou une Room
            $table->uuid()->nullable()->unique();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('custom_properties');
            $table->json('generated_conversions');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable()->index(); // Pour le tri Drag & Drop
            $table->nullableTimestamps();
        });

        // 4. ÉTABLISSEMENTS (Hôtels, Appartements, Villas)
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('host_id')->constrained('users')->onDelete('cascade');
            $table->string('type')->index(); // 'hotel', 'apartment', 'villa'
            $table->json('name');
            $table->json('description');

            // Localisation complète & Géolocalisation
            $table->string('address_line_1');
            $table->string('address_line_2')->nullable();
            $table->string('city')->index();
            $table->string('state_province')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country_code', 3)->index(); // Code ISO (ex: FRA, CMR, CAN)
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();

            // Commission de la plateforme & Politiques
            $table->decimal('commission_rate', 5, 2)->default(15.00); // Taux par défaut de 15%
            $table->time('check_in_after')->default('14:00:00');
            $table->time('check_out_before')->default('11:00:00');
            $table->json('cancellation_policy')->nullable();

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes(); // Sécurité : protège l'historique global
        });

        // Pivot : Équipements liés à l'établissement
        Schema::create('amenity_property', function (Blueprint $table) {
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->foreignId('amenity_id')->constrained()->onDelete('cascade');
            $table->primary(['property_id', 'amenity_id']);
        });

        // 5. CONFIGURATIONS DES CHAMBRES / TYPES DE LOGEMENT
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->json('name'); // ex: "Suite Deluxe Vue Mer"
            $table->json('description')->nullable();

            // Capacités d'accueil
            $table->json('bed_type')->nullable();// Lit simple, Double , etc...
            $table->integer('bed_quantity')->default(2);
            $table->integer('base_occupancy')->default(2);
            $table->integer('max_occupancy')->default(4)->index(); // Indexé pour la recherche de voyageurs
            $table->integer('max_children')->default(2);

            // Inventaire physique et prix plancher
            $table->integer('total_inventory')->default(1); // Nombre de chambres identiques disponibles
            $table->decimal('default_price_per_night', 10, 2);

            $table->integer('superficie')->default(20); //en mettre carre
            $table->boolean('is_active')->default(true);
            $table->boolean('is_smooking')->default(true);
            $table->timestamps();
            $table->softDeletes(); // Empêche de casser l'historique comptable des réservations

            $table->index(['property_id', 'is_active']);
        });

        // Pivot : Équipements spécifiques à la chambre
        Schema::create('amenity_room', function (Blueprint $table) {
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->foreignId('amenity_id')->constrained()->onDelete('cascade');
            $table->primary(['room_id', 'amenity_id']);
        });

        // 6. CALENDRIER DYNAMIQUE (Inventaire quotidien et prix fluctuants)
        Schema::create('room_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->decimal('price_actual', 10, 2); // Prix net pour ce jour exact
            $table->integer('rooms_booked')->default(0); // Compteur de contrôle anti-overbooking
            $table->boolean('is_blocked')->default(false)->index(); // Blocage manuel par l'hôte

            $table->unique(['room_id', 'date']);
            $table->index(['date', 'is_blocked']); // Pour l'analyse ultra-rapide des plages de dispo
            $table->timestamps();
        });

        // 7. RÉSERVATIONS (Relation Voyageur / Expérience client)
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_reference')->unique()->index(); // BK-2026-X8Y9
            $table->foreignId('guest_id')->constrained('users')->onDelete('restrict'); // Interdit de supprimer le client si résa active
            $table->date('check_in');
            $table->date('check_out');

            // Détails financiers du panier
            $table->decimal('subtotal_amount', 10, 2); // Somme brute des nuitées
            $table->decimal('tax_amount', 10, 2)->default(0.00); // Taxes de séjour / TVA
            $table->decimal('service_fee', 10, 2)->default(0.00); // Frais de plateforme optionnels côté client
            $table->decimal('total_amount', 10, 2); // Prix final payé par le client
            $table->string('currency', 3)->default('EUR');

            // Répartition et transparence Hôte / Commission
            $table->decimal('total_commission_amount', 10, 2)->default(0.00); // Part de notre plateforme
            $table->decimal('host_payout_amount', 10, 2)->default(0.00); // Revenu net pour l'hôte

            // États logiques
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed', 'no_show'])->default('pending')->index();
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'paid', 'refunded'])->default('unpaid')->index();

            $table->text('guest_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['check_in', 'check_out', 'status']); // Optimisation moteur de recherche inversé
        });

        // 8. DÉTAILS DES ITEMS DE RÉSERVATION (Historique gelé)
        Schema::create('booking_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('room_id')->constrained()->onDelete('restrict'); // Sécurisé par le softDeletes de rooms
            $table->integer('quantity_ordered')->default(1);
            $table->decimal('commission_rate_applied', 5, 2); // Sauvegarde du taux appliqué à l'instant T
            $table->decimal('commission_amount', 10, 2); // Montant de la commission pour cet item
            $table->json('nightly_prices'); // Ex: [{"date": "2026-06-15", "price": 120.00}]
            $table->timestamps();
        });

        // 9. JOURNAL DES COMMISSIONS (Relation B2B Plateforme / Établissement)
        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('restrict');
            $table->foreignId('property_id')->constrained()->onDelete('cascade');

            $table->decimal('base_amount', 10, 2); // Base brute calculée (hors taxes)
            $table->decimal('rate_applied', 5, 2); // Pourcentage appliqué
            $table->decimal('commission_amount', 10, 2); // Revenu net plateforme

            // 'pending' (avant séjour), 'calculated' (prêt à facturer), 'invoiced' (facturé), 'paid' (encaissé), 'cancelled'
            $table->string('status')->default('pending')->index();
            $table->timestamp('processed_at')->nullable(); // Date d'édition comptable / facturation mensuelle
            $table->timestamps();
        });

        // 10. PAIEMENTS (Flux bancaires réels)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('restrict'); // Sécurité financière absolue
            $table->string('gateway')->default('stripe'); // 'stripe', 'paypal', 'apple_pay'
            $table->string('transaction_reference')->unique()->index(); // ID de transaction unique Stripe (ch_xxx ou pi_xxx)
            $table->decimal('amount', 10, 2);
            $table->string('status')->index(); // 'succeeded', 'failed', 'refunded'
            $table->json('gateway_response_raw')->nullable(); // Utile pour le debugging d'erreurs bancaires
            $table->timestamps();
        });

        // 11. AVIS CLIENTS (Vérifiés par un séjour réel)
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('property_id')->constrained()->onDelete('cascade'); // Maintenu pour la performance des index

            // Système multi-critères type Booking.com
            $table->decimal('rating', 3, 1); // Note globale finale calculée sur 10.0 (ex: 9.2)
            $table->integer('cleanliness_rating')->nullable(); // Note propreté (1-10)
            $table->integer('location_rating')->nullable(); // Note emplacement (1-10)
            $table->integer('value_rating')->nullable(); // Note rapport qualité/prix (1-10)

            $table->text('comment_positive')->nullable(); // "Ce que le client a aimé"
            $table->text('comment_negative')->nullable(); // "Ce que le client n'a pas aimé"
            $table->timestamps();
        });

        // 12. SESSIONS & PASSWORDS (Standards requis par Laravel Core)
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('commissions');
        Schema::dropIfExists('booking_items');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('room_calendars');
        Schema::dropIfExists('amenity_room');
        Schema::dropIfExists('rooms');
        Schema::dropIfExists('amenity_property');
        Schema::dropIfExists('properties');
        Schema::dropIfExists('media');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('users');
    }
};

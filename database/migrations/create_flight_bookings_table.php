<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flight_bookings', function (Blueprint $table) {
            $table->id();
            $table->string('session_identifier')->nullable()->index();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->onDelete('set null'); // Si un utilisateur est supprimé, on garde l'historique de la réservation
            $table->string('pnr')->nullable()->unique()->index();
            $table->string('booking_type')->default('now'); // 'now' (immédiat) ou 'hold' (bloquer le tarif)
            $table->string('booking_status')->default('pending'); // 'pending', 'confirmed', 'ticketed', 'cancelled'
            $table->json('raw_flight_data')->nullable();
            // Tarification et Paiement
            $table->decimal('total_amount', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0.00);
            $table->string('currency', 3)->default('XAF');
            $table->string('payment_method')->nullable(); // 'momo', 'om', 'card'
            $table->string('payment_status')->default('unpaid'); // 'unpaid', 'partially_paid', 'paid', 'refunded'

            // Contact principal
            $table->string('contact_email');
            $table->string('contact_phone');

            $table->timestamps();
        });
        Schema::create('flight_booking_trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained()->onDelete('cascade');

            // Logique de tri pour le multi-destination
            $table->integer('sort_order')->default(0);

            // Identifiants GDS indispensables pour la re-tarification (Air Price)
            $table->string('offering_id')->index();
            $table->string('brand_value')->index();
            $table->string('gds_authority_value')->nullable();

            // Informations de vol pour l'affichage résumé en back-office / historique
            $table->string('origin', 3);      // Ex: DLA
            $table->string('destination', 3); // Ex: CDG
            $table->dateTime('departure_time');
            $table->dateTime('arrival_time');
            $table->string('airline_code', 3);
            $table->string('flight_number');

            $table->timestamps();
        });
        Schema::create('flight_passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_booking_id')->constrained()->onDelete('cascade');

            $table->string('passenger_type')->default('ADT'); // ADT (Adulte), CHD (Enfant), INF (Bébé)
            $table->string('title', 10); // MR, MRS, MS
            $table->string('first_name');
            $table->string('last_name');
            $table->date('birth_date');

            // Optionnel : Infos passeport requises sur les vols internationaux
            $table->string('passport_number')->nullable();
            $table->string('passport_expiry')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flight_bookings');
    }
};

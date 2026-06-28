<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Identifiants
            $table->string('reference_num')->nullable();
            $table->string('supplier_confirmation_num')->nullable();
            $table->string('client_ref_num');
            $table->string('product_id');

            // Hôtel
            $table->string('hotel_id');
            $table->string('session_id');
            $table->string('token_id');
            $table->string('rate_basis_id');

            // Séjour
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedTinyInteger('days');

            // Tarif
            $table->string('currency', 3);
            $table->decimal('net_price', 10, 2);
            $table->string('fare_type');
            $table->json('cancellation_policy');

            // Statut
            $table->enum('status', [
                'PENDING','PENDING_PAYMENT',
                'CONFIRMED',
                'CANCELLED',
                'FAILED',
            ])->default('PENDING_PAYMENT');

            // Contact
            $table->string('customer_email');
            $table->string('customer_phone');
            $table->text('booking_note')->nullable();

            // Voyageurs + chambres (données brutes)
            $table->json('rooms_booked');
            $table->json('pax_details');
            $table->json('api_request_payload')->nullable();
            // Réponse API complète (audit)
            $table->json('api_response')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('hotel_id');
            $table->index('customer_email');
            $table->index('status');
            $table->index('check_in');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_bookings');
    }
};

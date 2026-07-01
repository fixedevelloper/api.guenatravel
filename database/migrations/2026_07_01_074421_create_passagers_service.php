<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('flight_passenger_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flight_passenger_id')
                ->constrained('flight_passengers')
                ->onDelete('cascade');

            // 'baggage', 'meal', 'seat'
            $table->string('service_type');
            $table->string('service_id');      // ex: 'XBPB', 'TCSW', 'MF8wXzFfMA'
            $table->string('seat_code')->nullable(); // ex: '1A' (uniquement pour les sièges)

            $table->string('description')->nullable(); // ex: '10 Kg' ou 'Tomato Cucumber Cheese...'
            $table->integer('quantity')->default(1);
            $table->integer('segment_index')->default(0);
            $table->string('direction');       // 'outbound' ou 'inbound'

            // Financier
            $table->decimal('amount', 10, 2)->default(0.00);
            $table->string('currency', 3)->default('USD');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('flight_passenger_services');
    }
};

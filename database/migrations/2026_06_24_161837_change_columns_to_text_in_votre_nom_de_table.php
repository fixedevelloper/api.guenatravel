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

        Schema::table('flight_booking_trips', function (Blueprint $table) {
            // 1. Supprimer d'abord les index string existants pour éviter les erreurs de structure SQL
            $table->dropIndex(['offering_id']);
            $table->dropIndex(['brand_value']);

            // Note : Si gds_authority_value avait aussi un index, décommentez la ligne ci-dessous :
            // $table->dropIndex(['gds_authority_value']);
        });

        Schema::table('flight_booking_trips', function (Blueprint $table) {
            // 2. Modifier le type des colonnes en TEXT pour accueillir les longs payloads Travelport
            $table->text('offering_id')->change();
            $table->text('brand_value')->change();
            $table->text('gds_authority_value')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('flight_booking_trips', function (Blueprint $table) {
            //
        });
    }
};

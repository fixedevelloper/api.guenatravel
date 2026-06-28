<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hotel_cities', function (Blueprint $table) {
            $table->id();
            $table->string('city_name');
            $table->string('country_name');
            $table->decimal('latitude',  10, 8);
            $table->decimal('longitude', 11, 8);
            $table->timestamps();

            $table->unique(['city_name', 'country_name']); // Clé métier stable
            $table->index('city_name');
            $table->index('country_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hotel_cities');
    }
};

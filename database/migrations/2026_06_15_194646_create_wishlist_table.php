<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('property_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Index unique pour éviter qu'un utilisateur n'ajoute
            // la même propriété plusieurs fois
            $table->unique(['user_id', 'property_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('wishlists');
    }
};

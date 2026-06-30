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
        Schema::create('search_histories', function (Blueprint $table) {
            $table->id();

            // Relation utilisateur moderne (crée le BigInteger, la clé étrangère et l'index en 1 ligne)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            // Session ID indexé pour les recherches d'utilisateurs invités (non connectés)
            $table->string('session_id')->nullable()->index();

            // Typage moderne pour le type de recherche (flight, hotel, car, package)
            // L'index permet des filtres ultra-rapides pour l'historique du Dashboard
            $table->enum('search_type', ['flight', 'hotel', 'car', 'package'])->index();

            // Critères génériques de recherche
            $table->string('city')->nullable(); // Ville/Aéroport de destination
            $table->date('check_in')->nullable(); // Date départ ou Check-in
            $table->date('check_out')->nullable(); // Date retour ou Check-out

            // Conteneur JSON pour stocker la granularité (ex: passagers, escales, filtres de prix)
            $table->json('search_payload')->nullable();

            $table->timestamps();

            // Index composite pour optimiser l'affichage chronologique par utilisateur
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('viewed_offers', function (Blueprint $table) {
            $table->id();

            // Relation utilisateur (nullable pour les visiteurs anonymes)
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            // Session ID pour suivre l'historique des invités sur Next.js
            $table->string('session_id')->nullable()->index();

            // CLÉ POLYMORPHIQUE MODERNE (Génère viewable_type et viewable_id)
            // Permet de lier l'offre vue à un modèle Property, Flight, Car, etc.
            $table->morphs('viewable');

            // Metadata optionnelle (ex: le prix affiché au moment de la consultation, devise)
            $table->decimal('price_at_view', 12, 2)->nullable();
            $table->string('currency', 3)->default('XAF');

            $table->timestamps();

            // Index composite pour nettoyer/récupérer rapidement les dernières offres vues d'un utilisateur
            $table->index(['user_id', 'created_at']);
        });
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplus');
    }
};

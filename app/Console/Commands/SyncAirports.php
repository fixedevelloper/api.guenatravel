<?php

namespace App\Console\Commands;

use App\Facades\TravelOpro;
use App\Models\Airport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncAirports extends Command
{
    /**
     * Le nom et la signature de la commande en console.
     */
    protected $signature = 'airport:sync';

    /**
     * La description de la commande.
     */
    protected $description = 'Récupère les aéroports depuis TravelOpro et les enregistre en base de données';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Récupération des aéroports depuis l\'API TravelOpro...');

        try {
            $apiAirports = TravelOpro::getAirportList();

            if (empty($apiAirports)) {
                $this->error('Aucun aéroport renvoyé par l\'API.');
                return Command::FAILURE;
            }

            $this->info(count($apiAirports) . ' aéroports trouvés. Importation en cours...');

            // On utilise une transaction et un découpage (chunk) pour éviter de surcharger la base de données
            DB::transaction(function () use ($apiAirports) {

                // Optionnel : Vider la table avant l'import si vous voulez repartir à zéro
                // Airport::truncate();

                $dataToInsert = [];
                $now = now();

                foreach ($apiAirports as $airport) {
                    $dataToInsert[] = [
                        'airport_code' => $airport['AirportCode'],
                        'airport_name' => $airport['AirportName'],
                        'city'         => $airport['City'],
                        'country'      => $airport['Country'],
                        'created_at'   => $now,
                        'updated_at'   => $now,
                    ];

                    // On insère par paquets de 500 pour les performances
                    if (count($dataToInsert) === 500) {
                        Airport::upsert($dataToInsert, ['airport_code'], ['airport_name', 'city', 'country']);
                        $dataToInsert = [];
                    }
                }

                // Insérer le reste
                if (!empty($dataToInsert)) {
                    Airport::upsert($dataToInsert, ['airport_code'], ['airport_name', 'city', 'country']);
                }
            });

            $this->info('✓ Tous les aéroports ont été synchronisés avec succès !');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->error('Une erreur est survenue lors de la synchronisation : ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}

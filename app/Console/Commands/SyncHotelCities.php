<?php

namespace App\Console\Commands;

use App\Models\HotelCity;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;

class SyncHotelCities extends Command
{
    protected $signature = 'hotels:sync-cities
                            {--limit=100    : Nombre de villes par appel API}
                            {--batch=50     : Taille des lots pour upsert en BDD}
                            {--force        : Vide la table avant la synchronisation}
                            {--delay=500    : Délai en ms entre chaque appel API}';

    protected $description = 'Synchronise toutes les villes hôtelières depuis l\'API Travelopro';

    private int $inserted = 0;
    private int $updated  = 0;
    private int $failed   = 0;
    private int $consecutiveFailures = 0;

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $batch = (int) $this->option('batch');
        $delay = (int) $this->option('delay');

        $this->info("🏨 Synchronisation des villes hôtelières");
        $this->info("   Taille page API : {$limit} | Lot upsert : {$batch} | Délai : {$delay}ms");
        $this->newLine();

        if ($this->option('force')) {
            $this->warn('⚠️  Mode --force : suppression des données existantes...');
            HotelCity::truncate();
            $this->info('✓  Table vidée.');
            $this->newLine();
        }

        $this->info('📡 Connexion à l\'API...');

        $from = 1;
        $to = $limit;
        $page = 1;

        $bar = $this->output->createProgressBar();
        $bar->setFormat(" Page %current% (%message%)");
        $bar->start();

        while (true) {
            // Sécurité boucle infinie (ex: max 10 000 pages)
            if ($page > 10000) {
                $this->error('❌ Sécurité : Nombre maximum de pages atteint.');
                break;
            }

            if ($page > 1) {
                usleep($delay * 1000); // Évite le rate limit (sauf première page)
            }

            $cities = $this->fetchPage($from, $to);

            if ($cities === null) {
                $this->consecutiveFailures++;
                if ($this->consecutiveFailures >= 3) {
                    $bar->finish();
                    $this->newLine();
                    $this->error('❌ 3 échecs consécutifs. Arrêt de la synchronisation.');
                    break;
                }
                $bar->setMessage("⚠️ Erreur page {$page}, nouvelle tentative...");
                continue;
            }

            // Condition d'arrêt : plus de données renvoyées
            if (empty($cities)) {
                $bar->setMessage("Fin des données.");
                $bar->finish();
                $this->newLine();
                break;
            }

            $this->consecutiveFailures = 0;
            $this->upsertCities($cities, $batch);

            $bar->setMessage(sprintf("Index %d à %d traités", $from, $to));
            $bar->advance();

            // Préparation des index du prochain bloc
            $from = $to + 1;
            $to = $from + $limit - 1;
            $page++;
        }

        $this->printSummary();

        return $this->failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function fetchPage(int $from, int $to): ?array
    {
        try {
            $response = Http::timeout(15)->get(
                'https://travelnext.works/api/hotel-api-v6/cities',
                [
                    'from'          => $from,
                    'to'            => $to,
                    'user_id'       => config('travelopro.user_id'),
                    'user_password' => config('travelopro.user_password'),
                    'ip_address'    => config('travelopro.ip_address'),
                    'access'        => config('travelopro.access'),
                ]
            );

            if (!$response->successful()) {
                $this->newLine();
                $this->error("❌ HTTP {$response->status()} pour [{$from}→{$to}]");
                return null;
            }

            $data = $response->json();

            return $data['cities'] ?? [];

        } catch (\Exception $e) {
            $this->newLine();
            $this->error("❌ Exception [{$from}→{$to}] : {$e->getMessage()}");
            return null;
        }
    }

    private function upsertCities(array $cities, int $batch): void
    {
        collect($cities)
            ->chunk($batch)
            ->each(function ($chunk) {
                $rows = $chunk->map(fn($city) => [
                    'city_name'    => trim($city['city_name']),
                    'country_name' => trim($city['country_name']),
                    'latitude'     => (float) $city['latitude'],
                    'longitude'    => (float) $city['longitude'],
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ])->all();

                try {
                    $existingKeys = HotelCity::where(function ($query) use ($rows) {
                        foreach ($rows as $row) {
                            $query->orWhere(fn($q) => $q
                                ->where('city_name',    $row['city_name'])
                                ->where('country_name', $row['country_name'])
                            );
                        }
                    })
                        ->count();

                    DB::table('hotel_cities')->upsert(
                        $rows,
                        ['city_name', 'country_name'],           // Clé unique
                        ['latitude', 'longitude', 'updated_at']  // Colonnes à mettre à jour
                    );

                    $this->inserted += count($rows) - $existingKeys;
                    $this->updated  += $existingKeys;

                } catch (\Exception $e) {
                    $this->newLine();
                    $this->error("❌ Upsert échoué : {$e->getMessage()}");
                    $this->failed += count($rows);
                }
            });
    }

    private function printSummary(): void
    {
        $this->newLine();
        $this->table(
            ['Métrique', 'Valeur'],
            [
                ['✅ Insérées',       $this->inserted],
                ['🔄 Mises à jour',   $this->updated],
                ['❌ Échouées',       $this->failed],
                ['📊 Total traité',   $this->inserted + $this->updated],
                ['🗃️  Total en base', HotelCity::count()],
            ]
        );
    }
}

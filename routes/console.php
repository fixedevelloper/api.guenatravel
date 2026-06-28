<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

// Exécute notre script de vérification des paiements automatiquement toutes les minutes
Schedule::command('payments:check-status')->everyMinute()->withoutOverlapping();
Schedule::command('booking:verify-payment')->everyMinute();
// Exécution chaque dimanche à minuit
Schedule::command('hotels:sync-cities --limit=200 --batch=100 --delay=200')
    ->weekly()
    ->sundays()
    ->at('00:00')
    ->runInBackground() // Évite de bloquer les autres tâches
    ->withoutOverlapping(); // Sécurité pour ne pas lancer 2 fois la commande si elle est longue

<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

use Illuminate\Support\Facades\Schedule;

// Exécute notre script de vérification des paiements automatiquement toutes les minutes
Schedule::command('payments:check-status')->everyMinute()->withoutOverlapping();

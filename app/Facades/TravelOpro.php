<?php

namespace App\Facades;

use Illuminate\Support\Facades\Facade;

class TravelOpro extends Facade
{
    /**
     * Obtenir le nom enregistré du composant.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'travelopro.service';
    }
}

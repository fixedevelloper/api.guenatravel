<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HotelCity extends Model
{
    protected $fillable = [
        'api_id',
        'city_name',
        'country_name',
        'latitude',
        'longitude',
    ];

    protected $casts = [
        'latitude'  => 'float',
        'longitude' => 'float',
        'api_id'    => 'integer',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Airport extends Model
{
    protected $fillable = [
        'airport_code',
        'airport_name',
        'city',
        'country',
    ];
}

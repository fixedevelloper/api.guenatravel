<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightPassengerService extends Model
{
    protected $fillable = [
        'flight_passenger_id',
        'service_type',
        'service_id',
        'seat_code',
        'description',
        'quantity',
        'segment_index',
        'direction',
        'amount',
        'currency'
    ];

    public function passenger(): BelongsTo
    {
        return $this->belongsTo(FlightPassenger::class, 'flight_passenger_id');
    }
}

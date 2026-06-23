<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightBookingTrip extends Model
{
    protected $fillable = [
        'flight_booking_id',
        'sort_order',
        'offering_id',
        'brand_value',
        'gds_authority_value',
        'origin',
        'destination',
        'departure_time',
        'arrival_time',
        'airline_code',
        'flight_number'
    ];

    protected $casts = [
        'departure_time' => 'datetime',
        'arrival_time' => 'datetime',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }
}

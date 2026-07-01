<?php


namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightPassenger extends Model
{
    protected $fillable = [
        'flight_booking_id',
        'passenger_type',
        'title',
        'first_name',
        'last_name',
        'birth_date',
        'passport_number',
        'passport_expiry'
    ];

    protected $casts = [
        'birth_date' => 'date',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }
    public function services()
    {
        return $this->hasMany(FlightPassengerService::class, 'flight_passenger_id');
    }
}

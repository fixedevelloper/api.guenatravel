<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HotelBooking extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'reference_num',
        'supplier_confirmation_num',
        'client_ref_num',
        'product_id',
        'hotel_id',
        'session_id',
        'token_id',
        'rate_basis_id',
        'check_in',
        'check_out',
        'days',
        'currency',
        'net_price',
        'fare_type',
        'cancellation_policy',
        'status',
        'customer_email',
        'customer_phone',
        'booking_note',
        'rooms_booked',
        'pax_details',
        'api_response',
        'api_request_payload'
    ];

    protected $casts = [
        'check_in'            => 'date',
        'check_out'           => 'date',
        'net_price'           => 'float',
        'days'                => 'integer',
        'cancellation_policy' => 'array',
        'rooms_booked'        => 'array',
        'pax_details'         => 'array',
        'api_response'        => 'array',
        'api_request_payload' => 'array'
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Scopes utiles
    public function scopeConfirmed($query)
    {
        return $query->where('status', 'CONFIRMED');
    }

    public function scopeForEmail($query, string $email)
    {
        return $query->where('customer_email', $email);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('check_in', '>=', now()->toDateString());
    }
}

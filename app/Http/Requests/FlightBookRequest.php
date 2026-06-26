<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FlightBookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // À lier à vos guards/policies si l'utilisateur doit être connecté
    }

    public function rules(): array
    {
        return [
            // Flight Booking Info
            'flightBookingInfo'                          => 'required|array',
            'flightBookingInfo.flight_session_id'        => 'required|string',
            'flightBookingInfo.fare_source_code'         => 'required|string',
            'flightBookingInfo.IsPassportMandatory'      => 'required|string',
            'flightBookingInfo.fareType'                 => 'required|string',
            'flightBookingInfo.areaCode'                 => 'required|string',
            'flightBookingInfo.countryCode'              => 'required|string',

            // Pax Info Globale
            'paxInfo'                                    => 'required|array',
            'paxInfo.clientRef'                          => 'required|string',
            'paxInfo.postCode'                           => 'required|string',
            'paxInfo.customerEmail'                      => 'required|email',
            'paxInfo.customerPhone'                      => 'required|string',
            'paxInfo.bookingNote'                        => 'nullable|string',

            // Validation de la liste linéarisée des passagers
            'paxInfo.paxDetails'                         => 'required|array|min:1',
            'paxInfo.paxDetails.*.type'                  => 'required|string|in:Adult,Child,Infant',
            'paxInfo.paxDetails.*.title'                 => 'required|string',
            'paxInfo.paxDetails.*.firstName'             => 'required|string|max:100',
            'paxInfo.paxDetails.*.lastName'              => 'required|string|max:100',
            'paxInfo.paxDetails.*.dob'                   => 'required|date_format:Y-m-d',
            'paxInfo.paxDetails.*.nationality'           => 'required|string|size:2',
            'paxInfo.paxDetails.*.passportNo'            => 'required|string',
            'paxInfo.paxDetails.*.passportIssueCountry'  => 'required|string|size:2',
            'paxInfo.paxDetails.*.passportExpiryDate'    => 'required|date_format:Y-m-d',
            'paxInfo.paxDetails.*.ExtraServiceOutbound'  => 'nullable|array',
            'paxInfo.paxDetails.*.ExtraServiceInbound'   => 'nullable|array',
        ];
    }
}

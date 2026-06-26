<?php

// app/Http/Resources/FlightResource.php
namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class FlightResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        $itin = $this['FareItinerary'] ?? [];
        $fareInfo = $itin['AirItineraryFareInfo'] ?? [];
        $itinTotalFares = $fareInfo['ItinTotalFares'] ?? [];

        // Extraction de toutes les options de voyage (Aller, Retour, etc.)
        $journeys = [];
        $originDestOptions = $itin['OriginDestinationOptions']['OriginDestinationOption'] ?? [];

        foreach ($originDestOptions as $journeyIndex => $option) {
            $segments = [];
            // Un voyage peut comporter plusieurs escales (segments)
            $flightSegments = $option['FlightSegment'] ?? [];

            // Si c'est un segment unique (tableau associatif simple), on le normalise en tableau
            if (isset($flightSegments['FlightNumber'])) {
                $flightSegments = [$flightSegments];
            }

            foreach ($flightSegments as $segment) {
                $segments[] = [
                    'departure' => [
                        'airport_code' => $segment['DepartureAirportCode'] ?? null,
                        'terminal' => $segment['DepartureTerminal'] ?? null,
                        'date_time' => $segment['DepartureDateTime'] ?? null,
                    ],
                    'arrival' => [
                        'airport_code' => $segment['ArrivalAirportCode'] ?? null,
                        'terminal' => $segment['ArrivalTerminal'] ?? null,
                        'date_time' => $segment['ArrivalDateTime'] ?? null,
                    ],
                    'marketing_airline' => [
                        'code' => $segment['MarketingAirlineCode'] ?? null,
                        'name' => $segment['MarketingAirlineName'] ?? null,
                    ],
                    'operating_airline' => [
                        'code' => $segment['OperatingAirlineCode'] ?? null,
                    ],
                    'flight_number' => $segment['FlightNumber'] ?? null,
                    'duration_minutes' => $segment['JourneyDuration'] ?? null,
                    'cabin_class' => $segment['CabinClass'] ?? null,
                    'res_book_desig_code' => $segment['ResBookDesigCode'] ?? null,
                    'marriage_group' => $segment['MarriageGroup'] ?? null,
                    'is_eticket_eligible' => $segment['IsETicketEligible'] ?? null,
                ];
            }

            $journeys[] = [
                'direction_index' => $journeyIndex, // 0 pour l'aller, 1 pour le retour
                'stops_count' => count($segments) - 1,
                'segments' => $segments
            ];
        }

        // Extraction détaillée des tarifs et taxes
        $fareBreakdown = [];
        $breakdowns = $itin['FareBreakdown']['FareBreakdown'] ?? [];
        if (isset($breakdowns['PassengerTypeQuantity'])) {
            $breakdowns = [$breakdowns];
        }

        foreach ($breakdowns as $breakdown) {
            $fareBreakdown[] = [
                'passenger_type' => $breakdown['PassengerTypeQuantity']['PassengerType'] ?? null,
                'quantity' => $breakdown['PassengerTypeQuantity']['Quantity'] ?? null,
                'base_fare' => $breakdown['PassengerFare']['BaseFare']['Amount'] ?? null,
                'total_fare' => $breakdown['PassengerFare']['TotalFare']['Amount'] ?? null,
                'taxes_total' => $breakdown['PassengerFare']['Taxes']['TotalTax']['Amount'] ?? null,
                'baggage' => [
                    'check_in' => $breakdown['Baggage']['CheckInBaggage'] ?? null,
                    'cabin' => $breakdown['Baggage']['CabinBaggage'] ?? null,
                ]
            ];
        }

        return [
            // Identifiant critique pour la tarification finale et le booking
            'fare_source_code' => $fareInfo['FareSourceCode'] ?? null,
            'is_refundable' => filter_var($itin['IsRefundable'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'passport_mandatory' => filter_var($itin['PassportMandatory'] ?? false, FILTER_VALIDATE_BOOLEAN),

            // Tarification Globale
            'pricing' => [
                'currency' => $itinTotalFares['TotalFare']['CurrencyCode'] ?? null,
                'total_amount' => $itinTotalFares['TotalFare']['Amount'] ?? null,
                'base_amount' => $itinTotalFares['BaseFare']['Amount'] ?? null,
                'tax_amount' => $itinTotalFares['TotalTax']['Amount'] ?? null,
                'construction_amount' => $itinTotalFares['TotalConstructionAmount']['Amount'] ?? null,
                'breakdown' => $fareBreakdown
            ],

            // Détails de tous les trajets (Aller et Retour inclus)
            'journeys' => $journeys,
        ];
    }
}

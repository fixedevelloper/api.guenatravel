<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\HotelBooking;
use App\Models\FlightBooking;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $now        = Carbon::now();
        $startMonth = $now->copy()->startOfMonth();
        $last30Days = $now->copy()->subDays(30);

        return response()->json([
            'success' => true,
            'data'    => [
                'kpis'              => $this->getKpis($startMonth),
                'revenue_chart'     => $this->getRevenueChart($last30Days),
                'bookings_by_type'  => $this->getBookingsByType(),
                'recent_bookings'   => $this->getRecentBookings(),
                'top_properties'    => $this->getTopProperties(),
                'status_breakdown'  => $this->getStatusBreakdown(),
            ],
        ]);
    }

    private function getKpis(Carbon $startMonth): array
    {
        $prevMonthStart = $startMonth->copy()->subMonth();

        // ── Hôtels ───────────────────────────────────────────────────────────
        $hotelTotal     = HotelBooking::count();
        $hotelMonth     = HotelBooking::where('created_at', '>=', $startMonth)->count();
        $hotelPrevMonth = HotelBooking::whereBetween('created_at', [$prevMonthStart, $startMonth])->count();

        $hotelRevenueTotal     = HotelBooking::where('status', 'CONFIRMED')->sum('net_price');
        $hotelRevenueMonth     = HotelBooking::where('status', 'CONFIRMED')->where('created_at', '>=', $startMonth)->sum('net_price');
        $hotelRevenuePrevMonth = HotelBooking::where('status', 'CONFIRMED')->whereBetween('created_at', [$prevMonthStart, $startMonth])->sum('net_price');

        $hotelPending = HotelBooking::where('status', 'PENDING')->count();

        // ── Vols ─────────────────────────────────────────────────────────────
        $flightTotal     = FlightBooking::count();
        $flightMonth     = FlightBooking::where('created_at', '>=', $startMonth)->count();
        $flightPrevMonth = FlightBooking::whereBetween('created_at', [$prevMonthStart, $startMonth])->count();

        $flightRevenueTotal     = FlightBooking::where('booking_status', 'CONFIRMED')->sum('total_amount');
        $flightRevenueMonth     = FlightBooking::where('booking_status', 'CONFIRMED')->where('created_at', '>=', $startMonth)->sum('total_amount');
        $flightRevenuePrevMonth = FlightBooking::where('booking_status', 'CONFIRMED')->whereBetween('created_at', [$prevMonthStart, $startMonth])->sum('total_amount');

        $flightPending = FlightBooking::where('booking_status', 'PENDING')->count();

        // ── Combinés ─────────────────────────────────────────────────────────
        $totalBookings     = $hotelTotal + $flightTotal;
        $totalBookingsMonth = $hotelMonth + $flightMonth;
        $totalBookingsPrev  = $hotelPrevMonth + $flightPrevMonth;

        $totalRevenue     = $hotelRevenueTotal + $flightRevenueTotal;
        $totalRevenueMonth = $hotelRevenueMonth + $flightRevenueMonth;
        $totalRevenuePrev  = $hotelRevenuePrevMonth + $flightRevenuePrevMonth;

        $usersTotal = User::count();
        $usersMonth = User::where('created_at', '>=', $startMonth)->count();

        return [
            'total_bookings' => [
                'value'        => $totalBookings,
                'change'       => $this->percentChange($totalBookingsPrev, $totalBookingsMonth),
                'hotels'       => $hotelTotal,
                'flights'      => $flightTotal,
            ],
            'total_revenue' => [
                'value'   => (float) $totalRevenue,
                'change'  => $this->percentChange($totalRevenuePrev, $totalRevenueMonth),
                'hotels'  => (float) $hotelRevenueTotal,
                'flights' => (float) $flightRevenueTotal,
            ],
            'pending_bookings' => [
                'value'   => $hotelPending + $flightPending,
                'hotels'  => $hotelPending,
                'flights' => $flightPending,
            ],
            'total_users' => [
                'value'  => $usersTotal,
                'change' => $usersMonth,
            ],
        ];
    }

    private function percentChange(float $previous, float $current): float
    {
        if ($previous == 0) return $current > 0 ? 100 : 0;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function getRevenueChart(Carbon $since): array
    {
        $hotelData = HotelBooking::where('status', 'CONFIRMED')
            ->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(net_price) as revenue'))
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $flightData = FlightBooking::where('booking_status', 'CONFIRMED')
            ->where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('SUM(total_amount) as revenue'))
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $period = collect();
        $cursor = $since->copy();
        while ($cursor <= now()) {
            $dateStr     = $cursor->format('Y-m-d');
            $hotelRev    = (float) ($hotelData[$dateStr]->revenue  ?? 0);
            $flightRev   = (float) ($flightData[$dateStr]->revenue ?? 0);

            $period->push([
                'date'    => $dateStr,
                'hotels'  => $hotelRev,
                'flights' => $flightRev,
                'revenue' => $hotelRev + $flightRev,
            ]);

            $cursor->addDay();
        }

        return $period->toArray();
    }

    private function getBookingsByType(): array
    {
        return [
            ['type' => 'hotels',  'count' => HotelBooking::count()],
            ['type' => 'flights', 'count' => FlightBooking::count()],
        ];
    }

    private function getRecentBookings(int $limit = 8): array
    {
        $hotels = HotelBooking::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get(['id', 'customer_email', 'net_price as amount', 'currency', 'status', 'created_at'])
            ->map(fn($b) => [
                'id'         => $b->id,
                'type'       => 'hotel',
                'contact'    => $b->customer_email,
                'amount'     => $b->amount,
                'currency'   => $b->currency,
                'status'     => $b->status,
                'created_at' => $b->created_at,
            ]);

        $flights = FlightBooking::orderBy('created_at', 'desc')
            ->limit($limit)
            ->get(['id', 'contact_email', 'total_amount as amount', 'currency', 'booking_status as status', 'created_at', 'pnr'])
            ->map(fn($b) => [
                'id'         => $b->id,
                'type'       => 'flight',
                'contact'    => $b->contact_email,
                'amount'     => $b->amount,
                'currency'   => $b->currency,
                'status'     => $b->status,
                'created_at' => $b->created_at,
                'pnr'        => $b->pnr,
            ]);

        return $hotels->concat($flights)
            ->sortByDesc('created_at')
            ->take($limit)
            ->values()
            ->toArray();
    }

    private function getTopProperties(int $limit = 5): array
    {
        return HotelBooking::where('status', 'CONFIRMED')
            ->select('hotel_id', DB::raw('COUNT(*) as bookings_count'), DB::raw('SUM(net_price) as revenue'))
            ->groupBy('hotel_id')
            ->orderByDesc('bookings_count')
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $property = DB::table('properties')->find($row->hotel_id);
                return [
                    'hotel_id'       => $row->hotel_id,
                    'name'           => $property?->name ?? 'Établissement #' . $row->hotel_id,
                    'bookings_count' => $row->bookings_count,
                    'revenue'        => (float) $row->revenue,
                ];
            })
            ->toArray();
    }

    private function getStatusBreakdown(): array
    {
        $hotelStatuses = HotelBooking::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->get()
            ->keyBy('status');

        $flightStatuses = FlightBooking::select('booking_status as status', DB::raw('COUNT(*) as count'))
            ->groupBy('booking_status')
            ->get()
            ->keyBy('status');

        $statuses = collect(['CONFIRMED', 'PENDING', 'CANCELLED', 'FAILED']);

        return $statuses->map(function ($status) use ($hotelStatuses, $flightStatuses) {
            $hotelCount  = $hotelStatuses[$status]->count  ?? 0;
            $flightCount = $flightStatuses[$status]->count ?? 0;

            return [
                'status' => $status,
                'count'  => $hotelCount + $flightCount,
            ];
        })
            ->filter(fn($s) => $s['count'] > 0)
            ->values()
            ->toArray();
    }
}

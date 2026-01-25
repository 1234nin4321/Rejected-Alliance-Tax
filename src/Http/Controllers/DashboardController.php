<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Rejected\SeatAllianceTax\Models\AllianceMiningActivity;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;

class DashboardController extends Controller
{
    /**
     * Display the alliance mining tax dashboard.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        // Get current period stats
        $currentPeriodStats = $this->getCurrentPeriodStats();

        // Get top miners this period
        $topMiners = $this->getTopMiners();

        // Get tax collection summary
        $taxSummary = $this->getTaxSummary();

        // Get recent activity
        $recentActivity = AllianceMiningActivity::with(['character', 'corporation'])
            ->orderBy('mining_date', 'desc')
            ->limit(50)
            ->get();

        return view('alliancetax::dashboard', compact(
            'currentPeriodStats',
            'topMiners',
            'taxSummary',
            'recentActivity'
        ));
    }

    /**
     * Get statistics for the current tax period.
     *
     * @return array
     */
    private function getCurrentPeriodStats()
    {
        $period = $this->getCurrentPeriod();

        return [
            'total_mined_value' => AllianceMiningActivity::whereBetween('mining_date', [$period['start'], $period['end']])
                ->sum('estimated_value'),
            'total_tax_owed' => AllianceTaxCalculation::whereBetween('period_start', [$period['start'], $period['end']])
                ->where('is_paid', false)
                ->sum('tax_amount'),
            'total_tax_paid' => AllianceTaxCalculation::whereBetween('period_start', [$period['start'], $period['end']])
                ->where('is_paid', true)
                ->sum('tax_amount'),
            'active_miners' => AllianceMiningActivity::whereBetween('mining_date', [$period['start'], $period['end']])
                ->distinct('character_id')
                ->count('character_id'),
        ];
    }

    /**
     * Get top miners for the current period.
     *
     * @param int $limit
     * @return \Illuminate\Support\Collection
     */
    private function getTopMiners($limit = 10)
    {
        $period = $this->getCurrentPeriod();

        return AllianceMiningActivity::select('character_id', DB::raw('SUM(estimated_value) as total_value'))
            ->whereBetween('mining_date', [$period['start'], $period['end']])
            ->groupBy('character_id')
            ->orderBy('total_value', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get tax collection summary.
     *
     * @return array
     */
    private function getTaxSummary()
    {
        return [
            'total_outstanding' => AllianceTaxCalculation::where('is_paid', false)->sum('tax_amount'),
            'total_collected' => AllianceTaxCalculation::where('is_paid', true)->sum('tax_amount'),
            'pending_count' => AllianceTaxCalculation::where('is_paid', false)->count(),
        ];
    }

    /**
     * Get the current tax period dates.
     *
     * @return array
     */
    private function getCurrentPeriod()
    {
        $periodType = config('alliancetax.tax_period', 'weekly');

        if ($periodType === 'weekly') {
            return [
                'start' => now()->startOfWeek(),
                'end' => now()->endOfWeek(),
            ];
        }

        return [
            'start' => now()->startOfMonth(),
            'end' => now()->endOfMonth(),
        ];
    }
}

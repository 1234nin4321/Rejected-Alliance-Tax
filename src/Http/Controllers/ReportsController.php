<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceMiningActivity;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;

class ReportsController extends Controller
{
    /**
     * Display alliance report.
     */
    public function alliance(Request $request)
    {
        $periodType = $request->input('period_type', 'weekly');
        $periodStart = $request->input('period_start') 
            ? Carbon::parse($request->input('period_start'))
            : Carbon::now()->startOfWeek();
        $periodEnd = $request->input('period_end')
            ? Carbon::parse($request->input('period_end'))
            : Carbon::now()->endOfWeek();

        // Overall statistics
        $totalMined = AllianceMiningActivity::whereBetween('mining_date', [$periodStart, $periodEnd])
            ->sum('estimated_value');

        $totalTaxGross = AllianceTaxCalculation::whereBetween('period_start', [$periodStart, $periodEnd])
            ->sum('tax_amount_gross');

        $totalTaxPending = AllianceTaxCalculation::where('status', 'pending')
            ->whereBetween('period_start', [$periodStart, $periodEnd])
            ->sum('tax_amount');

        $totalTaxPaid = AllianceTaxCalculation::where('status', 'paid')
            ->whereBetween('period_start', [$periodStart, $periodEnd])
            ->sum('tax_amount');

        // Mining breakdown by category
        $miningByCategory = $this->getMiningByCategory($periodStart, $periodEnd);

        // Top miners
        $topMiners = AllianceMiningActivity::select('character_id', DB::raw('SUM(estimated_value) as total_value'))
            ->whereBetween('mining_date', [$periodStart, $periodEnd])
            ->groupBy('character_id')
            ->orderByDesc('total_value')
            ->limit(10)
            ->with('character')
            ->get();

        // Corporation breakdown
        $corpBreakdown = AllianceTaxCalculation::select('corporation_id', 
                DB::raw('SUM(total_mined_value) as total_mined'),
                DB::raw('SUM(tax_amount_gross) as total_tax_gross'),
                DB::raw('SUM(tax_amount) as net_tax_owed'))
            ->whereBetween('period_start', [$periodStart, $periodEnd])
            ->groupBy('corporation_id')
            ->orderByDesc('total_mined')
            ->with('corporation')
            ->get();

        return view('alliancetax::reports.alliance', compact(
            'periodType', 'periodStart', 'periodEnd',
            'totalMined', 'totalTaxGross', 'totalTaxPending', 'totalTaxPaid',
            'miningByCategory', 'topMiners', 'corpBreakdown'
        ));
    }

    /**
     * Get mining breakdown by category
     */
    private function getMiningByCategory($periodStart, $periodEnd)
    {
        $activities = AllianceMiningActivity::whereBetween('mining_date', [$periodStart, $periodEnd])->get();
        
        $breakdown = [
            'ore' => 0,
            'ice' => 0,
            'moon_r4' => 0,
            'moon_r8' => 0,
            'moon_r16' => 0,
            'moon_r32' => 0,
            'moon_r64' => 0,
        ];

        foreach ($activities as $activity) {
            $category = $this->getItemCategory($activity->type_id);
            if (isset($breakdown[$category])) {
                $breakdown[$category] += $activity->estimated_value;
            }
        }

        return $breakdown;
    }

    /**
     * Determine item category
     */
    private function getItemCategory($typeId)
    {
        $groupInfo = DB::table('invTypes')
            ->join('invGroups', 'invTypes.groupID', '=', 'invGroups.groupID')
            ->where('invTypes.typeID', $typeId)
            ->select('invGroups.groupID', 'invGroups.categoryID')
            ->first();

        if (!$groupInfo || $groupInfo->categoryID != 25) {
            return 'ore';
        }

        if ($groupInfo->groupID == 465) {
            return 'ice';
        }

        switch ($groupInfo->groupID) {
            case 1884: return 'moon_r4';
            case 1920: return 'moon_r8';
            case 1921: return 'moon_r16';
            case 1922: return 'moon_r32';
            case 1923: return 'moon_r64';
        }

        return 'ore';
    }

    /**
     * Export alliance report to CSV
     */
    public function export(Request $request)
    {
        $periodStart = $request->input('period_start') 
            ? Carbon::parse($request->input('period_start'))
            : Carbon::now()->startOfWeek();
        $periodEnd = $request->input('period_end')
            ? Carbon::parse($request->input('period_end'))
            : Carbon::now()->endOfWeek();

        $taxes = AllianceTaxCalculation::with(['character', 'corporation'])
            ->whereBetween('period_start', [$periodStart, $periodEnd])
            ->get();

        $filename = "alliance_tax_report_{$periodStart->format('Y-m-d')}_to_{$periodEnd->format('Y-m-d')}.csv";

        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        $callback = function() use ($taxes) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['Character', 'Corporation', 'Period', 'Mined Value', 'Tax Rate', 'Tax Amount', 'Status']);

            foreach ($taxes as $tax) {
                fputcsv($file, [
                    $tax->character->name ?? 'Unknown',
                    $tax->corporation->name ?? 'Unknown',
                    $tax->tax_period,
                    number_format($tax->total_mined_value, 2),
                    number_format($tax->applicable_tax_rate ?? $tax->tax_rate, 2) . '%',
                    number_format($tax->tax_amount, 2),
                    $tax->status ?? ($tax->is_paid ? 'paid' : 'pending'),
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}

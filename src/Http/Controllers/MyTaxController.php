<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;
use Rejected\SeatAllianceTax\Models\AllianceMiningActivity;
use Rejected\SeatAllianceTax\Models\AllianceTaxRate;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
use Rejected\SeatAllianceTax\Models\AllianceTaxSystem;
use Rejected\SeatAllianceTax\Helpers\OreCategory;

class MyTaxController extends Controller
{
    /**
     * Display user's personal tax information
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $user = auth()->user();
        
        // Get all user's characters
        $characterIds = $user->characters->pluck('character_id');

        // Get pending taxes (includes 'pending' and 'sent' statuses - i.e., not yet paid)
        $pendingTaxes = AllianceTaxCalculation::whereIn('character_id', $characterIds)
            ->where(function($q) {
                $q->whereIn('status', ['pending', 'sent'])
                  ->orWhere('is_paid', false);
            })
            ->where('status', '!=', 'paid') // Ensure we exclude paid
            ->orderBy('tax_period', 'desc')
            ->get();

        // Get paid taxes (last 3 months of periods)
        $paidTaxes = AllianceTaxCalculation::whereIn('character_id', $characterIds)
            ->where(function($q) {
                $q->where('status', 'paid')
                  ->orWhere('is_paid', true);
            })
            ->where('tax_period', '>=', now()->subMonths(3)->startOfMonth())
            ->orderBy('tax_period', 'desc')
            ->get();

        // Calculate totals
        $totalPending = $pendingTaxes->sum('tax_amount');
        $totalPaid = $paidTaxes->sum('tax_amount');

        // Get recent mining activity (last 30 days)
        $recentActivity = AllianceMiningActivity::whereIn('character_id', $characterIds)
            ->where('mining_date', '>=', now()->subDays(30))
            ->with('character')
            ->orderBy('mining_date', 'desc')
            ->limit(50)
            ->get();

        // Mining summary by character
        $miningSummary = AllianceMiningActivity::whereIn('character_id', $characterIds)
            ->where('mining_date', '>=', now()->subMonths(3))
            ->select(
                'character_id',
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(estimated_value) as total_value'),
                DB::raw('COUNT(*) as mining_sessions')
            )
            ->groupBy('character_id')
            ->get();
        
        // Load character names for summary
        $miningSummary->load('character');

        // Get total tax credit balance
        $totalBalance = \Rejected\SeatAllianceTax\Models\AllianceTaxBalance::whereIn('character_id', $characterIds)
            ->sum('balance');

        // Get tax collection corporation info
        $taxCorpId = \Rejected\SeatAllianceTax\Models\AllianceTaxSetting::get('tax_collection_corporation_id');
        $taxCorp = $taxCorpId ? \Seat\Eveapi\Models\Corporation\CorporationInfo::where('corporation_id', $taxCorpId)->first() : null;

        // === TAX ESTIMATE for current uninvoiced period ===
        $taxEstimate = $this->calculateTaxEstimate($characterIds);

        return view('alliancetax::mytax.index', compact(
            'pendingTaxes',
            'paidTaxes',
            'totalPending',
            'totalPaid',
            'totalBalance',
            'taxCorp',
            'recentActivity',
            'miningSummary',
            'taxEstimate'
        ));
    }

    /**
     * Display detailed tax information for a specific character
     *
     * @param int $characterId
     * @return \Illuminate\View\View
     */
    public function character($characterId)
    {
        $user = auth()->user();
        
        // Verify user owns this character
        if (!$user->characters->contains('character_id', $characterId)) {
            abort(403, 'This is not your character');
        }

        // Get all taxes for this character
        $taxes = AllianceTaxCalculation::where('character_id', $characterId)
            ->orderBy('tax_period', 'desc')
            ->paginate(20);

        // Get mining activity
        $miningActivity = AllianceMiningActivity::where('character_id', $characterId)
            ->orderBy('mining_date', 'desc')
            ->paginate(50);

        return view('alliancetax::mytax.character', compact(
            'characterId',
            'taxes',
            'miningActivity'
        ));
    }
    
    /**
     * Show detailed tax breakdown
     */
    public function taxDetails($taxId)
    {
        $user = auth()->user();
        $characterIds = $user->characters->pluck('character_id');
        
        $tax = AllianceTaxCalculation::with('character')
            ->whereIn('character_id', $characterIds)
            ->findOrFail($taxId);
        
        // Get all characters for the user who owns this tax record
        $ownerUserId = DB::table('refresh_tokens')->where('character_id', $tax->character_id)->value('user_id');
        $allOwnerCharacterIds = DB::table('refresh_tokens')->where('user_id', $ownerUserId)->pluck('character_id');

        // Get mining activity for all characters of this user for this tax period
        $miningActivity = AllianceMiningActivity::whereIn('character_id', $allOwnerCharacterIds)
            ->whereBetween('mining_date', [$tax->period_start, $tax->period_end])
            ->with('character')
            ->orderBy('mining_date', 'desc')
            ->get();
        
        // Filter out activities that shouldn't be taxed (like WH gas)
        $miningActivity = $miningActivity->filter(function($activity) {
            $category = \Rejected\SeatAllianceTax\Helpers\OreCategory::getCategoryForTypeId($activity->type_id);
            
            // Skip Gas mined in Wormholes (solar_system_id starting with 31)
            if ($category === 'gas' && $activity->solar_system_id >= 31000000 && $activity->solar_system_id < 32000000) {
                return false;
            }
            
            // Also apply system restrictions if they exist
            $taxedSystemIds = \Rejected\SeatAllianceTax\Models\AllianceTaxSystem::pluck('solar_system_id')->toArray();
            if (!empty($taxedSystemIds) && !in_array($activity->solar_system_id, $taxedSystemIds)) {
                return false;
            }

            return true;
        });
        
        // Load all active tax rates
        $allRates = \Rejected\SeatAllianceTax\Models\AllianceTaxRate::where('is_active', true)->get();
        $defaultRate = \Rejected\SeatAllianceTax\Models\AllianceTaxSetting::get('default_tax_rate', 10);
        $allianceId = \Rejected\SeatAllianceTax\Models\AllianceTaxSetting::get('alliance_id');

        // Add tax rate to each activity
        $miningActivity = $miningActivity->map(function($activity) use ($allRates, $defaultRate, $allianceId) {
            // Determine ore category from type_id
            $category = \Rejected\SeatAllianceTax\Helpers\OreCategory::getCategoryForTypeId($activity->type_id);
            $activity->ore_category = $category;
            
            // Mirroring Service logic: Corp Category > Corp All > Alliance Category > Alliance All > Default
            
            // 1. Corp Category specific
            $rate = $allRates->where('corporation_id', $activity->corporation_id)->where('item_category', $category)->first();
            if ($rate) {
                $activity->tax_rate = $rate->tax_rate;
                return $activity;
            }

            // 2. Corp All
            $rate = $allRates->where('corporation_id', $activity->corporation_id)->where('item_category', 'all')->first();
            if ($rate) {
                $activity->tax_rate = $rate->tax_rate;
                return $activity;
            }

            // 3. Alliance Category specific
            $rate = $allRates->where('alliance_id', $allianceId)->where('item_category', $category)->first();
            if ($rate) {
                $activity->tax_rate = $rate->tax_rate;
                return $activity;
            }

            // 4. Alliance All
            $rate = $allRates->where('alliance_id', $allianceId)->where('item_category', 'all')->first();
            if ($rate) {
                $activity->tax_rate = $rate->tax_rate;
                return $activity;
            }

            // 5. Default
            $activity->tax_rate = $defaultRate;
            return $activity;
        });
        
        return view('alliancetax::mytax.details', compact('tax', 'miningActivity'));
    }

    /**
     * Calculate an estimated tax for the current uninvoiced period.
     * This looks at mining activity that hasn't been invoiced yet and
     * applies the current tax rates to give players a preview.
     *
     * @param \Illuminate\Support\Collection $characterIds
     * @return array
     */
    protected function calculateTaxEstimate($characterIds)
    {
        $periodType = AllianceTaxSetting::get('tax_period', 'weekly');
        $allianceId = AllianceTaxSetting::get('alliance_id');

        // Determine the current period boundaries
        $now = Carbon::now();
        if ($periodType === 'monthly') {
            $periodStart = $now->copy()->startOfMonth();
            $periodEnd = $now->copy()->endOfMonth();
            $periodLabel = $periodStart->format('F Y');
        } else {
            // Weekly — current week (Monday to Sunday)
            $periodStart = $now->copy()->startOfWeek(Carbon::MONDAY);
            $periodEnd = $now->copy()->endOfWeek(Carbon::SUNDAY);
            $periodLabel = $periodStart->format('M d') . ' - ' . $periodEnd->format('M d, Y');
        }

        // Check if this period has already been invoiced for the user
        $alreadyInvoiced = AllianceTaxCalculation::whereIn('character_id', $characterIds)
            ->where('tax_period', $periodStart->format('Y-m-d'))
            ->whereIn('status', ['sent', 'paid'])
            ->exists();

        if ($alreadyInvoiced) {
            return [
                'has_estimate' => false,
                'reason' => 'already_invoiced',
                'period_label' => $periodLabel,
            ];
        }

        // Get mining activity for the current period across all user characters
        $activities = AllianceMiningActivity::whereIn('character_id', $characterIds)
            ->whereBetween('mining_date', [$periodStart, $periodEnd])
            ->with('character')
            ->get();

        // Apply system restrictions
        $taxedSystemIds = AllianceTaxSystem::pluck('solar_system_id')->toArray();
        if (!empty($taxedSystemIds)) {
            $activities = $activities->filter(function ($activity) use ($taxedSystemIds) {
                return in_array($activity->solar_system_id, $taxedSystemIds);
            });
        }

        if ($activities->isEmpty()) {
            return [
                'has_estimate' => false,
                'reason' => 'no_activity',
                'period_label' => $periodLabel,
            ];
        }

        // Load all active tax rates once
        $allRates = AllianceTaxRate::where('is_active', true)->get();
        $defaultRate = AllianceTaxSetting::get('default_tax_rate', 10);

        $totalMinedValue = 0;
        $totalEstimatedTax = 0;
        $characterBreakdown = [];

        foreach ($activities as $activity) {
            $category = OreCategory::getCategoryForTypeId($activity->type_id);

            // Skip Gas mined in Wormholes
            if ($category === 'gas' && $activity->solar_system_id >= 31000000 && $activity->solar_system_id < 32000000) {
                continue;
            }

            // Determine tax rate using the same hierarchy as TaxCalculationService
            $taxRate = $this->getEstimateTaxRate($allRates, $activity->corporation_id, $allianceId, $category, $defaultRate);

            $activityValue = (float) $activity->estimated_value;
            $activityTax = $activityValue * ($taxRate / 100);

            $totalMinedValue += $activityValue;
            $totalEstimatedTax += $activityTax;

            // Build per-character breakdown
            $charId = $activity->character_id;
            if (!isset($characterBreakdown[$charId])) {
                $characterBreakdown[$charId] = [
                    'character_id' => $charId,
                    'character_name' => optional($activity->character)->name ?? 'Character ' . $charId,
                    'mined_value' => 0,
                    'estimated_tax' => 0,
                    'sessions' => 0,
                ];
            }

            $characterBreakdown[$charId]['mined_value'] += $activityValue;
            $characterBreakdown[$charId]['estimated_tax'] += $activityTax;
            $characterBreakdown[$charId]['sessions']++;
        }

        // Apply credit balance
        $totalCredit = \Rejected\SeatAllianceTax\Models\AllianceTaxBalance::whereIn('character_id', $characterIds)
            ->sum('balance');
        $creditApplicable = min((float) $totalCredit, $totalEstimatedTax);
        $netEstimatedTax = $totalEstimatedTax - $creditApplicable;

        return [
            'has_estimate' => true,
            'period_label' => $periodLabel,
            'period_type' => $periodType,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'total_mined_value' => $totalMinedValue,
            'total_estimated_tax' => $totalEstimatedTax,
            'credit_applicable' => $creditApplicable,
            'net_estimated_tax' => $netEstimatedTax,
            'character_breakdown' => array_values($characterBreakdown),
        ];
    }

    /**
     * Get the applicable tax rate for an activity, mirroring TaxCalculationService logic.
     * Hierarchy: Corp Category > Corp All > Alliance Category > Alliance All > Default
     */
    protected function getEstimateTaxRate($allRates, $corporationId, $allianceId, $category, $defaultRate)
    {
        $now = Carbon::now();

        // 1. Corp + Category specific
        $rate = $allRates->where('corporation_id', $corporationId)
            ->where('item_category', $category)
            ->filter(function ($r) use ($now) {
                return $r->effective_from <= $now && (!$r->effective_until || $r->effective_until >= $now);
            })->first();
        if ($rate) return $rate->tax_rate;

        // 2. Corp + All
        $rate = $allRates->where('corporation_id', $corporationId)
            ->where('item_category', 'all')
            ->filter(function ($r) use ($now) {
                return $r->effective_from <= $now && (!$r->effective_until || $r->effective_until >= $now);
            })->first();
        if ($rate) return $rate->tax_rate;

        // 3. Alliance + Category specific
        $rate = $allRates->where('alliance_id', $allianceId)
            ->where('item_category', $category)
            ->filter(function ($r) use ($now) {
                return $r->effective_from <= $now && (!$r->effective_until || $r->effective_until >= $now);
            })->first();
        if ($rate) return $rate->tax_rate;

        // 4. Alliance + All
        $rate = $allRates->where('alliance_id', $allianceId)
            ->where('item_category', 'all')
            ->filter(function ($r) use ($now) {
                return $r->effective_from <= $now && (!$r->effective_until || $r->effective_until >= $now);
            })->first();
        if ($rate) return $rate->tax_rate;

        // 5. Default
        return $defaultRate;
    }
}

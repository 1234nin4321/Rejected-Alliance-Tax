<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;
use Rejected\SeatAllianceTax\Models\AllianceMiningActivity;

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

        return view('alliancetax::mytax.index', compact(
            'pendingTaxes',
            'paidTaxes',
            'totalPending',
            'totalPaid',
            'totalBalance',
            'taxCorp',
            'recentActivity',
            'miningSummary'
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
}

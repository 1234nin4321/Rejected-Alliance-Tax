<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Routing\Controller;
use Rejected\SeatAllianceTax\Models\AllianceMiningActivity;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;
use Seat\Eveapi\Models\Character\CharacterInfo;

class CharacterTaxController extends Controller
{
    /**
     * Display mining tax information for a specific character.
     *
     * @param int $character_id
     * @return \Illuminate\View\View
     */
    public function show($character_id)
    {
        $character = CharacterInfo::findOrFail($character_id);

        // Get tax calculations
        $taxes = AllianceTaxCalculation::where('character_id', $character_id)
            ->orderBy('tax_period', 'desc')
            ->paginate(20);

        // Get recent mining activity
        $miningActivity = AllianceMiningActivity::where('character_id', $character_id)
            ->orderBy('mining_date', 'desc')
            ->paginate(50);

        return view('alliancetax::character.show', compact('character', 'taxes', 'miningActivity'));
    }

    /**
     * Display mining history for a specific character.
     *
     * @param int $character_id
     * @return \Illuminate\View\View
     */
    public function history($character_id)
    {
        $character = CharacterInfo::findOrFail($character_id);

        $history = AllianceMiningActivity::forCharacter($character_id)
            ->orderBy('mining_date', 'desc')
            ->paginate(50);

        return view('alliancetax::character.history', compact('character', 'history'));
    }

    /**
     * Get mining activity for the current tax period.
     *
     * @param int $character_id
     * @return \Illuminate\Support\Collection
     */
    private function getCurrentPeriodActivity($character_id)
    {
        $period = $this->getCurrentPeriod();

        return AllianceMiningActivity::forCharacter($character_id)
            ->period($period['start'], $period['end'])
            ->orderBy('mining_date', 'desc')
            ->get();
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

<?php

namespace Rejected\SeatAllianceTax\Services;

use Illuminate\Support\Facades\DB;
use Rejected\SeatAllianceTax\Models\AllianceCorpRattingTaxSetting;
use Rejected\SeatAllianceTax\Models\AllianceCorpRattingTaxCalculation;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Carbon\Carbon;

class CorpRattingTaxService
{
    /**
     * Ref Type variants for tax income in Corporate wallets
     * 96: Corporation Tax
     * 145: ESS Bounty Tax
     * We include strings for SeAT versions that store them as strings.
     */
    const CORP_TAX_INCOME_REF_TYPES = [
        96, 145, 
        'corporation_tax', 
        'ess_escrow_transfer', 
        'ess_payout', 
        'ess_bounty_tax'
    ];

    /**
     * Calculate ratting tax for a corporation for a given period
     * Based on how much tax the corp actually collected in its wallet
     * 
     * @param int $corporationId
     * @param string $start
     * @param string $end
     * @return AllianceCorpRattingTaxCalculation|null
     */
    public function calculateForCorp($corporationId, $start, $end)
    {
        $setting = AllianceCorpRattingTaxSetting::where('corporation_id', $corporationId)
            ->where('is_active', true)
            ->first();

        if (!$setting || $setting->tax_rate <= 0) {
            return null;
        }

        $totalCorpTaxIncome = $this->getCorpTaxIncome($corporationId, $start, $end);

        \Log::debug('Scanned Corp Tax: ' . $corporationId . ' income detected: ' . $totalCorpTaxIncome);

        // check against minimum threshold
        if ($totalCorpTaxIncome < ($setting->min_threshold ?? 0)) {
            \Log::debug("Corp {$corporationId} income {$totalCorpTaxIncome} is below threshold {$setting->min_threshold}. Skipping.");
            return null;
        }

        if ($totalCorpTaxIncome <= 0) {
            return null;
        }

        // The tax is a percentage OF the tax the corp collected
        $taxAmount = ($totalCorpTaxIncome * $setting->tax_rate) / 100;

        return AllianceCorpRattingTaxCalculation::create([
            'corporation_id' => $corporationId,
            'total_bounty_value' => $totalCorpTaxIncome, // Rebasing this column to mean "Collected Tax"
            'tax_rate' => $setting->tax_rate,
            'tax_amount' => $taxAmount,
            'period_start' => $start,
            'period_end' => $end,
            'status' => 'pending',
        ]);
    }

    /**
     * Get sum of all tax entries in a corporation's wallet
     * 
     * @param int $corporationId
     * @param string $start
     * @param string $end
     * @return float
     */
    public function getCorpTaxIncome($corporationId, $start, $end)
    {
        $totalIncome = 0;
        $foundInCorp = 0;
        $foundInMembers = 0;

        // 1. Try dedicated corporation wallet journals (the most accurate source)
        if (\Illuminate\Support\Facades\Schema::hasTable('corporation_wallet_journals')) {
            $column = $this->getRefTypeColumn('corporation_wallet_journals');
            
            $foundInCorp = (float) DB::table('corporation_wallet_journals')
                ->where('corporation_id', $corporationId)
                ->whereIn($column, self::CORP_TAX_INCOME_REF_TYPES)
                ->whereBetween('date', [$start . ' 00:00:00', $end . ' 23:59:59'])
                ->sum('amount');

            $totalIncome += $foundInCorp;
        }

        // 2. Always supplement with member wallets if data is missing or to be thorough
        // In character journals, Type 96 is a NEGATIVE amount (the tax leaving their wallet).
        // We take the absolute value to represent this as income to the corporation.
        if (\Illuminate\Support\Facades\Schema::hasTable('wallet_journals')) {
            $column = $this->getRefTypeColumn('wallet_journals');
            
            $memberSum = (float) DB::table('wallet_journals')
                ->join('character_infos', 'wallet_journals.character_id', '=', 'character_infos.character_id')
                ->where('character_infos.corporation_id', $corporationId)
                ->whereIn("wallet_journals.{$column}", self::CORP_TAX_INCOME_REF_TYPES)
                ->whereBetween('wallet_journals.date', [$start . ' 00:00:00', $end . ' 23:59:59'])
                ->sum('wallet_journals.amount');

            $foundInMembers = abs($memberSum);
            
            // Only add if we don't have corp-level data, or if member data is higher 
            // (prevents double counting if both are synced, but ensures we get the most data)
            if ($foundInMembers > $totalIncome) {
                $totalIncome = $foundInMembers;
            }
        }

        \Log::debug("Income Check for Corp {$corporationId}: CorpTable: {$foundInCorp} | MemberTable: {$foundInMembers} | Final: {$totalIncome}");

        return $totalIncome;
    }

    /**
     * Get all corporations that have some tax income in a period
     * 
     * @param string $start
     * @param string $end
     * @return \Illuminate\Support\Collection
     */
    public function getActiveCorps($start, $end)
    {
        if (\Illuminate\Support\Facades\Schema::hasTable('corporation_wallet_journals')) {
            $column = $this->getRefTypeColumn('corporation_wallet_journals');
            
            return DB::table('corporation_wallet_journals')
                ->whereIn($column, self::CORP_TAX_INCOME_REF_TYPES)
                ->whereBetween('date', [$start . ' 00:00:00', $end . ' 23:59:59'])
                ->select('corporation_id')
                ->distinct()
                ->pluck('corporation_id');
        }

        $column = $this->getRefTypeColumn('wallet_journals');

        return DB::table('wallet_journals')
            ->join('character_infos', 'wallet_journals.character_id', '=', 'character_infos.character_id')
            ->whereIn("wallet_journals.{$column}", self::CORP_TAX_INCOME_REF_TYPES)
            ->whereBetween('wallet_journals.date', [$start . ' 00:00:00', $end . ' 23:59:59'])
            ->select('character_infos.corporation_id')
            ->distinct()
            ->pluck('character_infos.corporation_id');
    }

    /**
     * Helper to detect if we should use ref_type or ref_type_id
     * SeAT versions and ESI schemas vary.
     */
    protected function getRefTypeColumn($table)
    {
        if (\Illuminate\Support\Facades\Schema::hasColumn($table, 'ref_type')) {
            return 'ref_type';
        }

        return 'ref_type_id';
    }
}

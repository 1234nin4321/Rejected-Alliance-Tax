<?php

namespace Rejected\SeatAllianceTax\Console\Commands;

use Illuminate\Console\Command;
use Rejected\SeatAllianceTax\Models\AllianceCorpRattingTaxSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DiagnoseCorpTax extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alliancetax:diagnose-corp {corporation_id : The ID of the corporation to inspect} {start? : Optional YYYY-MM-DD} {end? : Optional YYYY-MM-DD}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deep scan a corporation\'s tax data to debug missing invoices';

    public function handle()
    {
        $corpId = $this->argument('corporation_id');
        $start = $this->argument('start') ?? now()->startOfMonth()->format('Y-m-d');
        $end = $this->argument('end') ?? now()->format('Y-m-d');

        $this->info("Starting diagnosis for Corp ID: $corpId");
        $this->info("Period: $start to $end");

        // 1. Check Settings
        $setting = AllianceCorpRattingTaxSetting::where('corporation_id', $corpId)->first();
        if (!$setting) {
            $this->error("CRITICAL: No Tax Settings found for this corporation!");
            $this->warn("You must add this corporation in the 'Tax Structure Configuration' box first.");
            return;
        }

        $this->line("Settings Found: Rate={$setting->tax_rate}%, Active=" . ($setting->is_active ? 'YES' : 'NO') . ", MinThreshold=" . ($setting->min_threshold ?? '0'));
        if (!$setting->is_active) {
            $this->alert("WARNING: Corporation is marked INACTIVE. Generation will skip it.");
        }

        // 2. Scan Official Corp Wallet
        $this->info("\n--- Scanning Official Corporate Wallet ---");
        if (Schema::hasTable('corporation_wallet_journals')) {
            $refCol = $this->getRefColumn('corporation_wallet_journals');
            $this->line("Using column: $refCol");
            
            $count = DB::table('corporation_wallet_journals')
                ->where('corporation_id', $corpId)
                ->whereBetween('date', [$start . ' 00:00:00', $end . ' 23:59:59'])
                ->count();
            
            $this->line("Total entries in this period: $count");

            $taxRefs = [96, 145, 'corporation_tax', 'ess_escrow_transfer', 'ess_payout', 'ess_bounty_tax'];
            $taxSum = DB::table('corporation_wallet_journals')
                ->where('corporation_id', $corpId)
                ->whereIn($refCol, $taxRefs)
                ->whereBetween('date', [$start . ' 00:00:00', $end . ' 23:59:59'])
                ->sum('amount');
            
            $this->info("Found Official Tax Income: " . number_format($taxSum, 2) . " ISK");

            $this->line("\n--- Ref Type Breakdown (Top 10 by Volume) ---");
            $breakdown = DB::table('corporation_wallet_journals')
                ->where('corporation_id', $corpId)
                ->whereBetween('date', [$start . ' 00:00:00', $end . ' 23:59:59'])
                ->select($refCol, DB::raw('count(*) as count'), DB::raw('sum(amount) as total'))
                ->groupBy($refCol)
                ->orderBy('total', 'desc')
                ->limit(10)
                ->get();
            
            foreach ($breakdown as $row) {
                $typeName = $row->$refCol;
                $this->line("Ref: $typeName | Count: {$row->count} | Total: " . number_format($row->total, 2));
            }

        } else {
            $this->warn("Table 'corporation_wallet_journals' does not exist.");
        }

        // 3. Scan Member Wallets
        $this->info("\n--- Scanning Member Wallets (Fallback) ---");
        if (Schema::hasTable('wallet_journals')) {
            $refCol = $this->getRefColumn('wallet_journals');
            $this->line("Using column: $refCol");

            $query = DB::table('wallet_journals')
                ->join('character_infos', 'wallet_journals.character_id', '=', 'character_infos.character_id')
                ->where('character_infos.corporation_id', $corpId)
                ->whereBetween('wallet_journals.date', [$start . ' 00:00:00', $end . ' 23:59:59']);
            
            $totalEntries = $query->count();
            $this->line("Total member entries in this period: $totalEntries");
            
            // Checking specifically for tax deduction (negative amounts)
            $taxRefs = [96, 145, 'corporation_tax', 'ess_escrow_transfer', 'ess_payout', 'ess_bounty_tax'];
            
            $taxSum = DB::table('wallet_journals')
                ->join('character_infos', 'wallet_journals.character_id', '=', 'character_infos.character_id')
                ->where('character_infos.corporation_id', $corpId)
                ->whereIn("wallet_journals.{$refCol}", $taxRefs)
                ->whereBetween('wallet_journals.date', [$start . ' 00:00:00', $end . ' 23:59:59'])
                ->sum('wallet_journals.amount');
            
            $absSum = abs($taxSum);
            $this->info("Found Member Tax Contributions: " . number_format($absSum, 2) . " ISK (Raw Sum: $taxSum)");
            
            if ($absSum > 0) {
                 $this->line("This confirms members are ratting. If 'Official Tax' was 0, the invoice should use this amount.");
            }
        } else {
            $this->warn("Table 'wallet_journals' does not exist.");
        }
        
        // 4. Threshold check
        $finalIncome = max($taxSum ?? 0, abs($taxSum ?? 0));
        if ($finalIncome < ($setting->min_threshold ?? 0)) {
             $this->error("\nRESULT: SKIP. Total found ($finalIncome) is less than Min Threshold ({$setting->min_threshold}).");
        } else {
             $this->info("\nRESULT: GENERATE. Total found ($finalIncome) exceeds Min Threshold ({$setting->min_threshold}).");
        }
    }

    protected function getRefColumn($table)
    {
         return Schema::hasColumn($table, 'ref_type') ? 'ref_type' : 'ref_type_id';
    }
}

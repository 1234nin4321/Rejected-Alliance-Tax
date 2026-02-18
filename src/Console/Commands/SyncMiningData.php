<?php

namespace Rejected\SeatAllianceTax\Console\Commands;

use Illuminate\Console\Command;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;

class SyncMiningData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alliancetax:sync-mining';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync mining ledger data for the current period to update tax estimates';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $allianceId = AllianceTaxSetting::get('alliance_id');
        
        if (!$allianceId) {
            $this->error('No alliance ID configured!');
            return 1;
        }

        // Determine current period (weekly or monthly)
        $periodType = AllianceTaxSetting::get('tax_period', 'weekly');
        $now = Carbon::now();

        if ($periodType === 'monthly') {
            $periodStart = $now->copy()->startOfMonth();
            $periodEnd = $now->copy()->endOfMonth();
        } else {
            $periodStart = $now->copy()->startOfWeek();
            $periodEnd = $now->copy()->endOfWeek();
        }

        $this->info("Syncing mining data for active period: {$periodStart->format('Y-m-d')} to {$periodEnd->format('Y-m-d')}");

        $service = new \Rejected\SeatAllianceTax\Services\TaxCalculationService();
        $imported = $service->importMiningData($allianceId, $periodStart, $periodEnd, $this->output);

        $this->info("Synced {$imported} new mining records.");

        return 0;
    }
}

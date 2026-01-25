<?php

namespace Rejected\SeatAllianceTax\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceMiningActivity;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;
use Rejected\SeatAllianceTax\Models\AllianceTaxRate;
use Rejected\SeatAllianceTax\Models\AllianceTaxExemption;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;

class CalculateAllianceTax extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alliancetax:calculate 
                            {--period= : Tax period (YYYY-MM-DD), defaults to last week}
                            {--alliance= : Alliance ID, defaults to configured alliance}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Calculate alliance mining taxes from ledger data';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting alliance tax calculation...');

        $allianceId = $this->option('alliance') ?? AllianceTaxSetting::get('alliance_id');
        
        if (!$allianceId) {
            $this->error('No alliance ID configured!');
            return 1;
        }

        $taxPeriod = $this->option('period') 
            ? Carbon::parse($this->option('period'))
            : Carbon::now()->subWeek()->startOfWeek();

        $periodStart = $taxPeriod->copy()->startOfWeek();
        $periodEnd = $taxPeriod->copy()->endOfWeek();

        $this->info("Alliance: {$allianceId} | Period: {$periodStart->format('Y-m-d')} to {$periodEnd->format('Y-m-d')}");

        $service = new \Rejected\SeatAllianceTax\Services\TaxCalculationService();
        
        $this->info('Importing mining data...');
        $imported = $service->importMiningData($allianceId, $periodStart, $periodEnd, $this->output);
        $this->newLine();
        $this->info("Imported {$imported} records.");

        $this->info('Calculating taxes...');
        $calculated = $service->calculateTaxes($allianceId, $periodStart, $periodEnd, $this->output);
        $this->newLine();
        $this->info("Calculated taxes for {$calculated} characters.");

        $this->info('✅ Done!');
        return 0;
    }

    protected function isExempt($characterId, $corporationId, $date)
    {
        return AllianceTaxExemption::where(function($query) use ($characterId, $corporationId) {
                $query->where('character_id', $characterId)
                      ->orWhere('corporation_id', $corporationId);
            })
            ->where('is_active', true)
            ->where('exempt_from', '<=', $date)
            ->where(function($query) use ($date) {
                $query->whereNull('exempt_until')
                      ->orWhere('exempt_until', '>=', $date);
            })
            ->exists();
    }
}

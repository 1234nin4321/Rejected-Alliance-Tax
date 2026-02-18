<?php

namespace Rejected\SeatAllianceTax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceTaxSystem;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;

class DiagnoseMiningTax extends Command
{
    protected $signature = 'alliancetax:diagnose-mining {--days=7 : Number of days to look back}';
    protected $description = 'Check mining activity and verify system filtering logic';

    public function handle()
    {
        $days = (int) $this->option('days');
        $start = Carbon::now()->subDays($days);
        $end = Carbon::now();

        $this->info("Checking mining activity from {$start->toDateTimeString()} to {$end->toDateTimeString()}");

        // 1. Check Taxed Systems List
        $taxedSystems = AllianceTaxSystem::all();
        $taxedSystemIds = $taxedSystems->pluck('solar_system_id')->toArray();

        $this->info("\n1. Taxed Solar Systems Configuration:");
        if (count($taxedSystemIds) === 0) {
            $this->warn("   NO taxed systems configured. This means ALL systems are taxed (except WH gas).");
        } else {
            foreach ($taxedSystems as $sys) {
                $this->line("   - [{$sys->solar_system_id}] " . ($sys->solar_system_name ?? 'Unknown'));
            }
        }

        // 2. Sample Mining Data (Raw SeAT Data)
        $allianceId = AllianceTaxSetting::get('alliance_id');
        if (!$allianceId) {
            $this->error("No alliance ID configured.");
            return;
        }

        $allianceCharacters = DB::table('character_affiliations')
            ->where('alliance_id', $allianceId)
            ->pluck('character_id');
        
        $rawQuery = DB::table('character_minings')
            ->whereIn('character_id', $allianceCharacters)
            ->whereBetween('date', [$start, $end]);

        $totalRaw = $rawQuery->count();
        $this->info("\n2. Raw Mining Data (SeAT Ledger):");
        $this->line("   Total records found: {$totalRaw}");

        if ($totalRaw === 0) {
            return;
        }

        // Breakdown by system
        $systemBreakdown = $rawQuery->select('solar_system_id', DB::raw('count(*) as count'))
            ->groupBy('solar_system_id')
            ->get();

        $this->info("\n3. Breakdown by Solar System:");
        
        $keptCount = 0;
        $droppedCount = 0;

        foreach ($systemBreakdown as $row) {
            $sysId = $row->solar_system_id;
            
            // Check filtering logic
            $isKept = empty($taxedSystemIds) || in_array($sysId, $taxedSystemIds);
            
            $status = $isKept ? "✅ TAXED" : "❌ IGNORED";
            if ($isKept) $keptCount += $row->count;
            else $droppedCount += $row->count;

            $sysName = DB::table('mapSolarSystems')->where('solarSystemID', $sysId)->value('solarSystemName') ?? $sysId;

            $this->line("   System {$sysName} [{$sysId}]: {$row->count} records -> {$status}");
        }

        $this->info("\nSummary:");
        $this->info("   Taxable Records: {$keptCount}");
        $this->info("   Ignored Records: {$droppedCount}");

        if (!empty($taxedSystemIds) && $keptCount === 0 && $droppedCount > 0) {
            $this->warn("\n⚠️  WARNING: You have configured taxed systems, but NO mining activity occurred in them.");
            $this->warn("    This explains why tax estimates might be 0.");
        }
    }
}

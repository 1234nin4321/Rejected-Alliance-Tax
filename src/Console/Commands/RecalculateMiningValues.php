<?php

namespace Rejected\SeatAllianceTax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Rejected\SeatAllianceTax\Models\AllianceMiningActivity;
use Rejected\SeatAllianceTax\Services\JitaPriceService;
use Rejected\SeatAllianceTax\Services\CompressedOreMappingService;

class RecalculateMiningValues extends Command
{
    protected $signature = 'alliance-tax:recalculate-values {--days=30 : Number of days of history to recalculate}';
    
    protected $description = 'Recalculate estimated values for mining activity using current compressed ore prices';

    public function handle()
    {
        $days = $this->option('days');
        $cutoffDate = now()->subDays($days);
        
        $this->info("Recalculating mining values for last {$days} days...");
        $this->info("Cutoff date: {$cutoffDate->toDateString()}");
        $this->newLine();
        
        // Step 1: Clear price cache
        $this->info('Step 1: Clearing price cache...');
        Cache::flush();
        $this->info('✓ Cache cleared');
        $this->newLine();
        
        // Step 2: Get mining activities to recalculate
        $activities = AllianceMiningActivity::where('mining_date', '>=', $cutoffDate)->get();
        
        if ($activities->isEmpty()) {
            $this->warn('No mining activities found in the specified date range.');
            return 0;
        }
        
        $this->info("Step 2: Recalculating {$activities->count()} mining records...");
        $bar = $this->output->createProgressBar($activities->count());
        $bar->start();
        
        $updated = 0;
        $errors = 0;
        
        foreach ($activities as $activity) {
            try {
                // Get compressed variant
                $compressedTypeId = CompressedOreMappingService::getCompressedTypeId($activity->type_id);
                
                // Fetch full compressed market price
                $price = JitaPriceService::getSellPrice($compressedTypeId);
                
                // New estimated value (Quantity * Full Compressed Price)
                $newValue = $activity->quantity * $price;
                
                // Update database record
                $activity->estimated_value = $newValue;
                $activity->save();
                
                $updated++;
            } catch (\Exception $e) {
                $errors++;
                $this->error("\nError updating activity ID {$activity->id}: " . $e->getMessage());
            }
            
            $bar->advance();
        }
        
        $bar->finish();
        $this->newLine();
        $this->newLine();
        
        // Summary
        $this->info('═══════════════════════════════════════');
        $this->info('Recalculation Complete!');
        $this->info('═══════════════════════════════════════');
        $this->info("✓ Updated: {$updated} records");
        if ($errors > 0) {
            $this->warn("✗ Errors: {$errors} records");
        }
        $this->info("Date Range: {$cutoffDate->toDateString()} to " . now()->toDateString());
        $this->newLine();
        
        $this->comment('Note: Tax calculations are NOT automatically recalculated.');
        $this->comment('Run "php artisan alliancetax:calculate" to recalculate taxes for a period.');
        
        return 0;
    }
}

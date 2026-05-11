<?php

namespace Rejected\SeatAllianceTax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Rejected\SeatAllianceTax\Models\AllianceTaxInvoice;

class CleanupDuplicateInvoices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'alliancetax:cleanup-duplicates {--dry-run : Only show what would be deleted}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Find and remove duplicate invoices for the same character and period';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Searching for duplicate invoices...');

        $invoices = AllianceTaxInvoice::all();
        $grouped = [];
        $duplicates = [];

        foreach ($invoices as $invoice) {
            $metadata = json_decode($invoice->metadata, true);
            
            if (!$metadata || !isset($metadata['period_start']) || !isset($metadata['period_end'])) {
                continue;
            }

            $fingerprint = sprintf(
                '%d_%s_%s',
                $invoice->character_id,
                $metadata['period_start'],
                $metadata['period_end']
            );

            if (!isset($grouped[$fingerprint])) {
                $grouped[$fingerprint] = [];
            }

            $grouped[$fingerprint][] = $invoice;
        }

        $totalDeleted = 0;

        foreach ($grouped as $fingerprint => $group) {
            if (count($group) <= 1) {
                continue;
            }

            // Sort by ID to keep the oldest one
            usort($group, function ($a, $b) {
                return $a->id <=> $b->id;
            });

            $keep = array_shift($group);
            $this->info("Keeping Invoice #{$keep->id} for fingerprint {$fingerprint}");

            foreach ($group as $duplicate) {
                $this->warn("Found Duplicate: Invoice #{$duplicate->id} (Amount: {$duplicate->amount}, Created: {$duplicate->created_at})");
                
                if (!$this->option('dry-run')) {
                    // Delete using DB facade to avoid Eloquent events that might reset calculations to pending
                    DB::table('alliance_tax_invoices')->where('id', $duplicate->id)->delete();
                    $totalDeleted++;
                }
            }
        }

        if ($this->option('dry-run')) {
            $this->info("\nDry run complete. Found " . count($group) . " potential duplicates across " . count($grouped) . " groups.");
        } else {
            $this->info("\nCleanup complete. Deleted {$totalDeleted} duplicate invoices.");
        }

        return 0;
    }
}

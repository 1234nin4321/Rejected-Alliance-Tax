<?php

namespace Rejected\SeatAllianceTax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Rejected\SeatAllianceTax\Models\AllianceTaxInvoice;
use Rejected\SeatAllianceTax\Models\AllianceTaxBalance;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
use Seat\Eveapi\Models\Wallet\CorporationWalletJournal;

class RecalculateCredits extends Command
{
    protected $signature = 'alliancetax:recalculate-credits {--dry-run : Show what would change without saving}';
    protected $description = 'Recalculate tax credit balances from actual paid invoices and wallet transactions';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        
        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $taxCorpId = (int) AllianceTaxSetting::get('tax_collection_corporation_id');
        if (!$taxCorpId) {
            $this->error('No tax collection corporation configured!');
            return 1;
        }

        // Detect the ID column in wallet journal
        $journalTable = (new CorporationWalletJournal)->getTable();
        $columns = Schema::getColumnListing($journalTable);
        $idColumn = in_array('ref_id', $columns) ? 'ref_id' : 'id';
        
        $this->info("Wallet journal table: {$journalTable}, ID column: {$idColumn}");

        // Get all paid invoices that have a payment_ref_id
        $paidInvoices = AllianceTaxInvoice::where('status', 'paid')
            ->whereNotNull('payment_ref_id')
            ->get();

        $this->info("Found {$paidInvoices->count()} paid invoices with transaction references.");

        // Reset all balances first
        if (!$dryRun) {
            AllianceTaxBalance::query()->update(['balance' => 0]);
        }
        $this->info("Reset all credit balances to 0.");

        $creditsByCharacter = [];
        $totalCredits = 0;

        foreach ($paidInvoices as $invoice) {
            $metadata = $invoice->metadata ? json_decode($invoice->metadata, true) : [];
            
            // Get the original invoice amount
            // If partial payments were applied, we need the original amount
            // Check metadata for applied_payments to reconstruct original
            $appliedPayments = $metadata['applied_payments'] ?? [];
            
            // The current invoice->amount might have been reduced by partial payments
            // Original amount = current amount + sum of all applied partial payments
            $partialTotal = 0;
            foreach ($appliedPayments as $p) {
                $partialTotal += (float) ($p['amount'] ?? 0);
            }
            $originalAmount = (float) $invoice->amount + $partialTotal;

            // Look up the actual wallet transaction
            $tx = DB::table($journalTable)
                ->where('corporation_id', $taxCorpId)
                ->where($idColumn, $invoice->payment_ref_id)
                ->first();

            if (!$tx) {
                $this->line("  ⚠ Invoice #{$invoice->id} (char {$invoice->character_id}): transaction {$invoice->payment_ref_id} not found in journal — skipping");
                continue;
            }

            $txAmount = (float) $tx->amount;
            
            // Calculate total paid across ALL transactions for this invoice
            $totalPaidForInvoice = $txAmount;
            foreach ($appliedPayments as $p) {
                // Don't double-count the final payment (which is payment_ref_id)
                if (($p['tx_id'] ?? '') != $invoice->payment_ref_id) {
                    // Look up partial payment transaction amount
                    $partialTx = DB::table($journalTable)
                        ->where('corporation_id', $taxCorpId)
                        ->where($idColumn, $p['tx_id'] ?? '')
                        ->first();
                    if ($partialTx) {
                        $totalPaidForInvoice += (float) $partialTx->amount;
                    }
                }
            }

            $overpaid = $totalPaidForInvoice - $originalAmount;
            
            if ($overpaid > 1) { // More than 1 ISK overpayment (ignore rounding noise)
                $charId = $invoice->character_id;
                $creditsByCharacter[$charId] = ($creditsByCharacter[$charId] ?? 0) + $overpaid;
                $totalCredits += $overpaid;
                
                $charName = optional($invoice->character)->name ?? $charId;
                $this->info("  ✅ {$charName}: paid " . number_format($totalPaidForInvoice, 0) 
                    . " for invoice of " . number_format($originalAmount, 0) 
                    . " → credit: " . number_format($overpaid, 0) . " ISK");
            }
        }

        // Apply credits
        if (!$dryRun) {
            foreach ($creditsByCharacter as $charId => $credit) {
                $balance = AllianceTaxBalance::firstOrCreate(['character_id' => $charId]);
                $balance->balance = floor($credit);
                $balance->save();
            }
        }

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Characters with credit: " . count($creditsByCharacter));
        $this->info("  Total credit: " . number_format($totalCredits, 0) . " ISK");
        
        if ($dryRun) {
            $this->warn("No changes saved (dry run). Run without --dry-run to apply.");
        } else {
            $this->info("Credits applied successfully.");
        }

        return 0;
    }
}

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
    protected $description = 'Recalculate tax credit balances by comparing total ISK sent vs total invoiced per user';

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

        $this->info("Tax Collection Corp: {$taxCorpId}");

        // Detect ref_type column
        $journalTable = (new CorporationWalletJournal)->getTable();
        $columns = Schema::getColumnListing($journalTable);
        $refTypeColumn = in_array('ref_type', $columns) ? 'ref_type' : null;

        // Get all invoices grouped by user
        // First, find all unique character_ids that have invoices
        $invoiceCharacters = AllianceTaxInvoice::select('character_id')
            ->distinct()
            ->pluck('character_id');

        $this->info("Found invoices for " . $invoiceCharacters->count() . " characters.");

        $creditsByCharacter = [];
        $totalCreditsApplied = 0;

        foreach ($invoiceCharacters as $charId) {
            // Find the SeAT user who owns this character
            $userId = DB::table('refresh_tokens')->where('character_id', $charId)->value('user_id');
            
            if (!$userId) {
                $this->line("  ⚠ Character {$charId}: no user found, skipping");
                continue;
            }

            // Get ALL characters for this user
            $userCharIds = DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->pluck('character_id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            // Skip if we already processed this user (via another character)
            $userKey = 'user_' . $userId;
            if (isset($creditsByCharacter[$userKey])) {
                continue;
            }

            // Get the main character for display
            $mainCharId = DB::table('users')->where('id', $userId)->value('main_character_id') ?? $charId;
            $charName = DB::table('character_infos')->where('character_id', $mainCharId)->value('name') ?? "Character {$mainCharId}";

            // 1. Calculate TOTAL invoiced for this user (original amounts)
            //    We use ALL invoices regardless of status
            $invoices = AllianceTaxInvoice::whereIn('character_id', $userCharIds)->get();
            
            $totalInvoiced = 0;
            foreach ($invoices as $invoice) {
                $metadata = $invoice->metadata ? json_decode($invoice->metadata, true) : [];
                $appliedPayments = $metadata['applied_payments'] ?? [];
                
                // Reconstruct original invoice amount
                // For partial/paid invoices, the current amount might have been reduced
                $partialTotal = 0;
                foreach ($appliedPayments as $p) {
                    $partialTotal += (float)($p['amount'] ?? 0);
                }
                
                // Original amount = current remaining + all partial payments applied
                $originalAmount = (float)$invoice->amount + $partialTotal;
                $totalInvoiced += $originalAmount;
            }

            // 2. Calculate TOTAL ISK sent by this user's characters to the tax corp
            $txQuery = DB::table($journalTable)
                ->where('corporation_id', $taxCorpId)
                ->where('amount', '>', 0)
                ->where(function ($q) use ($userCharIds) {
                    $q->whereIn('first_party_id', $userCharIds)
                      ->orWhereIn('second_party_id', $userCharIds);
                });

            // Filter to player donation types if possible
            if ($refTypeColumn) {
                $txQuery->whereIn($refTypeColumn, [
                    'player_donation',
                    'corporation_account_withdrawal',
                    'direct_transfer',
                    'cash_out',
                    'deposit',
                    'union_payment',
                ]);
            }

            $totalSent = (float) $txQuery->sum('amount');

            // 3. Credit = Total Sent - Total Invoiced
            $credit = $totalSent - $totalInvoiced;

            $this->line(
                "  {$charName}: Sent " . number_format($totalSent, 0) . 
                " ISK, Invoiced " . number_format($totalInvoiced, 0) . 
                " ISK → " . ($credit > 0 ? "Credit: " . number_format($credit, 0) . " ISK" : "No credit")
            );

            if ($credit > 1) { // More than 1 ISK to avoid rounding noise
                $creditsByCharacter[$userKey] = [
                    'character_id' => $mainCharId,
                    'character_name' => $charName,
                    'credit' => floor($credit),
                ];
                $totalCreditsApplied += floor($credit);
            } else {
                $creditsByCharacter[$userKey] = [
                    'character_id' => $mainCharId,
                    'character_name' => $charName,
                    'credit' => 0,
                ];
            }
        }

        // Reset and apply
        if (!$dryRun) {
            AllianceTaxBalance::query()->update(['balance' => 0]);
            
            foreach ($creditsByCharacter as $data) {
                if ($data['credit'] > 0) {
                    $balance = AllianceTaxBalance::firstOrCreate(['character_id' => $data['character_id']]);
                    $balance->balance = $data['credit'];
                    $balance->save();
                }
            }
        }

        $usersWithCredit = collect($creditsByCharacter)->filter(fn($d) => $d['credit'] > 0);

        $this->newLine();
        $this->info("Summary:");
        $this->info("  Users with credit: " . $usersWithCredit->count());
        $this->info("  Total credit: " . number_format($totalCreditsApplied, 0) . " ISK");

        if ($usersWithCredit->isNotEmpty()) {
            $this->newLine();
            $this->info("Credit Breakdown:");
            foreach ($usersWithCredit as $data) {
                $this->info("  ✅ {$data['character_name']}: " . number_format($data['credit'], 0) . " ISK");
            }
        }
        
        if ($dryRun) {
            $this->warn("\nNo changes saved (dry run). Run without --dry-run to apply.");
        } else {
            $this->info("\n✅ Credits applied successfully.");
        }

        return 0;
    }
}

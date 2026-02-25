<?php

namespace Rejected\SeatAllianceTax\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceTaxInvoice;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
use Rejected\SeatAllianceTax\Services\CreditRecalculationService;
use Seat\Eveapi\Models\Wallet\CorporationWalletJournal;

class ReconcilePayments extends Command
{
    protected $signature = 'alliancetax:reconcile 
                            {--days=30 : Number of days to look back for payments}';

    protected $description = 'Reconcile tax payments from corporation wallet journal';

    public function handle()
    {
        $this->info('Starting payment reconciliation...');

        $taxCorpId = AllianceTaxSetting::get('tax_collection_corporation_id');
        
        if (!$taxCorpId) {
            $this->error('Tax collection corporation not configured! Set it in Settings.');
            return 1;
        }

        // Ensure corporation_id is compared as an integer to avoid type mismatch
        $taxCorpId = (int) $taxCorpId;

        $this->info("Tax Collection Corp: {$taxCorpId}");

        $daysBack = $this->option('days');
        $since = Carbon::now()->subDays($daysBack);

        // Get unpaid invoices
        $unpaidInvoices = AllianceTaxInvoice::where('status', '!=', 'paid')
            ->with('character')
            ->get();

        if ($unpaidInvoices->isEmpty()) {
            $this->info('No unpaid invoices to reconcile.');
            return 0;
        }

        $this->info("Found {$unpaidInvoices->count()} unpaid invoices");

        // Determine the correct ref_type column name (SeAT version compatibility)
        $refTypeColumn = $this->getRefTypeColumn();

        // Get wallet journal entries (player donations/transfers)
        $query = CorporationWalletJournal::where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->where('amount', '>', 0); // Incoming only

        if ($refTypeColumn) {
            $query->whereIn($refTypeColumn, [
                'player_donation', 
                'corporation_account_withdrawal', 
                'direct_transfer', 
                'cash_out',
                'deposit',
                'union_payment',
            ]);
        }

        $transactions = $query->orderBy('date', 'desc')->get();

        $this->info("Checking {$transactions->count()} wallet transactions since {$since->toDateTimeString()}...");
        
        if ($transactions->isNotEmpty()) {
            $this->info("Recent recorded transactions:");
            foreach ($transactions->take(5) as $tx) {
                $this->line("  - [{$tx->date}] Ref: {$tx->ref_id} | Type: {$tx->ref_type} | Amount: " . number_format($tx->amount, 2) . " | Party1: {$tx->first_party_id} | Party2: {$tx->second_party_id}");
            }
        }

        $matched = 0;
        $bar = $this->output->createProgressBar($unpaidInvoices->count());
        $bar->start();

        // Load all already-used transaction ref_ids ONCE, then track in-memory
        // This prevents the same transaction being matched to multiple invoices
        $usedRefIds = AllianceTaxInvoice::whereNotNull('payment_ref_id')
            ->pluck('payment_ref_id')
            ->toArray();

        foreach ($unpaidInvoices as $invoice) {
            // Look for matching transaction
            $payment = $this->findMatchingPayment($invoice, $transactions, $usedRefIds);

            if ($payment) {
                // Immediately claim this ref_id so no other invoice can use it
                $usedRefIds[] = $payment->ref_id;

                $this->markInvoiceAsPaid($invoice, $payment);
                $matched++;
                $this->newLine();
                $characterName = $invoice->character ? $invoice->character->name : $invoice->character_id;
                $this->info("✓ Matched payment for {$characterName}: " . number_format($invoice->amount, 2) . " ISK (ref_id: {$payment->ref_id})");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Reconciliation complete! Matched {$matched} payments.");

        // Recalculate all credit balances from source of truth
        $this->info('Recalculating credit balances...');
        try {
            CreditRecalculationService::recalculate();
            $this->info('✅ Credit balances recalculated.');
        } catch (\Exception $e) {
            $this->error('Failed to recalculate credits: ' . $e->getMessage());
        }

        return 0;
    }

    /**
     * Find a matching payment for an invoice.
     * 
     * @param AllianceTaxInvoice $invoice
     * @param \Illuminate\Support\Collection $transactions
     * @param array $usedRefIds Already-claimed transaction ref_ids (passed by reference from caller)
     * @return object|null
     */
    protected function findMatchingPayment($invoice, $transactions, array &$usedRefIds)
    {
        // Get all characters owned by the user of this invoice
        $userId = DB::table('refresh_tokens')->where('character_id', $invoice->character_id)->value('user_id');
        
        if (!$userId) {
            // Fallback to just the invoice character if user cannot be found
            $userCharacterIds = [$invoice->character_id];
        } else {
            $userCharacterIds = DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->pluck('character_id')
                ->toArray();
        }

        // Ensure character IDs are integers for reliable comparison
        $userCharacterIds = array_map('intval', $userCharacterIds);

        // Cast invoice amount to float to avoid string comparison issues
        $invoiceAmount = (float) $invoice->amount;

        foreach ($transactions as $tx) {
            // Skip if this transaction has already been used to pay an invoice
            // (either from a previous run or earlier in this run)
            if (in_array($tx->ref_id, $usedRefIds)) {
                continue;
            }

            // Check if character matches (first_party_id or second_party_id)
            $firstPartyId = (int) $tx->first_party_id;
            $secondPartyId = (int) $tx->second_party_id;

            $isFromUserCharacter = in_array($firstPartyId, $userCharacterIds, true) || 
                                   in_array($secondPartyId, $userCharacterIds, true);

            if (!$isFromUserCharacter) {
                continue;
            }

            // Check if amount matches (minimum is invoice amount)
            // Cast to float for reliable numeric comparison
            $txAmount = (float) $tx->amount;
            if ($txAmount >= $invoiceAmount) {
                // Check if transaction is after invoice creation
                // We allow a 5-minute buffer for ESI sync differences
                $comparisonDate = Carbon::parse($invoice->created_at)->subMinutes(5);
                
                if (Carbon::parse($tx->date) >= $comparisonDate) {
                    return $tx;
                }
            }
        }

        return null;
    }

    /**
     * Mark invoice and related tax calculations as paid
     */
    protected function markInvoiceAsPaid($invoice, $transaction)
    {
        $invoiceAmount = (float) $invoice->amount;
        $txAmount = (float) $transaction->amount;
        
        $overpaidAmount = 0;
        if ($txAmount > $invoiceAmount) {
            $overpaidAmount = $txAmount - $invoiceAmount;
            // Note: credits are recalculated authoritatively after reconciliation
            // No incremental balance updates here
            $this->info("  ! Overpayment detected: " . number_format($overpaidAmount, 2) . " ISK (will be applied during credit recalculation)");
        }

        $invoice->status = 'paid';
        $invoice->paid_at = $transaction->date;
        $invoice->payment_ref_id = $transaction->ref_id;
        
        // Preserve original metadata (especially calculation_ids) and add payment info
        $originalMetadata = $invoice->metadata ? json_decode($invoice->metadata, true) : [];
        $originalMetadata['payment_transaction_id'] = $transaction->id;
        $originalMetadata['payment_ref_id'] = $transaction->ref_id;
        $originalMetadata['overpaid_amount'] = $overpaidAmount;
        $invoice->metadata = json_encode($originalMetadata);
        
        $invoice->save();

        // Mark related tax calculation(s) as paid
        $calc = $invoice->taxCalculation;
        if ($calc) {
            $calc->status = 'paid';
            $calc->is_paid = true;
            $calc->paid_at = $transaction->date;
            $calc->save();
        }

        // If consolidated invoice, mark all related calculations
        if (isset($originalMetadata['calculation_ids']) && is_array($originalMetadata['calculation_ids'])) {
            DB::table('alliance_tax_calculations')
                ->whereIn('id', $originalMetadata['calculation_ids'])
                ->update([
                    'status' => 'paid',
                    'is_paid' => true,
                    'paid_at' => $transaction->date,
                ]);
        }
    }

    /**
     * Determine the correct ref_type column name.
     * SeAT versions differ - some use 'ref_type' (string), others 'ref_type_id' (int).
     * 
     * @return string|null
     */
    protected function getRefTypeColumn()
    {
        $table = (new CorporationWalletJournal)->getTable();

        if (Schema::hasColumn($table, 'ref_type')) {
            return 'ref_type';
        }

        if (Schema::hasColumn($table, 'ref_type_id')) {
            $this->warn('Using ref_type_id column - skipping ref_type filter for broader matching');
            return null;
        }

        return 'ref_type';
    }
}

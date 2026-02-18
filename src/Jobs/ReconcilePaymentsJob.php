<?php

namespace Rejected\SeatAllianceTax\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceTaxInvoice;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
use Seat\Eveapi\Models\Wallet\CorporationWalletJournal;

class ReconcilePaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle()
    {
        $taxCorpId = AllianceTaxSetting::get('tax_collection_corporation_id');
        
        if (!$taxCorpId) {
            Log::info('[AllianceTax] Payment reconciliation skipped - no tax collection corp configured');
            return;
        }

        // Ensure corporation_id is compared as an integer to avoid type mismatch
        $taxCorpId = (int) $taxCorpId;

        Log::info("[AllianceTax] Starting payment reconciliation for corp {$taxCorpId}");

        // Look back 30 days for payments (was 7 - too short for late payments)
        $since = Carbon::now()->subDays(30);

        // Get unpaid invoices
        $unpaidInvoices = AllianceTaxInvoice::where('status', '!=', 'paid')
            ->with('character')
            ->get();

        if ($unpaidInvoices->isEmpty()) {
            Log::info('[AllianceTax] No unpaid invoices to reconcile');
            return;
        }

        Log::info("[AllianceTax] Found {$unpaidInvoices->count()} unpaid invoices to check");

        // Determine the correct ref_type column name (SeAT version compatibility)
        $refTypeColumn = $this->getRefTypeColumn();

        // Get wallet journal entries - include all payment-like ref_types
        $query = CorporationWalletJournal::where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->where('amount', '>', 0);

        // Apply ref_type filter using the correct column name
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

        Log::info("[AllianceTax] Found {$transactions->count()} incoming wallet transactions to check against");

        $matched = 0;

        // Load all already-used transaction ref_ids ONCE, then track in-memory
        // This prevents the same transaction being matched to multiple invoices
        $usedRefIds = AllianceTaxInvoice::whereNotNull('payment_ref_id')
            ->pluck('payment_ref_id')
            ->toArray();

        foreach ($unpaidInvoices as $invoice) {
            $payment = $this->findMatchingPayment($invoice, $transactions, $usedRefIds);

            if ($payment) {
                // Immediately claim this ref_id so no other invoice can use it
                $usedRefIds[] = $payment->ref_id;

                $this->markInvoiceAsPaid($invoice, $payment);
                $matched++;
                Log::info("[AllianceTax] Matched payment for character {$invoice->character_id}: " . number_format($invoice->amount, 2) . " ISK (ref_id: {$payment->ref_id})");
            }
        }

        Log::info("[AllianceTax] Payment reconciliation complete. Matched {$matched} of {$unpaidInvoices->count()} unpaid invoices.");
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
            // Note: if using ref_type_id, string values won't match.
            // For now, return null to skip the filter and rely on amount/character matching.
            Log::warning('[AllianceTax] Using ref_type_id column - skipping ref_type filter for broader matching');
            return null;
        }

        return 'ref_type';
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
        // (the 'decimal:2' cast returns a string like "12345678.90")
        $invoiceAmount = (float) $invoice->amount;

        foreach ($transactions as $tx) {
            // Skip if this transaction has already been used to pay an invoice
            // (either from a previous run or earlier in this run)
            if (in_array($tx->ref_id, $usedRefIds)) {
                continue;
            }

            // Check if character matches (any of user's characters)
            // Cast party IDs to int for reliable comparison
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
                // We allow a 5-minute buffer for ESI sync differences (was 1 min - too tight)
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
            
            // Update character balance
            $balance = \Rejected\SeatAllianceTax\Models\AllianceTaxBalance::firstOrCreate(['character_id' => $invoice->character_id]);
            $balance->balance += $overpaidAmount;
            $balance->save();
            
            Log::info("[AllianceTax] Overpayment detected: " . number_format($overpaidAmount, 2) . " ISK added to character {$invoice->character_id} balance.");
        }

        $invoice->status = 'paid';
        $invoice->paid_at = $transaction->date;
        $invoice->payment_ref_id = $transaction->ref_id;
        
        // Preserve original metadata (especially calculation_ids) and add payment info
        $metadata = $invoice->metadata ? json_decode($invoice->metadata, true) : [];
        $metadata['payment_transaction_id'] = $transaction->id;
        $metadata['payment_ref_id'] = $transaction->ref_id;
        $metadata['overpaid_amount'] = $overpaidAmount;
        $invoice->metadata = json_encode($metadata);
        
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
        if (isset($metadata['calculation_ids']) && is_array($metadata['calculation_ids'])) {
            DB::table('alliance_tax_calculations')
                ->whereIn('id', $metadata['calculation_ids'])
                ->update([
                    'status' => 'paid',
                    'is_paid' => true,
                    'paid_at' => $transaction->date,
                ]);
        }
    }
}

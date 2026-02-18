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
     * The wallet journal table name and its actual column names
     */
    protected $journalTable;
    protected $idColumn = 'id';

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

        $taxCorpId = (int) $taxCorpId;

        Log::info("[AllianceTax] Starting payment reconciliation for corp {$taxCorpId}");

        // Detect table structure
        $this->journalTable = (new CorporationWalletJournal)->getTable();
        $columns = Schema::getColumnListing($this->journalTable);
        
        // The column is 'id' in this SeAT version (not 'ref_id')
        $this->idColumn = in_array('ref_id', $columns) ? 'ref_id' : 'id';
        $hasRefType = in_array('ref_type', $columns);

        Log::info("[AllianceTax] Journal table: {$this->journalTable}, ID column: {$this->idColumn}, has ref_type: " . ($hasRefType ? 'yes' : 'no'));

        // Look back 60 days for payments (extended to catch older payments)
        $since = Carbon::now()->subDays(60);

        // Get unpaid invoices
        $unpaidInvoices = AllianceTaxInvoice::where('status', '!=', 'paid')
            ->with('character')
            ->get();

        if ($unpaidInvoices->isEmpty()) {
            Log::info('[AllianceTax] No unpaid invoices to reconcile');
            return;
        }

        Log::info("[AllianceTax] Found {$unpaidInvoices->count()} unpaid invoices to check");

        // Get all character IDs from unpaid invoices (and their alts) so we can search efficiently
        $allCharacterIds = [];
        foreach ($unpaidInvoices as $invoice) {
            $userId = DB::table('refresh_tokens')->where('character_id', $invoice->character_id)->value('user_id');
            if ($userId) {
                $charIds = DB::table('refresh_tokens')
                    ->where('user_id', $userId)
                    ->pluck('character_id')
                    ->map(function($id) { return (int)$id; })
                    ->toArray();
                $allCharacterIds = array_merge($allCharacterIds, $charIds);
            } else {
                $allCharacterIds[] = (int) $invoice->character_id;
            }
        }
        $allCharacterIds = array_unique($allCharacterIds);

        // Query wallet journal: get ALL positive transactions where any invoice character is a party
        // This is much better than filtering by ref_type which may miss payment types
        $transactions = DB::table($this->journalTable)
            ->where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->where('amount', '>', 0)
            ->where(function ($query) use ($allCharacterIds) {
                $query->whereIn('first_party_id', $allCharacterIds)
                      ->orWhereIn('second_party_id', $allCharacterIds);
            })
            ->orderBy('date', 'desc')
            ->get();

        Log::info("[AllianceTax] Found {$transactions->count()} transactions from invoice characters");

        // If no character-specific transactions found, also try with ref_type filter as fallback
        if ($transactions->isEmpty() && $hasRefType) {
            $transactions = DB::table($this->journalTable)
                ->where('corporation_id', $taxCorpId)
                ->where('date', '>=', $since)
                ->where('amount', '>', 0)
                ->whereIn('ref_type', [
                    'player_donation',
                    'player_trading',
                    'corporation_account_withdrawal',
                    'direct_transfer',
                    'cash_out',
                    'deposit',
                    'union_payment',
                ])
                ->orderBy('date', 'desc')
                ->get();
            Log::info("[AllianceTax] Fallback: found {$transactions->count()} donation-type transactions");
        }

        if ($transactions->isEmpty()) {
            Log::info('[AllianceTax] No matching wallet transactions found at all');
            return;
        }

        $matched = 0;

        // Load all already-used transaction IDs ONCE, then track in-memory
        $usedTxIds = AllianceTaxInvoice::whereNotNull('payment_ref_id')
            ->pluck('payment_ref_id')
            ->map(function($id) { return (string) $id; })
            ->toArray();

        foreach ($unpaidInvoices as $invoice) {
            $payment = $this->findMatchingPayment($invoice, $transactions, $usedTxIds);

            if ($payment) {
                $txId = (string) ($payment->{$this->idColumn} ?? $payment->id ?? null);
                $usedTxIds[] = $txId;

                $this->markInvoiceAsPaid($invoice, $payment, $txId);
                $matched++;
                Log::info("[AllianceTax] Matched payment for character {$invoice->character_id}: " 
                    . number_format((float)$invoice->amount, 2) . " ISK (tx_id: {$txId})");
            }
        }

        Log::info("[AllianceTax] Payment reconciliation complete. Matched {$matched} of {$unpaidInvoices->count()} unpaid invoices.");
    }

    /**
     * Find a matching payment for an invoice.
     */
    protected function findMatchingPayment($invoice, $transactions, array &$usedTxIds)
    {
        // Get all characters owned by the user of this invoice
        $userId = DB::table('refresh_tokens')->where('character_id', $invoice->character_id)->value('user_id');
        
        if (!$userId) {
            $userCharacterIds = [(int) $invoice->character_id];
        } else {
            $userCharacterIds = DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->pluck('character_id')
                ->map(function($id) { return (int)$id; })
                ->toArray();
        }

        $invoiceAmount = (float) $invoice->amount;

        foreach ($transactions as $tx) {
            // Get the transaction ID using the correct column
            $txId = (string) ($tx->{$this->idColumn} ?? $tx->id ?? null);
            
            // Skip if already used
            if (in_array($txId, $usedTxIds, true)) {
                continue;
            }

            // Check if character matches (any of user's characters)
            $firstPartyId = (int) ($tx->first_party_id ?? 0);
            $secondPartyId = (int) ($tx->second_party_id ?? 0);

            $isFromUserCharacter = in_array($firstPartyId, $userCharacterIds, true) || 
                                   in_array($secondPartyId, $userCharacterIds, true);

            if (!$isFromUserCharacter) {
                continue;
            }

            // Check if amount is sufficient (>= invoice amount)
            $txAmount = (float) ($tx->amount ?? 0);
            if ($txAmount >= $invoiceAmount) {
                return $tx;
            }
        }

        return null;
    }

    /**
     * Mark invoice and related tax calculations as paid
     */
    protected function markInvoiceAsPaid($invoice, $transaction, $txId)
    {
        $invoiceAmount = (float) $invoice->amount;
        $txAmount = (float) ($transaction->amount ?? 0);
        
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
        $invoice->paid_at = $transaction->date ?? now();
        $invoice->payment_ref_id = $txId;
        
        // Preserve original metadata and add payment info
        $metadata = $invoice->metadata ? json_decode($invoice->metadata, true) : [];
        $metadata['payment_transaction_id'] = $txId;
        $metadata['payment_amount'] = $txAmount;
        $metadata['overpaid_amount'] = $overpaidAmount;
        $invoice->metadata = json_encode($metadata);
        
        $invoice->save();

        // Mark related tax calculation(s) as paid
        $calc = $invoice->taxCalculation;
        if ($calc) {
            $calc->status = 'paid';
            $calc->is_paid = true;
            $calc->paid_at = $transaction->date ?? now();
            $calc->save();
        }

        // If consolidated invoice, mark all related calculations
        if (isset($metadata['calculation_ids']) && is_array($metadata['calculation_ids'])) {
            DB::table('alliance_tax_calculations')
                ->whereIn('id', $metadata['calculation_ids'])
                ->update([
                    'status' => 'paid',
                    'is_paid' => true,
                    'paid_at' => $transaction->date ?? now(),
                ]);
        }
    }
}

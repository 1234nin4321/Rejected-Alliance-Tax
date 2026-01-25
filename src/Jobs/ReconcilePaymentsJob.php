<?php

namespace Rejected\SeatAllianceTax\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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

        Log::info("[AllianceTax] Starting payment reconciliation for corp {$taxCorpId}");

        // Look back 7 days for payments
        $since = Carbon::now()->subDays(7);

        // Get unpaid invoices
        $unpaidInvoices = AllianceTaxInvoice::where('status', '!=', 'paid')
            ->with('character')
            ->get();

        if ($unpaidInvoices->isEmpty()) {
            Log::info('[AllianceTax] No unpaid invoices to reconcile');
            return;
        }

        // Get wallet journal entries
        $transactions = CorporationWalletJournal::where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->whereIn('ref_type', [
                'player_donation', 
                'corporation_account_withdrawal', 
                'direct_transfer', 
                'cash_out'
            ])
            ->where('amount', '>', 0)
            ->get();

        $matched = 0;
        foreach ($unpaidInvoices as $invoice) {
            $payment = $this->findMatchingPayment($invoice, $transactions);

            if ($payment) {
                $this->markInvoiceAsPaid($invoice, $payment);
                $matched++;
                Log::info("[AllianceTax] Matched payment for character {$invoice->character_id}: " . number_format($invoice->amount, 2) . " ISK");
            }
        }

        Log::info("[AllianceTax] Payment reconciliation complete. Matched {$matched} payments.");
    }

    /**
     * Find a matching payment for an invoice
     */
    protected function findMatchingPayment($invoice, $transactions)
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

        // Get all transaction IDs already used for payments to avoid duplicates
        $usedRefIds = AllianceTaxInvoice::whereNotNull('payment_ref_id')
            ->pluck('payment_ref_id')
            ->toArray();

        foreach ($transactions as $tx) {
            // Skip if this transaction has already been used to pay an invoice
            if (in_array($tx->ref_id, $usedRefIds)) {
                continue;
            }

            // Check if character matches (any of user's characters)
            $isFromUserCharacter = in_array($tx->first_party_id, $userCharacterIds) || 
                                   in_array($tx->second_party_id, $userCharacterIds);

            if (!$isFromUserCharacter) {
                continue;
            }

            // Check if amount matches (minimum is invoice amount)
            // We allow overpayments
            if ($tx->amount >= $invoice->amount) {
                // Check if transaction is after invoice creation
                // We allow a 1-minute buffer for ESI sync differences
                $comparisonDate = \Carbon\Carbon::parse($invoice->created_at)->subMinute();

                if ($tx->date >= $comparisonDate) {
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
        $overpaidAmount = 0;
        if ($transaction->amount > $invoice->amount) {
            $overpaidAmount = $transaction->amount - $invoice->amount;
            
            // Update character balance
            $balance = \Rejected\SeatAllianceTax\Models\AllianceTaxBalance::firstOrCreate(['character_id' => $invoice->character_id]);
            $balance->balance += $overpaidAmount;
            $balance->save();
            
            Log::info("[AllianceTax] Overpayment detected: " . number_format($overpaidAmount, 2) . " ISK added to character {$invoice->character_id} balance.");
        }

        $invoice->status = 'paid';
        $invoice->paid_at = $transaction->date;
        $invoice->payment_ref_id = $transaction->ref_id;
        
        // Store transaction reference
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
        if (isset($metadata['calculation_ids'])) {
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

<?php

namespace Rejected\SeatAllianceTax\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceTaxInvoice;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
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

        // Get wallet journal entries (player donations/transfers)
        $transactions = CorporationWalletJournal::where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->whereIn('ref_type', [
                'player_donation', 
                'corporation_account_withdrawal', 
                'direct_transfer', 
                'cash_out',
                'deposit',
                'union_payment'
            ])
            ->where('amount', '>', 0) // Incoming only
            ->orderBy('date', 'desc')
            ->get();

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

        foreach ($unpaidInvoices as $invoice) {
            // Look for matching transaction
            $payment = $this->findMatchingPayment($invoice, $transactions);

            if ($payment) {
                $this->markInvoiceAsPaid($invoice, $payment);
                $matched++;
                $this->newLine();
                $characterName = $invoice->character ? $invoice->character->name : $invoice->character_id;
                $this->info("✓ Matched payment for {$characterName}: " . number_format($invoice->amount, 2) . " ISK");
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("✅ Reconciliation complete! Matched {$matched} payments.");

        return 0;
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

            // Check if character matches (first_party_id or second_party_id)
            $isFromUserCharacter = in_array($tx->first_party_id, $userCharacterIds) || 
                                   in_array($tx->second_party_id, $userCharacterIds);

            if (!$isFromUserCharacter) {
                continue;
            }

            // Check if amount matches (minimum is invoice amount)
            // We allow overpayments
            if ($tx->amount >= $invoice->amount) {
                // Check if transaction is after invoice creation
                // We allow a 1-minute buffer in case of slight time sync differences
                $comparisonDate = Carbon::parse($invoice->created_at)->subMinute();
                
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
            
            $this->info("  ! Overpayment detected: " . number_format($overpaidAmount, 2) . " ISK added to character balance.");
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
}

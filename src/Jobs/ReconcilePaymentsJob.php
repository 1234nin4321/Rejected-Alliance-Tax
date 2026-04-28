<?php

namespace Rejected\SeatAllianceTax\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceTaxInvoice;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
use Rejected\SeatAllianceTax\Services\CreditRecalculationService;
use Seat\Eveapi\Models\Wallet\CorporationWalletJournal;

class ReconcilePaymentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The correct column name for the journal entry ID
     */
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
        $journalTable = (new CorporationWalletJournal)->getTable();
        $columns = Schema::getColumnListing($journalTable);
        $this->idColumn = in_array('ref_id', $columns) ? 'ref_id' : 'id';

        Log::info("[AllianceTax] Journal table: {$journalTable}, ID column: {$this->idColumn}");

        // Look back 60 days for payments
        $since = Carbon::now()->subDays(60);

        // Get unpaid invoices (including partially paid ones).
        // Process oldest first so overdue invoices are settled before new ones,
        // allowing overpayments to roll forward to the newer invoice in the same pass.
        $unpaidInvoices = AllianceTaxInvoice::where('status', '!=', 'paid')
            ->with('character')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($unpaidInvoices->isEmpty()) {
            Log::info('[AllianceTax] No unpaid invoices to reconcile');
            return;
        }

        Log::info("[AllianceTax] Found {$unpaidInvoices->count()} unpaid invoices to check");

        // Collect ALL character IDs from unpaid invoices (and their alts)
        $allCharacterIds = [];
        $invoiceCharMap = []; // invoice_id => [character_ids]
        foreach ($unpaidInvoices as $invoice) {
            $userId = DB::table('refresh_tokens')->where('character_id', $invoice->character_id)->value('user_id');
            if ($userId) {
                $charIds = DB::table('refresh_tokens')
                    ->where('user_id', $userId)
                    ->pluck('character_id')
                    ->map(function($id) { return (int)$id; })
                    ->toArray();
            } else {
                $charIds = [(int) $invoice->character_id];
            }
            $invoiceCharMap[$invoice->id] = $charIds;
            $allCharacterIds = array_merge($allCharacterIds, $charIds);
        }
        $allCharacterIds = array_unique($allCharacterIds);

        // Get all positive transactions where any invoice character is a party
        $transactions = DB::table($journalTable)
            ->where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->where('amount', '>', 0)
            ->where(function ($query) use ($allCharacterIds) {
                $query->whereIn('first_party_id', $allCharacterIds)
                      ->orWhereIn('second_party_id', $allCharacterIds);
            })
            ->orderBy('date', 'asc') // Process oldest first
            ->get();

        Log::info("[AllianceTax] Found {$transactions->count()} transactions from invoice characters");

        if ($transactions->isEmpty()) {
            Log::info('[AllianceTax] No matching wallet transactions found');
            return;
        }

        // Load all already-used transaction IDs from all invoices (including partials)
        $usedTxIds = AllianceTaxInvoice::whereNotNull('payment_ref_id')
            ->pluck('payment_ref_id')
            ->map(function($id) { return (string) $id; })
            ->toArray();
        
        // Also load all transaction IDs from metadata of ALL invoices
        $allMetadata = AllianceTaxInvoice::whereNotNull('metadata')
            ->pluck('metadata');
        
        foreach ($allMetadata as $metaJson) {
            $meta = json_decode($metaJson, true);
            $applied = $meta['applied_payments'] ?? [];
            foreach ($applied as $p) {
                if (isset($p['tx_id'])) {
                    $usedTxIds[] = (string) $p['tx_id'];
                }
            }
        }
        $usedTxIds = array_unique(array_filter($usedTxIds));

        $matched = 0;
        $partialCount = 0;

        // Track overpayments per character so a single large transaction can
        // immediately satisfy subsequent invoices for the same character.
        // Key: character_id, Value: float ISK remaining from an overpayment.
        $carryForward = [];

        foreach ($unpaidInvoices as $invoice) {
            $userCharIds = $invoiceCharMap[$invoice->id] ?? [(int)$invoice->character_id];
            $invoiceAmount = (float) $invoice->amount;
            $metadata = $invoice->metadata ? json_decode($invoice->metadata, true) : [];
            $appliedPayments = $metadata['applied_payments'] ?? [];
            
            // Calculate how much is still owed
            $totalPaid = 0;
            foreach ($appliedPayments as $payment) {
                $totalPaid += (float) ($payment['amount'] ?? 0);
            }
            $remaining = $invoiceAmount - $totalPaid;

            if ($remaining <= 0) {
                // Already fully paid through partials, mark as paid
                $this->markInvoiceFullyPaid($invoice, $metadata);
                $matched++;
                continue;
            }

            // ── Apply any carry-forward overpayment from a previous invoice ──
            // This handles the case where a single wallet transaction was larger
            // than the first invoice — the surplus is immediately applied here.
            $charKey = (int) $invoice->character_id;
            if (!empty($carryForward[$charKey])) {
                $carry = (float) $carryForward[$charKey];
                if ($carry >= $remaining) {
                    // Carry-forward alone fully covers this invoice
                    $carryForward[$charKey] = $carry - $remaining;
                    $appliedPayments[] = [
                        'tx_id'     => 'carry_forward_' . time() . '_' . $invoice->id,
                        'amount'    => $remaining,
                        'date'      => now()->toDateTimeString(),
                        'ref_type'  => 'carry_forward_credit',
                        'note'      => 'Applied from overpayment on previous invoice',
                    ];
                    $metadata['applied_payments'] = $appliedPayments;
                    $this->markInvoiceFullyPaid($invoice, $metadata);
                    $matched++;
                    Log::info("[AllianceTax] Invoice #{$invoice->id} for character {$invoice->character_id} cleared by carry-forward credit.");
                    continue;
                } else {
                    // Partial cover from carry-forward, then fall through to find more transactions
                    $remaining -= $carry;
                    $appliedPayments[] = [
                        'tx_id'     => 'carry_forward_' . time() . '_' . $invoice->id,
                        'amount'    => $carry,
                        'date'      => now()->toDateTimeString(),
                        'ref_type'  => 'carry_forward_credit',
                        'note'      => 'Partial carry-forward from previous invoice overpayment',
                    ];
                    $carryForward[$charKey] = 0;
                    Log::info("[AllianceTax] Partial carry-forward of " . number_format($carry, 0) . " ISK applied to invoice #{$invoice->id}. Remaining: " . number_format($remaining, 0) . " ISK.");
                }
            }

            // Find matching transactions for this invoice
            foreach ($transactions as $tx) {
                $row = (array) $tx;
                $txId = (string) ($row[$this->idColumn] ?? $row['id'] ?? null);
                
                // Skip if already used
                if (in_array($txId, $usedTxIds, true)) {
                    continue;
                }

                // Check if character matches
                $firstPartyId = (int) ($row['first_party_id'] ?? 0);
                $secondPartyId = (int) ($row['second_party_id'] ?? 0);
                $isFromUser = in_array($firstPartyId, $userCharIds, true) || 
                              in_array($secondPartyId, $userCharIds, true);

                if (!$isFromUser) {
                    continue;
                }

                $txAmount = (float) ($row['amount'] ?? 0);

                // Match transactions that occurred around or after the invoice was created
                // (with a 3-day buffer to allow for early payments made right after mining)
                $txDate = Carbon::parse($row['date'] ?? now());
                $earliestAllowed = Carbon::parse($invoice->created_at)->subDays(3);
                if ($txDate < $earliestAllowed) {
                    continue;
                }
                
                // Claim this transaction
                $usedTxIds[] = $txId;

                if ($txAmount >= $remaining) {
                    // Full payment (or overpayment) — mark invoice as paid
                    $overpaid = $txAmount - $remaining;
                    
                    // Record this payment
                    $appliedPayments[] = [
                        'tx_id'    => $txId,
                        'amount'   => $remaining, // Only count what was needed
                        'date'     => $row['date'] ?? now()->toDateTimeString(),
                        'ref_type' => $row['ref_type'] ?? 'unknown',
                    ];
                    $metadata['applied_payments'] = $appliedPayments;

                    // Store overpayment in metadata AND in the carry-forward map
                    // so subsequent invoices for the same character are cleared in
                    // this same reconciliation pass — not just on the next run.
                    if ($overpaid > 0) {
                        $metadata['overpaid_amount'] = ($metadata['overpaid_amount'] ?? 0) + $overpaid;
                        $carryForward[$charKey] = ($carryForward[$charKey] ?? 0) + $overpaid;
                        Log::info("[AllianceTax] Overpayment of " . number_format($overpaid, 0) . " ISK for character {$invoice->character_id} — carrying forward to next invoice in this pass.");
                    }

                    $invoice->status = 'paid';
                    $invoice->paid_at = $row['date'] ?? now();
                    $invoice->payment_ref_id = $txId;
                    $invoice->metadata = json_encode($metadata);

                    try {
                        $invoice->save();
                    } catch (UniqueConstraintViolationException $e) {
                        // Same transaction already assigned to another invoice — store ref in metadata only
                        Log::warning("[AllianceTax] payment_ref_id {$txId} already used on another invoice, saving without it");
                        $invoice->payment_ref_id = null;
                        $invoice->save();
                    }

                    // Mark related tax calculations as paid
                    $this->markCalculationsPaid($invoice, $metadata, $row['date'] ?? now());

                    $matched++;
                    Log::info("[AllianceTax] Fully paid: character {$invoice->character_id}, " . number_format($invoiceAmount, 0) . " ISK (tx: {$txId})");
                    break; // Move to next invoice

                } else {
                    // Partial payment — deduct from remaining and keep going
                    $appliedPayments[] = [
                        'tx_id' => $txId,
                        'amount' => $txAmount,
                        'date' => $row['date'] ?? now()->toDateTimeString(),
                        'ref_type' => $row['ref_type'] ?? 'unknown',
                    ];
                    $remaining -= $txAmount;
                    $partialCount++;

                    Log::info("[AllianceTax] Partial payment: character {$invoice->character_id}, " 
                        . number_format($txAmount, 0) . " ISK applied. Remaining: " . number_format($remaining, 0) . " ISK (tx: {$txId})");
                    
                    // Continue looking for more transactions for this invoice
                }
            }

            // If we applied new payments, update the invoice metadata
            if (count($appliedPayments) > count($metadata['applied_payments'] ?? [])) {
                $metadata['applied_payments'] = $appliedPayments;
                $invoice->metadata = json_encode($metadata);
                
                // Calculate total applied to see if it's now fully paid
                $totalApplied = 0;
                foreach ($appliedPayments as $p) {
                    $totalApplied += (float) ($p['amount'] ?? 0);
                }
                
                if ($totalApplied >= $invoiceAmount) {
                    // Fully paid through multiple payments
                    $invoice->status = 'paid';
                    $invoice->paid_at = now();
                    $invoice->payment_ref_id = $appliedPayments[count($appliedPayments) - 1]['tx_id'] ?? null;

                    try {
                        $invoice->save();
                    } catch (UniqueConstraintViolationException $e) {
                        Log::warning("[AllianceTax] payment_ref_id already used on another invoice (partials), saving without it");
                        $invoice->payment_ref_id = null;
                        $invoice->save();
                    }
                    $this->markCalculationsPaid($invoice, $metadata, now());
                    $matched++;
                    Log::info("[AllianceTax] Fully paid via multiple payments: character {$invoice->character_id}");
                } else {
                    // Still partially unpaid — just update metadata and status
                    // DO NOT overwrite $invoice->amount, it must stay as the original total
                    $invoice->status = 'partial';
                    $invoice->save();
                    Log::info("[AllianceTax] Partially paid: character {$invoice->character_id}, paid total: " . number_format($totalApplied, 0) . " / " . number_format($invoiceAmount, 0));
                }
            }

        }

        Log::info("[AllianceTax] Reconciliation complete. Fully matched: {$matched}, Partial payments applied: {$partialCount}");

        // Recalculate all credit balances from source of truth (total sent vs total invoiced)
        // This prevents credits from accumulating incorrectly across repeated runs
        try {
            CreditRecalculationService::recalculate();
            Log::info('[AllianceTax] Credit balances recalculated after reconciliation');
        } catch (\Exception $e) {
            Log::error('[AllianceTax] Failed to recalculate credits: ' . $e->getMessage());
        }
    }

    /**
     * Mark an invoice as fully paid (when partials add up)
     */
    protected function markInvoiceFullyPaid($invoice, $metadata)
    {
        $invoice->status = 'paid';
        $invoice->paid_at = now();
        $invoice->metadata = json_encode($metadata);
        $invoice->save();

        $this->markCalculationsPaid($invoice, $metadata, now());
    }

    /**
     * Mark related tax calculations as paid
     */
    protected function markCalculationsPaid($invoice, $metadata, $paidAt)
    {
        // Mark single linked calculation
        $calc = $invoice->taxCalculation;
        if ($calc) {
            $calc->status = 'paid';
            $calc->is_paid = true;
            $calc->paid_at = $paidAt;
            $calc->save();
        }

        // Mark all consolidated calculation_ids
        if (isset($metadata['calculation_ids']) && is_array($metadata['calculation_ids'])) {
            DB::table('alliance_tax_calculations')
                ->whereIn('id', $metadata['calculation_ids'])
                ->update([
                    'status' => 'paid',
                    'is_paid' => true,
                    'paid_at' => $paidAt,
                ]);
        }
    }
}

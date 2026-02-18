<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;
use Rejected\SeatAllianceTax\Models\AllianceTaxInvoice;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;

class InvoiceController extends Controller
{
    /**
     * Display invoice management page
     */
    public function index()
    {
        $pendingInvoices = AllianceTaxInvoice::with(['character', 'corporation'])
            ->where('status', '!=', 'paid')
            ->orderBy('created_at', 'desc')
            ->paginate(50, ['*'], 'pending_page');

        $paidInvoices = AllianceTaxInvoice::with(['character', 'corporation'])
            ->where('status', 'paid')
            ->orderBy('paid_at', 'desc')
            ->paginate(50, ['*'], 'paid_page');

        $stats = [
            'total_sent' => AllianceTaxInvoice::where('status', 'sent')->sum('amount'),
            'total_overdue' => AllianceTaxInvoice::overdue()->sum('amount'),
            'total_paid' => AllianceTaxInvoice::where('status', 'paid')->sum('amount'),
            'pending_calc_count' => AllianceTaxCalculation::where('status', 'pending')->count(),
            'pending_invoice_count' => AllianceTaxInvoice::where('status', '!=', 'paid')->count(),
            'paid_invoice_count' => AllianceTaxInvoice::where('status', 'paid')->count(),
        ];

        $pendingCalculations = AllianceTaxCalculation::where('status', 'pending')
            ->with(['character', 'corporation'])
            ->orderBy('tax_period', 'desc')
            ->paginate(50, ['*'], 'calc_page');

        return view('alliancetax::invoices.index', compact(
            'pendingInvoices', 
            'paidInvoices', 
            'stats', 
            'pendingCalculations'
        ));
    }

    /**
     * Generate invoices for a period (consolidated by user's main character)
     */
    public function generate(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
            'due_days' => 'required|integer|min:1|max:30',
        ]);

        $service = new \Rejected\SeatAllianceTax\Services\TaxCalculationService();
        $result = $service->generateInvoices(
            Carbon::parse($request->period_start),
            Carbon::parse($request->period_end),
            $request->due_days
        );

        $generated = $result['count'] ?? 0;

        return redirect()->route('alliancetax.invoices.index')
            ->with('success', "Generated {$generated} consolidated invoices (grouped by main character).");
    }

    /**
     * Send Discord notifications for invoices
     */
    public function sendNotifications(Request $request)
    {
        $request->validate([
            'invoice_ids' => 'required|array',
            'invoice_ids.*' => 'exists:alliance_tax_invoices,id',
        ]);

        $invoices = AllianceTaxInvoice::whereIn('id', $request->invoice_ids)
            ->whereNull('notified_at')
            ->get();

        if ($invoices->isEmpty()) {
            return redirect()->back()->with('warning', 'No invoices to notify (already notified)');
        }

        // Mark all as notified
        foreach ($invoices as $invoice) {
            $invoice->notified_at = now();
            $invoice->save();
        }
        
        $service = new \Rejected\SeatAllianceTax\Services\TaxCalculationService();
        $service->notifyDiscord($invoices->count());

        return redirect()->back()->with('success', "Marked {$invoices->count()} invoice(s) as notified and triggered Discord announcement.");
    }

    /**
     * Mark invoice as paid
     */
    public function markPaid($id)
    {
        $invoice = AllianceTaxInvoice::findOrFail($id);
        $invoice->status = 'paid';
        $invoice->paid_at = now();
        $invoice->save();

        // Mark the single linked calculation as paid
        $calc = $invoice->taxCalculation;
        if ($calc) {
            $calc->status = 'paid';
            $calc->is_paid = true;
            $calc->paid_at = now();
            $calc->save();
        }

        // Also mark all consolidated calculation_ids from metadata
        $metadata = json_decode($invoice->metadata, true);
        if (isset($metadata['calculation_ids']) && is_array($metadata['calculation_ids'])) {
            AllianceTaxCalculation::whereIn('id', $metadata['calculation_ids'])->update([
                'status' => 'paid',
                'is_paid' => true,
                'paid_at' => now(),
            ]);
        }

        return redirect()->back()->with('success', 'Invoice marked as paid');
    }

    /**
     * Bulk mark invoices as paid
     */
    public function bulkMarkPaid(Request $request)
    {
        $request->validate([
            'invoice_ids' => 'required|array',
            'invoice_ids.*' => 'exists:alliance_tax_invoices,id',
        ]);

        $count = 0;
        foreach ($request->invoice_ids as $id) {
            $invoice = AllianceTaxInvoice::find($id);
            if ($invoice && $invoice->status !== 'paid') {
                $invoice->status = 'paid';
                $invoice->paid_at = now();
                $invoice->save();
                
                // Mark single linked calculation
                $calc = $invoice->taxCalculation;
                if ($calc) {
                    $calc->status = 'paid';
                    $calc->is_paid = true;
                    $calc->paid_at = now();
                    $calc->save();
                }

                // Also mark all consolidated calculation_ids from metadata
                $metadata = json_decode($invoice->metadata, true);
                if (isset($metadata['calculation_ids']) && is_array($metadata['calculation_ids'])) {
                    AllianceTaxCalculation::whereIn('id', $metadata['calculation_ids'])->update([
                        'status' => 'paid',
                        'is_paid' => true,
                        'paid_at' => now(),
                    ]);
                }

                $count++;
            }
        }

        return redirect()->back()->with('success', "Marked {$count} invoices as paid");
    }

    /**
     * Delete an invoice
     */
    public function destroy($id)
    {
        $invoice = AllianceTaxInvoice::findOrFail($id);
        
        // When deleting an invoice, we reset the status of related tax calculations back to pending
        $metadata = json_decode($invoice->metadata, true);
        if (isset($metadata['calculation_ids'])) {
            AllianceTaxCalculation::whereIn('id', $metadata['calculation_ids'])->update([
                'status' => 'pending',
                'is_paid' => false,
                'paid_at' => null
            ]);
        } else if ($invoice->tax_calculation_id) {
            $calc = AllianceTaxCalculation::find($invoice->tax_calculation_id);
            if ($calc) {
                $calc->status = 'pending';
                $calc->is_paid = false;
                $calc->paid_at = null;
                $calc->save();
            }
        }

        $invoice->delete();

        return redirect()->route('alliancetax.invoices.index')
            ->with('success', 'Invoice deleted successfully. Related tax calculations have been reset to pending.');
    }

    /**
     * Bulk delete invoices
     */
    public function bulkDelete(Request $request)
    {
        $ids = $request->input('invoice_ids', []);
        
        if (empty($ids)) {
            return redirect()->back()->with('error', 'No invoices selected');
        }

        $invoices = AllianceTaxInvoice::whereIn('id', $ids)->get();
        $count = 0;

        foreach ($invoices as $invoice) {
            // Reset calculations
            $metadata = json_decode($invoice->metadata, true);
            if (isset($metadata['calculation_ids'])) {
                AllianceTaxCalculation::whereIn('id', $metadata['calculation_ids'])->update([
                    'status' => 'pending',
                    'is_paid' => false,
                    'paid_at' => null
                ]);
            } else if ($invoice->tax_calculation_id) {
                AllianceTaxCalculation::where('id', $invoice->tax_calculation_id)->update([
                    'status' => 'pending',
                    'is_paid' => false,
                    'paid_at' => null
                ]);
            }

            $invoice->delete();
            $count++;
        }

        return redirect()->route('alliancetax.invoices.index')
            ->with('success', "Deleted {$count} invoices and reset related calculations.");
    }

    /**
     * Manually trigger payment reconciliation
     */
    public function reconcile()
    {
        $taxCorpId = AllianceTaxSetting::get('tax_collection_corporation_id');

        if (!$taxCorpId) {
            return redirect()->route('alliancetax.invoices.index')
                ->with('error', 'Cannot reconcile: No tax collection corporation configured. Please set it in Admin → Settings.');
        }

        $taxCorpId = (int) $taxCorpId;
        $debug = request()->has('debug');

        // Run reconciliation
        $unpaidBefore = AllianceTaxInvoice::where('status', '!=', 'paid')->count();

        $job = new \Rejected\SeatAllianceTax\Jobs\ReconcilePaymentsJob();
        $job->handle();

        $unpaidAfter = AllianceTaxInvoice::where('status', '!=', 'paid')->count();
        $matched = $unpaidBefore - $unpaidAfter;

        if (!$debug) {
            if ($matched > 0) {
                return redirect()->route('alliancetax.invoices.index')
                    ->with('success', "Payment reconciliation complete! Matched {$matched} invoice(s) to wallet transactions.");
            }
            return redirect()->route('alliancetax.invoices.index')
                ->with('info', 'Payment reconciliation complete. No new payment matches found.');
        }

        // === DEBUG MODE: Show why remaining invoices didn't match ===
        $diag = [];
        $diag[] = "📊 <strong>Reconciliation Result: Matched {$matched}, Remaining unpaid: {$unpaidAfter}</strong>";

        $journalTable = (new \Seat\Eveapi\Models\Wallet\CorporationWalletJournal)->getTable();
        $since = \Carbon\Carbon::now()->subDays(60);

        // Get remaining unpaid invoices
        $stillUnpaid = AllianceTaxInvoice::where('status', '!=', 'paid')
            ->with('character')
            ->get();

        $usedTxIds = AllianceTaxInvoice::whereNotNull('payment_ref_id')
            ->pluck('payment_ref_id')
            ->map(function($id) { return (string) $id; })
            ->toArray();
        $diag[] = "Already-used transaction IDs: " . count($usedTxIds);

        foreach ($stillUnpaid as $invoice) {
            $charName = optional($invoice->character)->name ?? 'Unknown';
            $invoiceAmount = (float) $invoice->amount;

            // Get user's character IDs
            $userId = \Illuminate\Support\Facades\DB::table('refresh_tokens')
                ->where('character_id', $invoice->character_id)
                ->value('user_id');

            if ($userId) {
                $userCharIds = \Illuminate\Support\Facades\DB::table('refresh_tokens')
                    ->where('user_id', $userId)
                    ->pluck('character_id')
                    ->map(function($id) { return (int)$id; })
                    ->toArray();
            } else {
                $userCharIds = [(int)$invoice->character_id];
            }

            $charIdList = implode(', ', $userCharIds);
            $diag[] = "<br>📌 <strong>{$charName}</strong> (ID:{$invoice->character_id}) — " . number_format($invoiceAmount, 0) . " ISK — Created: {$invoice->created_at}";
            $diag[] = "&nbsp;&nbsp;Owner chars: <code>[{$charIdList}]</code>" . (!$userId ? " ⚠️ No refresh_token!" : "");

            // Search for ANY positive transactions involving this user's characters
            $charTx = \Illuminate\Support\Facades\DB::table($journalTable)
                ->where('corporation_id', $taxCorpId)
                ->where('amount', '>', 0)
                ->where(function($q) use ($userCharIds) {
                    $q->whereIn('first_party_id', $userCharIds)
                      ->orWhereIn('second_party_id', $userCharIds);
                })
                ->orderBy('date', 'desc')
                ->take(5)
                ->get();

            if ($charTx->isEmpty()) {
                $diag[] = "&nbsp;&nbsp;❌ <strong>No wallet transactions found involving any of this user's characters!</strong>";
                $diag[] = "&nbsp;&nbsp;&nbsp;&nbsp;This means the payment either hasn't been synced from ESI, was made from an unlinked alt, or hasn't been made yet.";
            } else {
                $diag[] = "&nbsp;&nbsp;Found {$charTx->count()} transaction(s) from this user:";
                foreach ($charTx as $tx) {
                    $row = (array) $tx;
                    $txId = (string) $row['id'];
                    $txAmount = (float) $row['amount'];
                    $isUsed = in_array($txId, $usedTxIds, true);
                    $amountOk = $txAmount >= $invoiceAmount;

                    $flags = [];
                    if ($isUsed) $flags[] = "⛔ already used by another invoice";
                    if (!$amountOk) $flags[] = "💰 amount too low (" . number_format($txAmount, 0) . " < " . number_format($invoiceAmount, 0) . ")";
                    if (!$isUsed && $amountOk) $flags[] = "✅ should match — <strong>possible bug</strong>";

                    $refType = $row['ref_type'] ?? 'N/A';
                    $diag[] = "&nbsp;&nbsp;&nbsp;&nbsp;• [{$row['date']}] id:<code>{$txId}</code> type:<code>{$refType}</code> amt:<code>" . number_format($txAmount, 0) . "</code> — " . implode(', ', $flags);
                }
            }
        }

        $message = implode('<br>', $diag);
        return redirect()->route('alliancetax.invoices.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }
}


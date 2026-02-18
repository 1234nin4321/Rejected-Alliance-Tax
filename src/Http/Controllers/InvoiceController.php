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
     * Manually trigger payment reconciliation with diagnostic output
     */
    public function reconcile()
    {
        $taxCorpId = AllianceTaxSetting::get('tax_collection_corporation_id');

        if (!$taxCorpId) {
            return redirect()->route('alliancetax.invoices.index')
                ->with('error', 'Cannot reconcile: No tax collection corporation configured. Please set it in Admin → Settings.');
        }

        $taxCorpId = (int) $taxCorpId;
        $diagnostics = [];
        $diagnostics[] = "🔧 <strong>Diagnostic Reconciliation Report</strong>";
        $diagnostics[] = "Tax Corp ID: <code>{$taxCorpId}</code>";

        // Check the wallet journal table structure
        $journalModel = new \Seat\Eveapi\Models\Wallet\CorporationWalletJournal;
        $table = $journalModel->getTable();
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing($table);
        $diagnostics[] = "Wallet table: <code>{$table}</code>";
        $diagnostics[] = "Columns: <code>" . implode(', ', $columns) . "</code>";

        $hasRefType = in_array('ref_type', $columns);
        $hasRefTypeId = in_array('ref_type_id', $columns);
        $diagnostics[] = "Has ref_type: " . ($hasRefType ? '✅' : '❌') . " | Has ref_type_id: " . ($hasRefTypeId ? '✅' : '❌');

        // Check how many journal entries exist for this corp
        $since = \Carbon\Carbon::now()->subDays(30);
        $totalForCorp = \Illuminate\Support\Facades\DB::table($table)
            ->where('corporation_id', $taxCorpId)
            ->count();
        $recentForCorp = \Illuminate\Support\Facades\DB::table($table)
            ->where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->count();
        $positiveRecent = \Illuminate\Support\Facades\DB::table($table)
            ->where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->where('amount', '>', 0)
            ->count();
        $diagnostics[] = "Journal entries for corp: total=<strong>{$totalForCorp}</strong> last30d=<strong>{$recentForCorp}</strong> positive(last30d)=<strong>{$positiveRecent}</strong>";

        // If NO entries for corp, check if table has data at all
        if ($totalForCorp === 0) {
            $totalAny = \Illuminate\Support\Facades\DB::table($table)->count();
            $diagnostics[] = "⚠️ <strong>Zero entries found for corp {$taxCorpId}!</strong> Total entries in table: {$totalAny}";
            
            // Show distinct corp IDs in the table
            $corpIds = \Illuminate\Support\Facades\DB::table($table)
                ->select('corporation_id')
                ->distinct()
                ->take(10)
                ->pluck('corporation_id')
                ->toArray();
            $diagnostics[] = "Corp IDs in table: <code>" . implode(', ', $corpIds) . "</code>";
        }

        // Show sample incoming transactions
        $sampleTx = \Illuminate\Support\Facades\DB::table($table)
            ->where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->where('amount', '>', 0)
            ->orderBy('date', 'desc')
            ->take(10)
            ->get();

        if ($sampleTx->isNotEmpty()) {
            $diagnostics[] = "<br><strong>📋 Recent incoming transactions (up to 10):</strong>";
            foreach ($sampleTx as $tx) {
                $refType = $hasRefType ? ($tx->ref_type ?? 'NULL') : ($hasRefTypeId ? ($tx->ref_type_id ?? 'NULL') : 'N/A');
                $diagnostics[] = "&nbsp;&nbsp;• [{$tx->date}] ref_id:<code>{$tx->ref_id}</code> type:<code>{$refType}</code> amount:<code>" 
                    . number_format((float)$tx->amount, 2) . "</code> 1st:<code>{$tx->first_party_id}</code> 2nd:<code>{$tx->second_party_id}</code>";
            }
        } else {
            $diagnostics[] = "⚠️ <strong>No positive-amount transactions found for corp {$taxCorpId} in the last 30 days!</strong>";
        }

        // Show unpaid invoices with character matching info
        $unpaidInvoices = AllianceTaxInvoice::where('status', '!=', 'paid')
            ->with('character')
            ->get();

        $usedRefIds = AllianceTaxInvoice::whereNotNull('payment_ref_id')
            ->pluck('payment_ref_id')
            ->toArray();

        $diagnostics[] = "<br><strong>📄 Unpaid invoices: {$unpaidInvoices->count()}</strong> | Already-used ref_ids: " . count($usedRefIds);

        foreach ($unpaidInvoices->take(10) as $invoice) {
            $charName = optional($invoice->character)->name ?? 'Unknown';
            $invoiceAmount = (float) $invoice->amount;

            // Find user's characters
            $userId = \Illuminate\Support\Facades\DB::table('refresh_tokens')
                ->where('character_id', $invoice->character_id)
                ->value('user_id');
            
            if ($userId) {
                $userCharacterIds = \Illuminate\Support\Facades\DB::table('refresh_tokens')
                    ->where('user_id', $userId)
                    ->pluck('character_id')
                    ->map(function($id) { return (int)$id; })
                    ->toArray();
            } else {
                $userCharacterIds = [(int)$invoice->character_id];
                $diagnostics[] = "&nbsp;&nbsp;⚠️ No refresh_token for char {$invoice->character_id}!";
            }

            $charIdList = implode(', ', $userCharacterIds);
            $comparisonDate = \Carbon\Carbon::parse($invoice->created_at)->subMinutes(5);

            $diagnostics[] = "&nbsp;&nbsp;📌 <strong>{$charName}</strong> (ID:{$invoice->character_id}) — " 
                . number_format($invoiceAmount, 2) . " ISK — Created: {$invoice->created_at} — Owner chars: <code>[{$charIdList}]</code>";

            // Check each sample tx against this invoice
            if ($sampleTx->isNotEmpty()) {
                $foundCandidate = false;
                foreach ($sampleTx as $tx) {
                    $isUsed = in_array($tx->ref_id, $usedRefIds);
                    $firstParty = (int) $tx->first_party_id;
                    $secondParty = (int) $tx->second_party_id;
                    $txAmount = (float) $tx->amount;
                    $charMatch = in_array($firstParty, $userCharacterIds, true) || in_array($secondParty, $userCharacterIds, true);
                    $amountOk = $txAmount >= $invoiceAmount;
                    $dateOk = \Carbon\Carbon::parse($tx->date) >= $comparisonDate;

                    // Only show if it's at least a partial match
                    if ($charMatch || ($amountOk && !$isUsed)) {
                        $foundCandidate = true;
                        $flags = [];
                        if ($isUsed) $flags[] = "used:❌";
                        $flags[] = "char:" . ($charMatch ? '✅' : "❌({$firstParty},{$secondParty})");
                        $flags[] = "amt:" . ($amountOk ? '✅' : "❌(" . number_format($txAmount,2) . "<" . number_format($invoiceAmount,2) . ")");
                        $flags[] = "date:" . ($dateOk ? '✅' : "❌({$tx->date}<{$comparisonDate})");
                        $allOk = $charMatch && $amountOk && $dateOk && !$isUsed;
                        $icon = $allOk ? '✅' : '❌';
                        $diagnostics[] = "&nbsp;&nbsp;&nbsp;&nbsp;{$icon} ref:<code>{$tx->ref_id}</code> " . implode(' | ', $flags);
                    }
                }
                if (!$foundCandidate) {
                    $diagnostics[] = "&nbsp;&nbsp;&nbsp;&nbsp;↳ No candidate transactions found at all for this character";
                }
            }
        }

        // Now run the actual reconciliation
        $unpaidBefore = AllianceTaxInvoice::where('status', '!=', 'paid')->count();

        $job = new \Rejected\SeatAllianceTax\Jobs\ReconcilePaymentsJob();
        $job->handle();

        $unpaidAfter = AllianceTaxInvoice::where('status', '!=', 'paid')->count();
        $matched = $unpaidBefore - $unpaidAfter;

        $diagnostics[] = "<br><strong>📊 Result: Matched {$matched} invoice(s). Remaining unpaid: {$unpaidAfter}</strong>";

        $message = implode('<br>', $diagnostics);

        return redirect()->route('alliancetax.invoices.index')
            ->with($matched > 0 ? 'success' : 'info', $message);
    }
}

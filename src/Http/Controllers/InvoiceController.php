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

        // Detect the right ID/ref column name
        $refIdCol = in_array('ref_id', $columns) ? 'ref_id' : (in_array('id', $columns) ? 'id' : null);
        $diagnostics[] = "Ref ID column detected: <code>" . ($refIdCol ?? 'NONE') . "</code>";

        $hasRefType = in_array('ref_type', $columns);
        $diagnostics[] = "Has ref_type column: " . ($hasRefType ? '✅' : '❌');

        // Check how many journal entries exist for this corp
        $since = \Carbon\Carbon::now()->subDays(30);
        $totalForCorp = \Illuminate\Support\Facades\DB::table($table)
            ->where('corporation_id', $taxCorpId)
            ->count();
        $positiveRecent = \Illuminate\Support\Facades\DB::table($table)
            ->where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->where('amount', '>', 0)
            ->count();
        $diagnostics[] = "Journal entries: total=<strong>{$totalForCorp}</strong> positive(last30d)=<strong>{$positiveRecent}</strong>";

        // If NO entries for corp, check what corps exist
        if ($totalForCorp === 0) {
            $totalAny = \Illuminate\Support\Facades\DB::table($table)->count();
            $corpIds = \Illuminate\Support\Facades\DB::table($table)
                ->select('corporation_id')
                ->distinct()
                ->take(10)
                ->pluck('corporation_id')
                ->toArray();
            $diagnostics[] = "⚠️ <strong>Zero entries for corp {$taxCorpId}!</strong> Total rows: {$totalAny}. Corps in table: <code>" . implode(', ', $corpIds) . "</code>";
        }

        // Show sample incoming transactions - dump ALL properties of first one
        $sampleTx = \Illuminate\Support\Facades\DB::table($table)
            ->where('corporation_id', $taxCorpId)
            ->where('date', '>=', $since)
            ->where('amount', '>', 0)
            ->orderBy('date', 'desc')
            ->take(10)
            ->get();

        if ($sampleTx->isNotEmpty()) {
            // Dump the FULL first row so we can see all column names and values
            $firstRow = (array) $sampleTx->first();
            $diagnostics[] = "<br><strong>📋 First transaction (full dump):</strong>";
            foreach ($firstRow as $col => $val) {
                $displayVal = is_null($val) ? '<em>NULL</em>' : htmlspecialchars(substr((string)$val, 0, 80));
                $diagnostics[] = "&nbsp;&nbsp;<code>{$col}</code> = <code>{$displayVal}</code>";
            }

            $diagnostics[] = "<br><strong>📋 Recent incoming transactions ({$sampleTx->count()}):</strong>";
            foreach ($sampleTx as $tx) {
                $row = (array) $tx;
                $txId = $row[$refIdCol] ?? $row['id'] ?? 'N/A';
                $refType = $row['ref_type'] ?? $row['ref_type_id'] ?? 'N/A';
                $amount = number_format((float)($row['amount'] ?? 0), 2);
                $party1 = $row['first_party_id'] ?? 'N/A';
                $party2 = $row['second_party_id'] ?? 'N/A';
                $date = $row['date'] ?? 'N/A';
                $diagnostics[] = "&nbsp;&nbsp;• [{$date}] id:<code>{$txId}</code> type:<code>{$refType}</code> amt:<code>{$amount}</code> 1st:<code>{$party1}</code> 2nd:<code>{$party2}</code>";
            }
        } else {
            $diagnostics[] = "⚠️ <strong>No positive-amount transactions found for corp {$taxCorpId} in the last 30 days!</strong>";
        }

        // Show unpaid invoices
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
                . number_format($invoiceAmount, 2) . " ISK — Created: {$invoice->created_at} — Chars: <code>[{$charIdList}]</code>";

            // Try matching against transactions
            if ($sampleTx->isNotEmpty()) {
                $foundCandidate = false;
                foreach ($sampleTx as $tx) {
                    $row = (array) $tx;
                    $txRefId = $row[$refIdCol] ?? $row['id'] ?? null;
                    $isUsed = in_array($txRefId, $usedRefIds);
                    $firstParty = (int) ($row['first_party_id'] ?? 0);
                    $secondParty = (int) ($row['second_party_id'] ?? 0);
                    $txAmount = (float) ($row['amount'] ?? 0);
                    $charMatch = in_array($firstParty, $userCharacterIds, true) || in_array($secondParty, $userCharacterIds, true);
                    $amountOk = $txAmount >= $invoiceAmount;
                    $dateOk = \Carbon\Carbon::parse($row['date'] ?? '2000-01-01') >= $comparisonDate;

                    if ($charMatch || ($amountOk && !$isUsed)) {
                        $foundCandidate = true;
                        $flags = [];
                        if ($isUsed) $flags[] = "used:❌";
                        $flags[] = "char:" . ($charMatch ? '✅' : "❌({$firstParty},{$secondParty})");
                        $flags[] = "amt:" . ($amountOk ? '✅' : "❌(" . number_format($txAmount,2) . "<" . number_format($invoiceAmount,2) . ")");
                        $flags[] = "date:" . ($dateOk ? '✅' : "❌");
                        $allOk = $charMatch && $amountOk && $dateOk && !$isUsed;
                        $icon = $allOk ? '✅' : '❌';
                        $diagnostics[] = "&nbsp;&nbsp;&nbsp;&nbsp;{$icon} id:<code>{$txRefId}</code> " . implode(' | ', $flags);
                    }
                }
                if (!$foundCandidate) {
                    $diagnostics[] = "&nbsp;&nbsp;&nbsp;&nbsp;↳ No matching transactions for this character";
                }
            }
        }

        // Skip running the actual reconcile job for now since it has the same ref_id bug
        $diagnostics[] = "<br><strong>📊 Reconciliation skipped — diagnostics only. Fix will be applied based on column names above.</strong>";

        $message = implode('<br>', $diagnostics);

        return redirect()->route('alliancetax.invoices.index')
            ->with('info', $message);
    }
}

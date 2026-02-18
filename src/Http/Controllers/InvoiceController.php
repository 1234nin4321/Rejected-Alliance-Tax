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

        $unpaidBefore = AllianceTaxInvoice::where('status', '!=', 'paid')->count();

        $job = new \Rejected\SeatAllianceTax\Jobs\ReconcilePaymentsJob();
        $job->handle();

        $unpaidAfter = AllianceTaxInvoice::where('status', '!=', 'paid')->count();
        $matched = $unpaidBefore - $unpaidAfter;

        if ($matched > 0) {
            return redirect()->route('alliancetax.invoices.index')
                ->with('success', "Payment reconciliation complete! Matched {$matched} invoice(s) to wallet transactions.");
        }

        return redirect()->route('alliancetax.invoices.index')
            ->with('info', 'Payment reconciliation complete. No new payment matches found.');
    }
}


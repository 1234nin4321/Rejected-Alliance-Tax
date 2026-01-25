<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rejected\SeatAllianceTax\Models\AllianceCorpRattingTaxSetting;
use Rejected\SeatAllianceTax\Models\AllianceCorpRattingTaxCalculation;
use Rejected\SeatAllianceTax\Services\CorpRattingTaxService;
use Seat\Eveapi\Models\Corporation\CorporationInfo;

class CorpRattingTaxController extends Controller
{
    protected $service;

    public function __construct(CorpRattingTaxService $service)
    {
        $this->service = $service;
    }

    /**
     * Display corporate ratting tax management page
     */
    public function index()
    {
        try {
            $calculations = AllianceCorpRattingTaxCalculation::with('corporation')
                ->orderBy('created_at', 'desc')
                ->get();

            $settings = AllianceCorpRattingTaxSetting::with('corporation')
                ->orderBy('tax_rate', 'desc')
                ->get();
            
            return view('alliancetax::corptax.index', compact('calculations', 'settings'));
        } catch (\Exception $e) {
            \Log::error('Corp Tax Index Failed: ' . $e->getMessage());
            
            // Return empty collections so the view doesn't crash on foreach
            $calculations = collect();
            $settings = collect();
            
            return view('alliancetax::corptax.index', compact('calculations', 'settings'))
                ->with('error', 'Database Error: ' . $e->getMessage() . '. Please ensure you have run the migrations.');
        }
    }

    /**
     * Update or create tax setting for a corporation
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'corporation_id' => 'required|integer',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'min_threshold' => 'nullable|numeric|min:0',
        ]);

        AllianceCorpRattingTaxSetting::updateOrCreate(
            ['corporation_id' => $request->corporation_id],
            [
                'tax_rate' => $request->tax_rate,
                'min_threshold' => $request->min_threshold ?? 0,
                'is_active' => $request->has('is_active') || $request->is_active === 'on' || $request->is_active === true,
            ]
        );

        return redirect()->route('alliancetax.corptax.index')
            ->with('success', 'Corporate ratting tax rate updated.');
    }

    /**
     * Generate corporate ratting invoices for a period
     */
    public function generate(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
        ]);

        try {
            $corps = AllianceCorpRattingTaxSetting::where('is_active', true)->pluck('corporation_id');
            $generated = 0;

            foreach ($corps as $corpId) {
                // Check if calculation already exists for this period to avoid duplicates
                $exists = AllianceCorpRattingTaxCalculation::where('corporation_id', $corpId)
                    ->where('period_start', $request->period_start)
                    ->where('period_end', $request->period_end)
                    ->where('status', '!=', 'cancelled')
                    ->exists();

                if ($exists) {
                    continue;
                }

                $calc = $this->service->calculateForCorp(
                    $corpId,
                    $request->period_start,
                    $request->period_end
                );

                if ($calc) {
                    $generated++;
                }
            }

            return redirect()->route('alliancetax.corptax.index')
                ->with('success', "Processed corporate tax. Generated {$generated} new invoices.");

        } catch (\Exception $e) {
            \Log::error('Corp Tax Generation Failed: ' . $e->getMessage());
            return redirect()->route('alliancetax.corptax.index')
                ->with('error', 'Generation failed: ' . $e->getMessage());
        }
    }

    /**
     * Mark an invoice as paid
     */
    public function markPaid($id)
    {
        try {
            $calc = AllianceCorpRattingTaxCalculation::findOrFail($id);
            $calc->update([
                'status' => 'paid',
                'paid_at' => \Carbon\Carbon::now(),
            ]);

            return redirect()->route('alliancetax.corptax.index')
                ->with('success', 'Corporate invoice marked as paid.');
        } catch (\Exception $e) {
            \Log::error('Corp Tax Settlement Failed: ' . $e->getMessage());
            return redirect()->route('alliancetax.corptax.index')
                ->with('error', 'Settlement failed: ' . $e->getMessage());
        }
    }

    /**
     * Delete a tax setting for a corporation
     */
    public function destroySettings($id)
    {
        AllianceCorpRattingTaxSetting::where('corporation_id', $id)->delete();
        
        return redirect()->route('alliancetax.corptax.index')
            ->with('success', 'Tax definition removed for this corporation.');
    }

    /**
     * Delete an invoice
     */
    public function destroy($id)
    {
        AllianceCorpRattingTaxCalculation::findOrFail($id)->delete();
        
        return redirect()->route('alliancetax.corptax.index')
            ->with('success', 'Invoice record deleted.');
    }
}

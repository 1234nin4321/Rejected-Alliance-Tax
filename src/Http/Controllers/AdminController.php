<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;
use Rejected\SeatAllianceTax\Models\AllianceTaxRate;
use Rejected\SeatAllianceTax\Models\AllianceTaxExemption;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
use Rejected\SeatAllianceTax\Models\AllianceTaxSystem;
use Rejected\SeatAllianceTax\Jobs\CalculateAllianceTaxes;

class AdminController extends Controller
{
    /**
     * Display tax rates management page.
     *
     * @return \Illuminate\View\View
     */
    public function rates()
    {
        $rates = AllianceTaxRate::orderBy('created_at', 'desc')->get();
        
        return view('alliancetax::admin.rates', compact('rates'));
    }

    /**
     * Store a new tax rate.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeRate(Request $request)
    {
        $request->validate([
            'rate_type' => 'required|in:alliance,corporation',
            'alliance_id' => 'nullable|integer',
            'corporation_id' => 'nullable|integer',
            'tax_rate' => 'required|numeric|min:0|max:100',
            'item_category' => 'required|in:all,ore,ice,moon_r4,moon_r8,moon_r16,moon_r32,moon_r64,gas',
            'effective_from' => 'required|date',
            'effective_until' => 'nullable|date|after:effective_from',
        ]);

        // Get alliance_id from settings if not provided
        $allianceId = $request->alliance_id;
        if ($request->rate_type === 'alliance' && !$allianceId) {
            $allianceId = AllianceTaxSetting::get('alliance_id');
        }

        AllianceTaxRate::create([
            'alliance_id' => $request->rate_type === 'alliance' ? $allianceId : null,
            'corporation_id' => $request->rate_type === 'corporation' ? $request->corporation_id : null,
            'tax_rate' => $request->tax_rate,
            'item_category' => $request->item_category,
            'effective_from' => $request->effective_from,
            'effective_until' => $request->effective_until,
            'is_active' => true,
        ]);

        return redirect()->route('alliancetax.admin.rates.index')
            ->with('success', 'Tax rate created successfully');
    }

    /**
     * Update an existing tax rate.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateRate(Request $request, $id)
    {
        $rate = AllianceTaxRate::findOrFail($id);

        $request->validate([
            'tax_rate' => 'required|numeric|min:0|max:100',
        ]);

        $rate->update([
            'tax_rate' => $request->tax_rate,
            'is_active' => $request->has('is_active') ? true : false,
        ]);

        return redirect()->route('alliancetax.admin.rates.index')
            ->with('success', 'Tax rate updated successfully');
    }

    /**
     * Delete a tax rate.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyRate($id)
    {
        AllianceTaxRate::findOrFail($id)->delete();

        return redirect()->route('alliancetax.admin.rates.index')
            ->with('success', 'Tax rate deleted successfully');
    }

    /**
     * Display exemptions management page.
     *
     * @return \Illuminate\View\View
     */
    public function exemptions()
    {
        $exemptions = AllianceTaxExemption::with(['character', 'corporation'])
            ->orderBy('created_at', 'desc')
            ->get();
        
        return view('alliancetax::admin.exemptions', compact('exemptions'));
    }

    /**
     * Store a new exemption.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeExemption(Request $request)
    {
        $request->validate([
            'exempt_type' => 'required|in:character,corporation',
            'character_id' => 'required_if:exempt_type,character|nullable|integer',
            'corporation_id' => 'required_if:exempt_type,corporation|nullable|integer',
            'reason' => 'required|string|max:500',
            'exempt_from' => 'required|date',
            'exempt_until' => 'nullable|date|after:exempt_from',
        ]);

        AllianceTaxExemption::create([
            'character_id' => $request->exempt_type === 'character' ? $request->character_id : null,
            'corporation_id' => $request->exempt_type === 'corporation' ? $request->corporation_id : null,
            'reason' => $request->reason,
            'exempt_from' => $request->exempt_from,
            'exempt_until' => $request->exempt_until,
            'is_active' => true,
            'created_by' => auth()->user()->id,
        ]);

        return redirect()->route('alliancetax.admin.exemptions.index')
            ->with('success', 'Exemption created successfully');
    }

    /**
     * Delete an exemption.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyExemption($id)
    {
        AllianceTaxExemption::findOrFail($id)->delete();

        return redirect()->route('alliancetax.admin.exemptions.index')
            ->with('success', 'Exemption removed successfully');
    }

    /**
     * Display settings page.
     *
     * @return \Illuminate\View\View
     */
    public function settings()
    {
        $settings = AllianceTaxSetting::getAll();
        $customRatesCount = AllianceTaxRate::where('is_active', true)->count();
        $activeExemptionsCount = AllianceTaxExemption::active()->count();
        $taxedSystems = AllianceTaxSystem::orderBy('solar_system_name')->get();
        
        // Check if mail sender has required scope
        $mailSenderHasScope = false;
        $mailSenderCharacter = null;
        if (isset($settings['mail_sender_character_id']) && $settings['mail_sender_character_id']) {
            $token = \Seat\Eveapi\Models\RefreshToken::where('character_id', $settings['mail_sender_character_id'])->first();
            if ($token) {
                $scopes = is_array($token->scopes) ? $token->scopes : json_decode($token->scopes, true);
                $mailSenderHasScope = in_array('esi-mail.send_mail.v1', $scopes ?? []);
                $mailSenderCharacter = \Seat\Eveapi\Models\Character\CharacterInfo::where('character_id', $settings['mail_sender_character_id'])->first();
            }
        }
        
        return view('alliancetax::admin.settings', compact(
            'settings', 
            'customRatesCount', 
            'activeExemptionsCount',
            'mailSenderHasScope',
            'mailSenderCharacter',
            'taxedSystems'
        ));
    }

    /**
     * Store a new taxed system.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function storeTaxedSystem(Request $request)
    {
        $request->validate([
            'solar_system_id' => 'required|integer',
            'solar_system_name' => 'required|string',
        ]);

        AllianceTaxSystem::updateOrCreate(
            ['solar_system_id' => $request->solar_system_id],
            ['solar_system_name' => $request->solar_system_name]
        );

        return redirect()->route('alliancetax.admin.settings')
            ->with('success', 'Solar system added to taxed systems.');
    }

    /**
     * Delete a taxed system.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroyTaxedSystem($id)
    {
        AllianceTaxSystem::findOrFail($id)->delete();

        return redirect()->route('alliancetax.admin.settings')
            ->with('success', 'Solar system removed from taxed systems.');
    }

    /**
     * Update settings.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'alliance_id' => 'nullable|integer',
            'default_tax_rate' => 'required|numeric|min:0|max:100',
            'tax_period' => 'required|in:weekly,monthly',
            'minimum_taxable_amount' => 'required|integer|min:0',
            'auto_calculate' => 'boolean',
            'tax_collection_corporation_id' => 'nullable|integer',
            'mail_sender_character_id' => 'nullable|integer',
            'mail_sender_character_name' => 'nullable|string',
            'tax_collection_corporation_name' => 'nullable|string',
            'discord_webhook_url' => 'nullable|url',
        ]);

        // Update each setting
        AllianceTaxSetting::set('alliance_id', $request->alliance_id);
        AllianceTaxSetting::set('default_tax_rate', $request->default_tax_rate);
        AllianceTaxSetting::set('tax_period', $request->tax_period);
        AllianceTaxSetting::set('minimum_taxable_amount', $request->minimum_taxable_amount);
        AllianceTaxSetting::set('auto_calculate', $request->auto_calculate ? 'true' : 'false');
        AllianceTaxSetting::set('discord_webhook_url', $request->discord_webhook_url);
        
        // Tax collection corporation
        if ($request->tax_collection_corporation_id) {
            AllianceTaxSetting::set('tax_collection_corporation_id', $request->tax_collection_corporation_id);
            AllianceTaxSetting::set('tax_collection_corporation_name', $request->tax_collection_corporation_name ?? '');
        } else {
            AllianceTaxSetting::set('tax_collection_corporation_id', null);
            AllianceTaxSetting::set('tax_collection_corporation_name', null);
        }
        
        // Mail sender character
        if ($request->mail_sender_character_id) {
            AllianceTaxSetting::set('mail_sender_character_id', $request->mail_sender_character_id);
            AllianceTaxSetting::set('mail_sender_character_name', $request->mail_sender_character_name ?? '');
        } else {
            AllianceTaxSetting::set('mail_sender_character_id', null);
            AllianceTaxSetting::set('mail_sender_character_name', null);
        }

        $successMessage = 'Settings updated successfully';
        if ($request->mail_sender_character_id) {
            $savedName = AllianceTaxSetting::get('mail_sender_character_name');
            $submittedName = $request->mail_sender_character_name;
            $successMessage .= " - Submitted: '{$submittedName}' | Saved: '{$savedName}' | ID: {$request->mail_sender_character_id}";
        }

        return redirect()->route('alliancetax.admin.settings')
            ->with('success', $successMessage);
    }

    /**
     * Trigger manual tax recalculation.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function recalculate(Request $request)
    {
        $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after:period_start',
        ]);

        // Determine period type
        $periodType = AllianceTaxSetting::get('tax_period', 'weekly');

        // Dispatch job to recalculate taxes
        CalculateAllianceTaxes::dispatch(
            $request->period_start,
            $request->period_end,
            $periodType
        );

        return redirect()->back()
            ->with('success', 'Tax recalculation job has been queued. Results will appear shortly.');
    }

    /**
     * Delete a tax calculation
     */
    public function destroyCalculation($id)
    {
        $calc = AllianceTaxCalculation::findOrFail($id);
        
        // Check if there's an invoice linked to this
        $invoice = \Rejected\SeatAllianceTax\Models\AllianceTaxInvoice::where('tax_calculation_id', $id)->first();
        if ($invoice) {
            $invoice->delete();
        }

        $calc->delete();

        return redirect()->back()
            ->with('success', 'Tax calculation record removed successfully.');
    }
}

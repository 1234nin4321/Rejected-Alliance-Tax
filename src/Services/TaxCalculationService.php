<?php

namespace Rejected\SeatAllianceTax\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Rejected\SeatAllianceTax\Models\AllianceMiningActivity;
use Rejected\SeatAllianceTax\Models\AllianceTaxCalculation;
use Rejected\SeatAllianceTax\Models\AllianceTaxRate;
use Rejected\SeatAllianceTax\Models\AllianceTaxExemption;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
use Rejected\SeatAllianceTax\Models\AllianceTaxSystem;
use Rejected\SeatAllianceTax\Services\JitaPriceService;
use Rejected\SeatAllianceTax\Services\CompressedOreMappingService;

class TaxCalculationService
{
    /**
     * Import mining data from SeAT and calculate taxes for a period.
     *
     * @param int $allianceId
     * @param Carbon $periodStart
     * @param Carbon $periodEnd
     * @param object|null $output Command output for progress bars
     * @return array
     */
    public function processPeriod($allianceId, Carbon $periodStart, Carbon $periodEnd, $output = null)
    {
        $imported = $this->importMiningData($allianceId, $periodStart, $periodEnd, $output);
        $calculated = $this->calculateTaxes($allianceId, $periodStart, $periodEnd, $output);

        return [
            'imported' => $imported,
            'calculated' => $calculated,
        ];
    }

    /**
     * Import mining data from SeAT's character_minings table
     */
    public function importMiningData($allianceId, $periodStart, $periodEnd, $output = null)
    {
        $imported = 0;

        $allianceCharacters = DB::table('character_affiliations')
            ->where('alliance_id', $allianceId)
            ->pluck('character_id');

        if ($allianceCharacters->isEmpty()) {
            return 0;
        }

        $miningRecords = DB::table('character_minings')
            ->whereIn('character_id', $allianceCharacters)
            ->whereBetween('date', [$periodStart, $periodEnd])
            ->get();

        if ($miningRecords->isEmpty()) {
            return 0;
        }

        $bar = null;
        if ($output) {
            $bar = $output->createProgressBar($miningRecords->count());
            $bar->start();
        }

        foreach ($miningRecords as $record) {
            $affiliation = DB::table('character_affiliations')
                ->where('character_id', $record->character_id)
                ->first();

            if (!$affiliation) {
                if ($bar) $bar->advance();
                continue;
            }

            $exists = AllianceMiningActivity::where('character_id', $record->character_id)
                ->where('type_id', $record->type_id)
                ->where('mining_date', $record->date)
                ->exists();

            if ($exists) {
                if ($bar) $bar->advance();
                continue;
            }

            $typeName = DB::table('invTypes')
                ->where('typeID', $record->type_id)
                ->value('typeName') ?? 'Unknown';

            $estimatedValue = 0;
            try {
                $estimatedValue = $record->quantity * $this->getOrePrice($record->type_id);
            } catch (\Exception $e) {
                \Log::warning("Failed to get price for type {$record->type_id}: " . $e->getMessage());
            }

            AllianceMiningActivity::create([
                'character_id' => $record->character_id,
                'corporation_id' => $affiliation->corporation_id,
                'alliance_id' => $allianceId,
                'type_id' => $record->type_id,
                'type_name' => $typeName,
                'quantity' => $record->quantity,
                'mining_date' => $record->date,
                'estimated_value' => $estimatedValue,
                'solar_system_id' => $record->solar_system_id ?? null,
            ]);

            $imported++;
            if ($bar) $bar->advance();
        }

        if ($bar) {
            $bar->finish();
        }

        return $imported;
    }

    /**
     * Calculate taxes for the period
     */
    public function calculateTaxes($allianceId, $periodStart, $periodEnd, $output = null)
    {
        $calculated = 0;

        // Get all characters that mined and their associated SeAT user
        $userMiningData = DB::table('alliance_mining_activity')
            ->join('refresh_tokens', 'alliance_mining_activity.character_id', '=', 'refresh_tokens.character_id')
            ->whereBetween('mining_date', [$periodStart, $periodEnd])
            ->select('refresh_tokens.user_id')
            ->distinct()
            ->get();

        if ($userMiningData->isEmpty()) {
            return 0;
        }

        $bar = null;
        if ($output) {
            $bar = $output->createProgressBar($userMiningData->count());
            $bar->start();
        }

        foreach ($userMiningData as $userData) {
            $userId = $userData->user_id;
            
            // Find main character for this user
            $userRecord = DB::table('users')->where('id', $userId)->first();
            $mainCharacterId = $userRecord->main_character_id ?? null;
            
            // If no main set, get the first character owned by this user
            if (!$mainCharacterId) {
                $mainCharacterId = DB::table('refresh_tokens')
                    ->where('user_id', $userId)
                    ->value('character_id');
            }

            if (!$mainCharacterId) {
                if ($bar) $bar->advance();
                continue;
            }

            // Get all characters for this user
            $userCharacterIds = DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->pluck('character_id');

            // Collect all activities for all characters of this user
            $activities = AllianceMiningActivity::whereIn('character_id', $userCharacterIds)
                ->whereBetween('mining_date', [$periodStart, $periodEnd])
                ->get();

            // Filter activities by solar system if system restrictions are set
            $taxedSystemIds = AllianceTaxSystem::pluck('solar_system_id')->toArray();
            if (!empty($taxedSystemIds)) {
                $activities = $activities->filter(function($activity) use ($taxedSystemIds) {
                    return in_array($activity->solar_system_id, $taxedSystemIds);
                });
            }

            if ($activities->isEmpty()) {
                if ($bar) $bar->advance();
                continue;
            }

            $totalValue = 0;
            $totalTaxAmount = 0;
            $firstActivity = $activities->first();
            $targetCorpId = $firstActivity->corporation_id; // Default to first corp found

            foreach ($activities as $activity) {
                $category = $this->getItemCategory($activity->type_id);
                
                // Skip Gas mined in Wormholes (solar_system_id starting with 31)
                // Wormhole systems are in the range 31000000 - 31999999
                if ($category === 'gas' && $activity->solar_system_id >= 31000000 && $activity->solar_system_id < 32000000) {
                    continue;
                }

                // We use the activity's actual corp for rate check
                $taxRate = $this->getTaxRate($activity->corporation_id, $allianceId, $periodStart, $category);
                
                $totalValue += $activity->estimated_value;
                $totalTaxAmount += $activity->estimated_value * ($taxRate / 100);
            }

            // Check if main character or any of alts are exempt? 
            // Usually we check main character or specific characters.
            // For consolidation, let's check if the main character is exempt.
            if ($this->isExempt($mainCharacterId, $targetCorpId, $periodStart)) {
                if ($bar) $bar->advance();
                continue;
            }

            $effectiveRate = $totalValue > 0 ? ($totalTaxAmount / $totalValue) * 100 : 0;
            $periodType = AllianceTaxSetting::get('tax_period', 'weekly');
            
            // Read current credit balance for display/record purposes only
            // Credits are managed authoritatively by CreditRecalculationService
            // We do NOT deduct from the balance here to prevent double-deduction on recalculation
            $balanceModel = \Rejected\SeatAllianceTax\Models\AllianceTaxBalance::firstOrCreate(['character_id' => $mainCharacterId]);
            $creditToApply = 0;
            $finalTaxAmount = $totalTaxAmount;
            
            if ($balanceModel->balance > 0) {
                $creditToApply = min($balanceModel->balance, $totalTaxAmount);
                $finalTaxAmount = $totalTaxAmount - $creditToApply;
                // Note: balance is NOT deducted here — it will be recalculated
                // from source of truth (total sent vs total invoiced) after reconciliation
            }

            // Check if record exists and its current status
            $existing = DB::table('alliance_tax_calculations')
                ->where('character_id', $mainCharacterId)
                ->where('tax_period', $periodStart->format('Y-m-d'))
                ->first();

            // If already paid, don't overwrite the status or reset quantities
            $status = 'pending';
            $isPaid = false;
            $paidAt = null;

            if ($existing && ($existing->status === 'paid' || $existing->is_paid)) {
                $status = 'paid';
                $isPaid = true;
                $paidAt = $existing->paid_at;
            }

            DB::table('alliance_tax_calculations')->updateOrInsert(
                [
                    'character_id' => $mainCharacterId,
                    'tax_period' => $periodStart->format('Y-m-d'),
                ],
                [
                    'corporation_id' => $targetCorpId,
                    'alliance_id' => $allianceId,
                    'period_type' => $periodType,
                    'period_start' => $periodStart->format('Y-m-d'),
                    'period_end' => $periodEnd->format('Y-m-d'),
                    'total_mined_value' => $totalValue,
                    'tax_rate' => $effectiveRate,
                    'applicable_tax_rate' => $effectiveRate,
                    'tax_amount' => $finalTaxAmount,
                    'tax_amount_gross' => $totalTaxAmount,
                    'credit_applied' => $creditToApply,
                    'status' => $status,
                    'is_paid' => $isPaid,
                    'paid_at' => $paidAt,
                    'calculated_at' => now(),
                    'updated_at' => now(),
                    'created_at' => $existing->created_at ?? now(),
                ]
            );

            $calculated++;
            if ($bar) $bar->advance();
        }

        if ($bar) {
            $bar->finish();
        }

        return $calculated;
    }

    protected function isExempt($characterId, $corporationId, $date)
    {
        return AllianceTaxExemption::where(function($query) use ($characterId, $corporationId) {
                $query->where('character_id', $characterId)
                      ->orWhere('corporation_id', $corporationId);
            })
            ->where('is_active', true)
            ->where('exempt_from', '<=', $date)
            ->where(function($query) use ($date) {
                $query->whereNull('exempt_until')
                      ->orWhere('exempt_until', '>=', $date);
            })
            ->exists();
    }

    protected function getItemCategory($typeId)
    {
        $groupInfo = DB::table('invTypes')
            ->join('invGroups', 'invTypes.groupID', '=', 'invGroups.groupID')
            ->where('invTypes.typeID', $typeId)
            ->select('invGroups.groupID', 'invGroups.categoryID')
            ->first();

        if (!$groupInfo) {
            return 'ore';
        }

        // Gas (Groups: 490: Mykoserocin, 496: Cytoserocin, 711: Gas Clouds/Fullerite)
        if (in_array($groupInfo->groupID, [490, 496, 711])) {
            return 'gas';
        }

        if ($groupInfo->categoryID != 25) {
            return 'ore';
        }

        if ($groupInfo->groupID == 465) {
            return 'ice';
        }

        switch ($groupInfo->groupID) {
            case 1884: return 'moon_r4';
            case 1920: return 'moon_r8';
            case 1921: return 'moon_r16';
            case 1922: return 'moon_r32';
            case 1923: return 'moon_r64';
        }

        return 'ore';
    }

    protected function getTaxRate($corporationId, $allianceId, $date, $category = 'all')
    {
        // Category specific corp rate
        $rate = AllianceTaxRate::where('corporation_id', $corporationId)
            ->where('item_category', $category)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function($query) use ($date) {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            })->first();

        if ($rate) return $rate->tax_rate;

        // General corp rate
        $rate = AllianceTaxRate::where('corporation_id', $corporationId)
            ->where('item_category', 'all')
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function($query) use ($date) {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            })->first();

        if ($rate) return $rate->tax_rate;

        // Category specific alliance rate
        $rate = AllianceTaxRate::where('alliance_id', $allianceId)
            ->where('item_category', $category)
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function($query) use ($date) {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            })->first();

        if ($rate) return $rate->tax_rate;

        // General alliance rate
        $rate = AllianceTaxRate::where('alliance_id', $allianceId)
            ->where('item_category', 'all')
            ->where('is_active', true)
            ->where('effective_from', '<=', $date)
            ->where(function($query) use ($date) {
                $query->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            })->first();

        if ($rate) return $rate->tax_rate;

        return AllianceTaxSetting::get('default_tax_rate', 10.0);
    }

    protected function getOrePrice($typeId)
    {
        // Identify the compressed variant for this ore
        $compressedTypeId = CompressedOreMappingService::getCompressedTypeId($typeId);
        
        // Return the full Jita Split Price for that compressed variant (no math/division)
        return JitaPriceService::getSellPrice($compressedTypeId);
    }

    /**
     * Generate invoices for a calculation period
     */
    public function generateInvoices($periodStart, $periodEnd, $dueDays = 7)
    {
        $dueDate = now()->addDays($dueDays);

        // Get pending tax calculations for this period
        $calculations = AllianceTaxCalculation::where('status', 'pending')
            ->whereBetween('period_start', [$periodStart, $periodEnd])
            ->with(['character.user.main_character'])
            ->get();

        if ($calculations->isEmpty()) {
            return 0;
        }

        // Group by user (main character)
        $userTaxes = [];
        foreach ($calculations as $calc) {
            // Find user through character relationship
            $character = DB::table('character_infos')->where('character_id', $calc->character_id)->first();
            if (!$character) continue;

            $userId = DB::table('refresh_tokens')->where('character_id', $calc->character_id)->value('user_id');
            if (!$userId) continue;

            $user = \Seat\Web\Models\User::find($userId);
            if (!$user) continue;

            $mainCharacterId = $user->main_character_id ?? $calc->character_id;
            
            if (!isset($userTaxes[$userId])) {
                $userTaxes[$userId] = [
                    'main_character_id' => $mainCharacterId,
                    'total_amount' => 0,
                    'calculation_ids' => [],
                    'corporation_id' => $calc->corporation_id,
                ];
            }

            $userTaxes[$userId]['total_amount'] += $calc->tax_amount;
            $userTaxes[$userId]['calculation_ids'][] = $calc->id;
        }

        $generated = 0;
        $invoiceIds = [];
        
        foreach ($userTaxes as $userId => $userTax) {
            // Check if invoice already exists for this user this period
            $existingInvoice = \Rejected\SeatAllianceTax\Models\AllianceTaxInvoice::where('character_id', $userTax['main_character_id'])
                ->where('invoice_date', '>=', now()->startOfDay())
                ->first();

            if ($existingInvoice) continue;

            $invoiceAmount = floor($userTax['total_amount']);
            
            // Determine if credit fully covered this invoice
            $isCreditPaid = $invoiceAmount <= 0;
            
            // Still create the invoice for record-keeping, even if 0
            $invoice = \Rejected\SeatAllianceTax\Models\AllianceTaxInvoice::create([
                'tax_calculation_id' => $userTax['calculation_ids'][0],
                'character_id' => $userTax['main_character_id'],
                'corporation_id' => $userTax['corporation_id'],
                'amount' => max($invoiceAmount, 0),
                'invoice_date' => now(),
                'due_date' => $dueDate,
                'status' => $isCreditPaid ? 'paid' : 'sent',
                'paid_at' => $isCreditPaid ? now() : null,
                'invoice_note' => $isCreditPaid
                    ? "Consolidated mining tax ({$periodStart->format('M d')} - {$periodEnd->format('M d, Y')}) — Covered by tax credit"
                    : "Consolidated mining tax ({$periodStart->format('M d')} - {$periodEnd->format('M d, Y')})",
            ]);

            // Store metadata including period info
            $invoice->metadata = json_encode([
                'calculation_ids' => $userTax['calculation_ids'],
                'character_count' => count($userTax['calculation_ids']),
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'credit_paid' => $isCreditPaid,
            ]);
            $invoice->save();

            // Mark calculations as sent (or paid if credit covered it)
            AllianceTaxCalculation::whereIn('id', $userTax['calculation_ids'])
                ->update(['status' => $isCreditPaid ? 'paid' : 'sent']);

            $generated++;
            $invoiceIds[] = $invoice->id;
        }

        return [
            'count' => $generated,
            'invoice_ids' => $invoiceIds
        ];
    }

    /**
     * Notify users about invoices via Discord
     */
    public function notifyDiscord($invoiceCount)
    {
        if ($invoiceCount <= 0) return;

        $webhookUrl = AllianceTaxSetting::get('discord_webhook_url');
        if (!$webhookUrl) return;

        \Rejected\SeatAllianceTax\Jobs\SendDiscordTaxAnnouncement::dispatch($invoiceCount, config('app.url'))
            ->onQueue('high');
    }
}

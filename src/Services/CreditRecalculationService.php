<?php

namespace Rejected\SeatAllianceTax\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Rejected\SeatAllianceTax\Models\AllianceTaxInvoice;
use Rejected\SeatAllianceTax\Models\AllianceTaxBalance;
use Rejected\SeatAllianceTax\Models\AllianceTaxSetting;
use Seat\Eveapi\Models\Wallet\CorporationWalletJournal;

/**
 * Authoritatively recalculates tax credit balances from the source of truth:
 *   Credit = Total ISK sent to tax corp - Total ISK invoiced
 *
 * This is the ONLY place that should set balance values.
 * No other code should increment/decrement AllianceTaxBalance directly.
 */
class CreditRecalculationService
{
    /**
     * Recalculate all credit balances from source of truth.
     *
     * @return array Summary of recalculation results
     */
    public static function recalculate(): array
    {
        $taxCorpId = (int) AllianceTaxSetting::get('tax_collection_corporation_id');
        if (!$taxCorpId) {
            Log::info('[AllianceTax] Credit recalculation skipped — no tax collection corp configured');
            return ['users_with_credit' => 0, 'total_credit' => 0];
        }

        // Detect ref_type column
        $journalTable = (new CorporationWalletJournal)->getTable();
        $columns = Schema::getColumnListing($journalTable);
        $refTypeColumn = in_array('ref_type', $columns) ? 'ref_type' : null;

        // Find all unique character_ids that have invoices
        $invoiceCharacters = AllianceTaxInvoice::select('character_id')
            ->distinct()
            ->pluck('character_id');

        $creditsByCharacter = [];
        $totalCreditsApplied = 0;

        foreach ($invoiceCharacters as $charId) {
            $userId = DB::table('refresh_tokens')->where('character_id', $charId)->value('user_id');
            if (!$userId) {
                continue;
            }

            // Get ALL characters for this user
            $userCharIds = DB::table('refresh_tokens')
                ->where('user_id', $userId)
                ->pluck('character_id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            // Skip if we already processed this user (via another character)
            $userKey = 'user_' . $userId;
            if (isset($creditsByCharacter[$userKey])) {
                continue;
            }

            // Get the main character
            $mainCharId = DB::table('users')->where('id', $userId)->value('main_character_id') ?? $charId;

            // 1. Calculate TOTAL invoiced for this user (original amounts)
            $invoices = AllianceTaxInvoice::whereIn('character_id', $userCharIds)->get();

            $totalInvoiced = 0;
            foreach ($invoices as $invoice) {
                $metadata = $invoice->metadata ? json_decode($invoice->metadata, true) : [];
                $appliedPayments = $metadata['applied_payments'] ?? [];

                // Reconstruct original invoice amount
                $partialTotal = 0;
                foreach ($appliedPayments as $p) {
                    $partialTotal += (float)($p['amount'] ?? 0);
                }

                // Original amount = current remaining + all partial payments applied
                $originalAmount = (float)$invoice->amount + $partialTotal;
                $totalInvoiced += $originalAmount;
            }

            // 2. Calculate TOTAL ISK sent by this user's characters to the tax corp
            $txQuery = DB::table($journalTable)
                ->where('corporation_id', $taxCorpId)
                ->where('amount', '>', 0)
                ->where(function ($q) use ($userCharIds) {
                    $q->whereIn('first_party_id', $userCharIds)
                      ->orWhereIn('second_party_id', $userCharIds);
                });

            if ($refTypeColumn) {
                $txQuery->whereIn($refTypeColumn, [
                    'player_donation',
                    'corporation_account_withdrawal',
                    'direct_transfer',
                    'cash_out',
                    'deposit',
                    'union_payment',
                ]);
            }

            $totalSent = (float) $txQuery->sum('amount');

            // 3. Credit = Total Sent - Total Invoiced (only if positive and > 1 ISK)
            $credit = $totalSent - $totalInvoiced;

            if ($credit > 1) {
                $creditsByCharacter[$userKey] = [
                    'character_id' => $mainCharId,
                    'credit' => floor($credit),
                ];
                $totalCreditsApplied += floor($credit);
            } else {
                $creditsByCharacter[$userKey] = [
                    'character_id' => $mainCharId,
                    'credit' => 0,
                ];
            }
        }

        // Reset ALL balances to 0, then apply calculated credits
        AllianceTaxBalance::query()->update(['balance' => 0]);

        foreach ($creditsByCharacter as $data) {
            if ($data['credit'] > 0) {
                $balance = AllianceTaxBalance::firstOrCreate(['character_id' => $data['character_id']]);
                $balance->balance = $data['credit'];
                $balance->save();
            }
        }

        $usersWithCredit = collect($creditsByCharacter)->filter(fn($d) => $d['credit'] > 0)->count();

        Log::info("[AllianceTax] Credit recalculation complete: {$usersWithCredit} users with credit, total: " . number_format($totalCreditsApplied, 0) . " ISK");

        return [
            'users_with_credit' => $usersWithCredit,
            'total_credit' => $totalCreditsApplied,
        ];
    }
}

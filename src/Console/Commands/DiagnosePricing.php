<?php

namespace Rejected\SeatAllianceTax\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Rejected\SeatAllianceTax\Services\JitaPriceService;

class DiagnosePricing extends Command
{
    protected $signature = 'alliance-tax:diagnose-pricing';
    protected $description = 'Diagnose pricing issues by listing all mined ores and their price status';

    public function handle()
    {
        $this->info('Alliance Tax - Price Diagnosis');
        $this->info('================================');
        $this->newLine();

        // Get all unique type IDs from mining activity
        $typeIds = DB::table('alliance_mining_activity')
            ->distinct()
            ->pluck('type_id')
            ->filter();

        if ($typeIds->isEmpty()) {
            $this->warn('No mining activity found in the database.');
            return;
        }

        $this->info("Found {$typeIds->count()} unique ore types in mining activity.");
        $this->newLine();

        $problemOres = [];
        $successfulOres = [];

        foreach ($typeIds as $typeId) {
            // Get ore name
            $oreName = DB::table('invTypes')
                ->where('typeID', $typeId)
                ->value('typeName') ?? "Unknown (ID: {$typeId})";

            // Try to get price
            $price = JitaPriceService::getSellPrice($typeId);

            if ($price == 0 || $price === null) {
                $problemOres[] = [
                    'id' => $typeId,
                    'name' => $oreName,
                    'price' => 0
                ];
            } else {
                $successfulOres[] = [
                    'id' => $typeId,
                    'name' => $oreName,
                    'price' => number_format($price, 2)
                ];
            }
        }

        // Display problems first
        if (!empty($problemOres)) {
            $this->error('Ores WITHOUT prices (' . count($problemOres) . '):');
            $this->table(
                ['Type ID', 'Ore Name', 'Price'],
                array_map(function($ore) {
                    return [$ore['id'], $ore['name'], 'NO PRICE'];
                }, $problemOres)
            );
            $this->newLine();
        } else {
            $this->info('All ores have prices! ✓');
            $this->newLine();
        }

        // Display successful ores
        if (!empty($successfulOres)) {
            $this->info('Ores WITH prices (' . count($successfulOres) . '):');
            $this->table(
                ['Type ID', 'Ore Name', 'Price (ISK)'],
                array_map(function($ore) {
                    return [$ore['id'], $ore['name'], $ore['price']];
                }, $successfulOres)
            );
        }

        $this->newLine();
        $this->info('Diagnosis complete.');
    }
}

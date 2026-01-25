<?php

namespace Rejected\SeatAllianceTax\Commands;

use Illuminate\Console\Command;
use Rejected\SeatAllianceTax\Services\JitaPriceService;

class RefreshJitaPrices extends Command
{
    protected $signature = 'alliancetax:refresh-prices';

    protected $description = 'Refresh Jita split prices cache (average of buy and sell) for common ore types';

    public function handle()
    {
        $this->info('Refreshing Jita split prices (avg of buy/sell)...');
        
        $count = JitaPriceService::prefetchCommonOres();
        
        $this->info("✅ Cached {$count} ore split prices from Jita.");
        
        return 0;
    }
}

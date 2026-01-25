<?php

namespace Rejected\SeatAllianceTax\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class JitaPriceService
{
    // Jita 4-4 Station ID
    const JITA_STATION_ID = 60003760;
    
    // The Forge region ID (Jita is in The Forge)
    const THE_FORGE_REGION_ID = 10000002;
    
    // Cache duration in minutes (1 hour)
    const CACHE_DURATION = 60;

    /**
     * Get Jita split price (average of buy and sell) for a type ID
     * 
     * @param int $typeId
     * @return float
     */
    public static function getSellPrice($typeId)
    {
        $cacheKey = "jita_split_price_{$typeId}";
        
        return Cache::remember($cacheKey, self::CACHE_DURATION * 60, function () use ($typeId) {
            return self::fetchPriceFromESI($typeId);
        });
    }

    /**
     * Get Jita split prices for multiple type IDs (batch)
     * 
     * @param array $typeIds
     * @return array [typeId => price]
     */
    public static function getSellPrices(array $typeIds)
    {
        $prices = [];
        $uncached = [];

        // Check cache first
        foreach ($typeIds as $typeId) {
            $cacheKey = "jita_split_price_{$typeId}";
            $cached = Cache::get($cacheKey);
            
            if ($cached !== null) {
                $prices[$typeId] = $cached;
            } else {
                $uncached[] = $typeId;
            }
        }

        // Fetch uncached prices from ESI
        foreach ($uncached as $typeId) {
            $price = self::fetchPriceFromESI($typeId);
            $prices[$typeId] = $price;
            
            // Cache the result
            Cache::put("jita_split_price_{$typeId}", $price, self::CACHE_DURATION * 60);
        }

        return $prices;
    }

    /**
     * Fetch split price from ESI market orders endpoint
     * Split price = average of highest buy price and lowest sell price
     * 
     * @param int $typeId
     * @return float
     */
    protected static function fetchPriceFromESI($typeId)
    {
        try {
            $url = "https://esi.evetech.net/latest/markets/" . self::THE_FORGE_REGION_ID . "/orders/";
            
            // Fetch sell orders
            $sellResponse = Http::timeout(10)->get($url, [
                'type_id' => $typeId,
                'order_type' => 'sell',
                'datasource' => 'tranquility',
            ]);

            // Fetch buy orders
            $buyResponse = Http::timeout(10)->get($url, [
                'type_id' => $typeId,
                'order_type' => 'buy',
                'datasource' => 'tranquility',
            ]);

            if (!$sellResponse->successful() || !$buyResponse->successful()) {
                Log::warning("ESI market fetch failed for type {$typeId}");
                return self::getFallbackPrice($typeId);
            }

            $sellOrders = $sellResponse->json();
            $buyOrders = $buyResponse->json();
            
            // Filter to only Jita 4-4 orders
            $jitaSellOrders = collect($sellOrders)->filter(function ($order) {
                return $order['location_id'] == self::JITA_STATION_ID;
            });

            $jitaBuyOrders = collect($buyOrders)->filter(function ($order) {
                return $order['location_id'] == self::JITA_STATION_ID;
            });

            // Get lowest sell price and highest buy price
            $lowestSell = null;
            $highestBuy = null;

            if ($jitaSellOrders->isNotEmpty()) {
                $lowestSell = $jitaSellOrders->min('price');
            } elseif (!empty($sellOrders)) {
                // Fallback to region-wide if no Jita 4-4 sell orders
                $lowestSell = collect($sellOrders)->min('price');
            }

            if ($jitaBuyOrders->isNotEmpty()) {
                $highestBuy = $jitaBuyOrders->max('price');
            } elseif (!empty($buyOrders)) {
                // Fallback to region-wide if no Jita 4-4 buy orders
                $highestBuy = collect($buyOrders)->max('price');
            }

            // Calculate split price (average of buy and sell)
            if ($lowestSell !== null && $highestBuy !== null) {
                return ($lowestSell + $highestBuy) / 2;
            } elseif ($lowestSell !== null) {
                // Only sell orders available, use sell price
                return $lowestSell;
            } elseif ($highestBuy !== null) {
                // Only buy orders available, use buy price
                return $highestBuy;
            }

            // No orders found, use fallback
            return self::getFallbackPrice($typeId);

        } catch (\Exception $e) {
            Log::error("ESI market fetch exception for type {$typeId}: " . $e->getMessage());
            return self::getFallbackPrice($typeId);
        }
    }

    /**
     * Get fallback price from SeAT's market_prices table
     * 
     * @param int $typeId
     * @return float
     */
    protected static function getFallbackPrice($typeId)
    {
        // Try market_prices (CCP's average price data)
        $price = \Illuminate\Support\Facades\DB::table('market_prices')
            ->where('type_id', $typeId)
            ->value('average_price');
        
        if ($price && $price > 0) {
            return $price;
        }

        // Try adjusted_price as second fallback
        $adjustedPrice = \Illuminate\Support\Facades\DB::table('market_prices')
            ->where('type_id', $typeId)
            ->value('adjusted_price');
            
        if ($adjustedPrice && $adjustedPrice > 0) {
            return $adjustedPrice;
        }

        // Get item name for better logging
        $itemName = \Illuminate\Support\Facades\DB::table('invTypes')
            ->where('typeID', $typeId)
            ->value('typeName');
        
        // Log missing price for debugging
        Log::warning("No price found for type ID {$typeId} ({$itemName}) - returning 0. Please check if this ore type exists in market data.");
        
        return 0;
    }

    /**
     * Clear price cache for a specific type or all types
     * 
     * @param int|null $typeId
     */
    public static function clearCache($typeId = null)
    {
        if ($typeId) {
            Cache::forget("jita_sell_price_{$typeId}");
        } else {
            // Clear all jita price cache (requires cache tagging or manual clearing)
            // For now, individual items will expire naturally after CACHE_DURATION
            Log::info('Jita price cache clear requested - items will expire naturally');
        }
    }

    /**
     * Prefetch prices for common ore types
     * Call this from a scheduled command to warm the cache
     * 
     * @return int Number of prices fetched
     */
    public static function prefetchCommonOres()
    {
        // Common ore type IDs (can be expanded)
        $commonOres = [
            // Standard Ores & Grade IV Variants
            1230, 17470, 17471, 28432, // Veldspar (I, II, III, IV)
            1228, 17459, 17460, 28429, // Scordite
            1224, 17455, 17456, 28422, // Pyroxeres
            18, 17453, 17454, 28420,   // Plagioclase
            1227, 17448, 17449, 28417, // Omber
            20, 17440, 17441, 28416,   // Kernite
            1226, 17444, 17445, 28414, // Jaspet
            1231, 17446, 17447, 28411, // Hemorphite
            21, 17450, 17451, 28408,   // Hedbergite
            1229, 17437, 17438, 28405, // Gneiss
            1232, 17439, 28402,        // Dark Ochre
            1225, 17442, 17443, 28399, // Crokite
            19, 17456, 17457, 28426,   // Spodumain
            22, 17425, 17426, 28393,   // Arkonor
            1223, 17442, 17443, 28396, // Bistot
            11396, 11506, 11507,       // Mercoxit (I, II, III)
            
            // Moon Ores - R4 (all variants)
            45490, 46676, 46688, // Zeolites (R4)
            45491, 46677, 46689, // Sylvite (R4)
            45492, 46678, 46690, // Bitumens (R4)
            45493, 46679, 46691, // Coesite (R4)
            
            // Moon Ores - R8 (all variants)
            45494, 46680, 46692, // Cobalt (R8)
            45495, 46681, 46693, // Euxenite (R8)
            45496, 46682, 46694, // Scheelite (R8)
            45497, 46683, 46695, // Titanite (R8)
            
            // Moon Ores - R16 (all variants)
            45498, 46684, 46696, // Chromite (R16)
            45499, 46685, 46697, // Otavite (R16)
            45500, 46686, 46698, // Sperrylite (R16)
            45501, 46687, 46699, // Vanadinite (R16)
            
            // Moon Ores - R32 (all variants)
            45502, 46688, 46700, // Carnotite (R32)
            45503, 46689, 46701, // Cinnabar (R32)
            45504, 46690, 46702, // Pollucite (R32)
            45505, 46691, 46703, // Zircon (R32)
            
            // Moon Ores - R64 (all variants)
            45506, 46692, 46704, // Xenotime (R64)
            45507, 46693, 46705, // Monazite (R64)
            45508, 46694, 46706, // Loparite (R64)
            45509, 46695, 46707, // Ytterbite (R64)
            
            // R4 Ubiquitous (Ore/Compressed/Enriched variants)
            46675, 46280, 46281, 46282, // Zeolites variants
            46676, 46283, 46284, 46285, // Sylvite variants
            46677, 46286, 46287, 46288, // Bitumens variants
            46678, 46289, 46290, 46291, // Coesite variants
            
            // R8 Common variants
            46679, 46292, 46293, 46294, // Cobalt variants
            46680, 46295, 46296, 46297, // Euxenite variants
            46681, 46298, 46299, 46300, // Scheelite variants
            46682, 46301, 46302, 46303, // Titanite variants
            
            // R16 Uncommon variants
            46683, 46304, 46305, 46306, // Chromite variants
            46684, 46307, 46308, 46309, // Otavite variants
            46685, 46310, 46311, 46312, // Sperrylite variants
            46686, 46313, 46314, 46315, // Vanadinite variants
            
            // R32 Rare variants
            46687, 46316, 46317, 46318, // Carnotite variants
            46688, 46319, 46320, 46321, // Cinnabar variants
            46689, 46322, 46323, 46324, // Pollucite variants
            46690, 46325, 46326, 46327, // Zircon variants
            
            // R64 Exceptional variants
            46691, 46328, 46329, 46330, // Xenotime variants
            46692, 46331, 46332, 46333, // Monazite variants
            46693, 46334, 46335, 46336, // Loparite variants
            46694, 46337, 46338, 46339, // Ytterbite variants (includes Eifyrium family)
            
            // Athanor Reaction Byproducts
            52327, 52328, 52329, 52330, // Various reaction products
            
            // Ice
            16262, 16263, 16264, 16265, 16266, 16267, 16268, 16269,

            // New Equinox/Metamorphic Ores (Eifyrium etc.)
            63650, // Eifyrium
        ];

        $fetched = 0;
        foreach ($commonOres as $typeId) {
            self::getSellPrice($typeId);
            $fetched++;
            
            // Small delay to be nice to ESI
            usleep(50000); // 50ms
        }

        return $fetched;
    }
}

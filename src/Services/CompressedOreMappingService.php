<?php

namespace Rejected\SeatAllianceTax\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

class CompressedOreMappingService
{
    /**
     * Get the compressed ore Type ID for a given ore Type ID
     * 
     * This allows us to calculate taxes based on compressed ore prices
     * which is more realistic since most miners compress before selling
     * 
     * @param int $typeId The original ore type ID (compressed or uncompressed)
     * @return int The compressed ore type ID
     */
    public static function getCompressedTypeId($typeId)
    {
        // Cache the mapping for 24 hours since SDE data doesn't change often
        $cacheKey = "compressed_ore_mapping_{$typeId}";
        
        return Cache::remember($cacheKey, 86400, function () use ($typeId) {
            // First check if the ore is already compressed
            $oreName = DB::table('invTypes')
                ->where('typeID', $typeId)
                ->value('typeName');
            
            if (!$oreName) {
                // Type ID not found, return original
                return $typeId;
            }
            
            // If already compressed, return as-is
            if (stripos($oreName, 'Compressed') === 0) {
                return $typeId;
            }
            
            // Look for the compressed version by name
            // Pattern: "Ore Name" -> "Compressed Ore Name"
            $compressedName = 'Compressed ' . $oreName;
            
            $compressedTypeId = DB::table('invTypes')
                ->where('typeName', $compressedName)
                ->value('typeID');
            
            if ($compressedTypeId) {
                return $compressedTypeId;
            }
            
            // No compressed version found, return original
            // This handles base ores like "Veldspar", "Scordite", etc.
            // which may or may not have compressed variants in the database
            return $typeId;
        });
    }
    
    /**
     * Check if an ore has a compressed variant
     * 
     * @param int $typeId
     * @return bool
     */
    public static function hasCompressedVariant($typeId)
    {
        $compressedId = self::getCompressedTypeId($typeId);
        return $compressedId !== $typeId;
    }
    
    /**
     * Get ore name (used for debugging/logging)
     * 
     * @param int $typeId
     * @return string
     */
    public static function getOreName($typeId)
    {
        return Cache::remember("ore_name_{$typeId}", 86400, function () use ($typeId) {
            return DB::table('invTypes')
                ->where('typeID', $typeId)
                ->value('typeName') ?? "Unknown (ID: {$typeId})";
        });
    }
    
    /**
     * Batch convert multiple type IDs to compressed variants
     * 
     * @param array $typeIds
     * @return array [originalTypeId => compressedTypeId]
     */
    public static function batchGetCompressedTypeIds(array $typeIds)
    {
        $mappings = [];
        
        foreach ($typeIds as $typeId) {
            $mappings[$typeId] = self::getCompressedTypeId($typeId);
        }
        
        return $mappings;
    }
    
    /**
     * Clear cached mappings (useful after SDE updates)
     */
    public static function clearCache()
    {
        // This would require iterating through all possible type IDs
        // For now, we can just clear all cache with a specific pattern
        // In production, you might want to use cache tags if available
        Cache::flush(); // Use carefully - clears all cache
    }
}

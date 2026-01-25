<?php

return [
    /*
     * Alliance Mining Tax Configuration
     * 
     * NOTE: Settings are primarily managed via the admin interface (database).
     * These .env values are ONLY used as fallbacks.
     * 
     * DO NOT access database in this config file - it's loaded during package
     * discovery before database is available.
     */

    // Default tax rate (percentage) - Fallback only
    'default_tax_rate' => env('ALLIANCE_TAX_RATE', 10.0),

    // Tax calculation period - Fallback only
    'tax_period' => env('ALLIANCE_TAX_PERIOD', 'weekly'),

    // Minimum mining value to be taxed (in ISK) - Fallback only
    'minimum_taxable_amount' => env('ALLIANCE_TAX_MINIMUM', 1000000),

    // Enable automatic tax calculations - Fallback only
    'auto_calculate' => env('ALLIANCE_TAX_AUTO_CALCULATE', true),

    // Alliance ID - Fallback only
    'alliance_id' => env('ALLIANCE_ID', null),

    // Ore types to include in tax calculations
    'taxable_ore_groups' => [
        'Veldspar', 'Scordite', 'Pyroxeres', 'Plagioclase', 'Omber',
        'Kernite', 'Jaspet', 'Hemorphite', 'Hedbergite', 'Gneiss',
        'Dark Ochre', 'Spodumain', 'Crokite', 'Bistot', 'Arkonor',
        'Mercoxit', 'Ice', 'Talassonite', 'Rakovene', 'Bezdnacine',
        'Ytirium', 'Mordunium', 'Griemeer', 'Hezorime', 'Ueganite',
        'Kylixium', 'Nocxite', 'Eifyrium'
    ],
];

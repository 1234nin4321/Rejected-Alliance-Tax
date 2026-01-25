<?php

namespace Rejected\SeatAllianceTax\Services;

class OreNameTranslationService
{
    /**
     * Precise map of EVE variant prefixes to their new Grade equivalents
     * 
     * Convention:
     * - Base Ore → Grade I
     * - First Variant → Grade II
     * - Second Variant → Grade III
     * - Third Variant → Grade IV
     */
    private static $gradeMappings = [
        // GRADE II PREFIXES (Variant 1)
        'Concentrated' => 'Grade I',   // Veldspar → Grade I, Concentrated → Grade II
        'Condensed' => 'Grade II',
        'Solid' => 'Grade II',
        'Azure' => 'Grade II',
        'Silvery' => 'Grade II',
        'Luminous' => 'Grade II',
        'Pure' => 'Grade II',
        'Vivid' => 'Grade II',
        'Vitric' => 'Grade II',
        'Iridescent' => 'Grade II',
        'Onyx' => 'Grade II',
        'Sharp' => 'Grade II',
        'Triclinic' => 'Grade II',
        'Crimson' => 'Grade II',
        'Bright' => 'Grade II',
        'Abyssal' => 'Grade II',    // Talassonite/Rakovene/Bezdnacine
        'Bootleg' => 'Grade II',    // Ytirium
        'Plum' => 'Grade II',       // Mordunium
        'Clear' => 'Grade II',      // Griemeer
        'Dull' => 'Grade II',       // Hezorime
        'Foggy' => 'Grade II',      // Ueganite
        'Kaolin' => 'Grade II',     // Kylixium
        'Fragrant' => 'Grade II',   // Nocxite
        'Doped' => 'Grade II',      // Eifyrium
        'Magma' => 'Grade II',      // Mercoxit (Mercoxit has no IV, so it goes Base -> Magma -> Vitreous)

        // GRADE III PREFIXES (Variant 2)
        'Dense' => 'Grade III',
        'Massive' => 'Grade III',
        'Viscous' => 'Grade III',
        'Rich' => 'Grade III',
        'Golden' => 'Grade III',
        'Fiery' => 'Grade III',
        'Pristine' => 'Grade III',
        'Radiant' => 'Grade III',
        'Glazed' => 'Grade III',
        'Prismatic' => 'Grade III',
        'Obsidian' => 'Grade III',
        'Crystalline' => 'Grade III',
        'Monoclinic' => 'Grade III',
        'Prime' => 'Grade III',
        'Gleaming' => 'Grade III',
        'Hadal' => 'Grade III',     // Talassonite/Rakovene/Bezdnacine
        'Firewater' => 'Grade III', // Ytirium
        'Prize' => 'Grade III',     // Mordunium
        'Inky' => 'Grade III',      // Griemeer
        'Serrated' => 'Grade III',  // Hezorime
        'Overcast' => 'Grade III',  // Ueganite
        'Argil' => 'Grade III',     // Kylixium
        'Intoxicating' => 'Grade III', // Nocxite
        'Boosted' => 'Grade III',   // Eifyrium
        'Vitreous' => 'Grade III',  // Mercoxit

        // GRADE IV PREFIXES (Variant 3)
        'Stable' => 'Grade IV',
        'Glossy' => 'Grade IV',
        'Opulent' => 'Grade IV',
        'Sparkling' => 'Grade IV',
        'Platinoid' => 'Grade IV',
        'Resplendent' => 'Grade IV',
        'Immaculate' => 'Grade IV',
        'Scintillating' => 'Grade IV',
        'Lustrous' => 'Grade IV',
        'Brilliant' => 'Grade IV',
        'Jet' => 'Grade IV',
        'Pellucid' => 'Grade IV',
        'Cubic' => 'Grade IV',
        'Flawless' => 'Grade IV',
        'Dazzling' => 'Grade IV',
        'Unstable' => 'Grade IV',   // Talassonite/Rakovene/Bezdnacine
        'Moonshine' => 'Grade IV',  // Ytirium
        'Jackpot' => 'Grade IV',    // Mordunium
        'Opaque' => 'Grade IV',     // Griemeer
        'Razor' => 'Grade IV',      // Hezorime
        'Stormy' => 'Grade IV',     // Ueganite
        'Ceramic' => 'Grade IV',    // Kylixium
        'Paralyzing' => 'Grade IV',  // Nocxite
        'Augmented' => 'Grade IV',   // Eifyrium
    ];

    /**
     * Valid Ore Families provided by the user
     */
    private static $oreFamilies = [
        'Veldspar', 'Scordite', 'Pyroxeres', 'Plagioclase', 'Omber', 'Kernite', 
        'Jaspet', 'Hemorphite', 'Hedbergite', 'Gneiss', 'Dark Ochre', 'Crokite', 
        'Bistot', 'Arkonor', 'Spodumain', 'Mercoxit', 'Talassonite', 'Rakovene', 
        'Bezdnacine', 'Ytirium', 'Mordunium', 'Griemeer', 'Hezorime', 'Ueganite', 
        'Kylixium', 'Nocxite', 'Eifyrium'
    ];

    /**
     * Map of old ore name prefixes to their new "Grade" equivalents
     */
    private static $translations = [
        // This is kept for backward compatibility if needed, 
        // but we'll use the more structured logic below.
    ];

    /**
     * Translate an ore name to new Grade-based nomenclature
     * 
     * @param string $oreName Original name from invTypes (e.g., "Dense Veldspar")
     * @return string Translated name (e.g., "Veldspar Grade III")
     */
    public static function translate($oreName)
    {
        if (empty($oreName)) {
            return $oreName;
        }

        // Handle batch compression and compression
        $prefix = '';
        if (stripos($oreName, 'Batch Compressed ') === 0) {
            $prefix = 'Batch Compressed ';
            $oreName = substr($oreName, 17);
        } elseif (stripos($oreName, 'Compressed ') === 0) {
            $prefix = 'Compressed ';
            $oreName = substr($oreName, 11);
        }

        // 1. Check for variants (Grade II, III, IV)
        foreach (self::$gradeMappings as $oldPrefix => $grade) {
            if (stripos($oreName, $oldPrefix . ' ') === 0) {
                $baseName = trim(substr($oreName, strlen($oldPrefix)));
                
                // Correction for 'Concentrated' which should be Grade II (if it was mapped as I in error)
                if ($oldPrefix === 'Concentrated') $grade = 'Grade II';

                if (in_array($baseName, self::$oreFamilies)) {
                    return $prefix . $baseName . ' ' . $grade;
                }
            }
        }

        // 2. Check for base ores (Grade I)
        if (in_array($oreName, self::$oreFamilies)) {
            return $prefix . $oreName . ' Grade I';
        }

        // 3. Special case for Dark Ochre variants if not caught
        if (stripos($oreName, 'Onyx Ochre') !== false) return $prefix . 'Dark Ochre Grade II';
        if (stripos($oreName, 'Obsidian Ochre') !== false) return $prefix . 'Dark Ochre Grade III';
        if (stripos($oreName, 'Jet Ochre') !== false) return $prefix . 'Dark Ochre Grade IV';

        return $prefix . $oreName;
    }

    /**
     * Get ore base name (without variants or compression)
     * 
     * @param string $oreName
     * @return string
     */
    public static function getBaseName($oreName)
    {
        $name = $oreName;
        if (stripos($name, 'Batch Compressed ') === 0) {
            $name = trim(substr($name, 17));
        } elseif (stripos($name, 'Compressed ') === 0) {
            $name = trim(substr($name, 11));
        }

        // Remove Grade notation if already translated
        $name = preg_replace('/ Grade (I|II|III|IV)$/', '', $name);

        // Check prefixes
        foreach (self::$gradeMappings as $oldPrefix => $grade) {
            if (stripos($name, $oldPrefix . ' ') === 0) {
                return trim(substr($name, strlen($oldPrefix)));
            }
        }

        // Special case for Ochre
        if (stripos($name, 'Onyx Ochre') !== false) return 'Dark Ochre';
        if (stripos($name, 'Obsidian Ochre') !== false) return 'Dark Ochre';
        if (stripos($name, 'Jet Ochre') !== false) return 'Dark Ochre';

        return $name;
    }

    public static function needsTranslation($oreName)
    {
        $translated = self::translate($oreName);
        return $translated !== $oreName;
    }

    public static function getTranslations()
    {
        return self::$gradeMappings;
    }
}

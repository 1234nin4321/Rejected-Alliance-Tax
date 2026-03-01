<?php

namespace Rejected\SeatAllianceTax\Helpers;

use Illuminate\Support\Facades\DB;

class OreCategory
{
    /**
     * Get the tax category for a given type ID.
     *
     * @param int $typeId
     * @return string
     */
    public static function getCategoryForTypeId($typeId)
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

        // Mercoxit (Group 469)
        if ($groupInfo->groupID == 469) {
            return 'ore';
        }


        // Metamorphic Ores (Equinox Ores, group 2030)
        if ($groupInfo->groupID == 2030) {
            return 'ore'; // Keep as ore for now or change to its own if needed
        }

        if ($groupInfo->categoryID != 25) {
            return 'ore';
        }

        // Ice (Group 465)
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
}



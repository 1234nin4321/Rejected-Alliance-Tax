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

        if (!$groupInfo || $groupInfo->categoryID != 25) {
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
}

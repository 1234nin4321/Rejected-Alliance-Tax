<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Seat\Eveapi\Models\Character\CharacterInfo;

class CharacterSearchController extends Controller
{
    /**
     * Search for characters by name
     */
    public function search(Request $request)
    {
        $query = $request->input('q', '');
        
        if (strlen($query) < 3) {
            return response()->json([]);
        }

        $characters = CharacterInfo::where('name', 'LIKE', "%{$query}%")
            ->limit(10)
            ->get(['character_id', 'name']);

        return response()->json($characters->map(function($char) {
            return [
                'id' => $char->character_id,
                'text' => $char->name . ' (' . $char->character_id . ')'
            ];
        }));
    }

    /**
     * Search for corporations by name
     */
    public function searchCorporations(Request $request)
    {
        $query = $request->input('q', '');
        
        if (strlen($query) < 3) {
            return response()->json([]);
        }

        $corporations = \Seat\Eveapi\Models\Corporation\CorporationInfo::where('name', 'LIKE', "%{$query}%")
            ->limit(10)
            ->get(['corporation_id', 'name']);

        return response()->json($corporations->map(function($corp) {
            return [
                'id' => $corp->corporation_id,
                'text' => $corp->name . ' (' . $corp->corporation_id . ')'
            ];
        }));
    }

    /**
     * Search for solar systems by name
     */
    public function searchSystems(Request $request)
    {
        $query = $request->input('q', '');
        
        if (strlen($query) < 2) {
            return response()->json([]);
        }

        try {
            // Use mapDenormalize - this is the standard SDE table SeAT uses for universe data
            // It contains solar systems with itemName and itemID
            $systems = DB::table('mapDenormalize')
                ->where('groupID', 5) // groupID 5 = Solar Systems
                ->where('itemName', 'LIKE', "%{$query}%")
                ->limit(15)
                ->get(['itemID', 'itemName']);

            return response()->json($systems->map(function($system) {
                return [
                    'id' => $system->itemID,
                    'text' => $system->itemName . ' (' . $system->itemID . ')'
                ];
            }));
        } catch (\Exception $e) {
            // If mapDenormalize doesn't exist, return empty
            \Log::error('System search failed: ' . $e->getMessage());
            return response()->json([]);
        }
    }
}

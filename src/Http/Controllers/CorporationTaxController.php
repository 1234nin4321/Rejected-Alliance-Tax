<?php

namespace Rejected\SeatAllianceTax\Http\Controllers;

use Illuminate\Routing\Controller;

class CorporationTaxController extends Controller
{
    /**
     * Display corporation tax overview.
     *
     * @param int $corporationId
     * @return \Illuminate\View\View
     */
    public function index($corporationId)
    {
        // TODO: Implement corporation tax overview
        return view('alliancetax::corporation.index', [
            'corporationId' => $corporationId,
            'message' => 'Corporation tax view - Coming soon!',
        ]);
    }

    /**
     * Display corporation mining activity.
     *
     * @param int $corporationId
     * @return \Illuminate\View\View
     */
    public function activity($corporationId)
    {
        // TODO: Implement corporation mining activity view
        return view('alliancetax::corporation.activity', [
            'corporationId' => $corporationId,
            'message' => 'Corporation activity view - Coming soon!',
        ]);
    }

    /**
     * Display corporation tax statistics.
     *
     * @param int $corporationId
     * @return \Illuminate\View\View
     */
    public function stats($corporationId)
    {
        // TODO: Implement corporation statistics view
        return view('alliancetax::corporation.stats', [
            'corporationId' => $corporationId,
            'message' => 'Corporation statistics - Coming soon!',
        ]);
    }
}

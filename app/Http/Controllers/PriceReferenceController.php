<?php

namespace App\Http\Controllers;

use App\Support\HarvestStock;
use App\Support\PriceHistory;
use Illuminate\Http\Request;

class PriceReferenceController extends Controller
{
    /**
     * The price sales start from, plus every price it used before.
     *
     * Prices come only from the official sources (DA Region I, PSA for crops the
     * DA does not list, and a class estimate when neither exists), so there is
     * nothing to edit here: the page is read-only for every role.
     */
    public function index(Request $request, HarvestStock $stock, PriceHistory $history)
    {
        $harvests = $stock->activeHarvests();
        $prices   = $stock->priceReferences();

        return view('price-reference.index', [
            'role'      => $request->attributes->get('firebase_role'),
            'priceList' => $stock->priceList($harvests, $prices),
            'history'   => $history->all(),
        ]);
    }
}

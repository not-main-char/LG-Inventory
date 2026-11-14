<?php

namespace App\Http\Controllers;

use App\Support\HarvestStock;
use App\Support\PriceBook;
use App\Support\PriceHistory;
use Illuminate\Http\Request;

class PriceReferenceController extends Controller
{
    /**
     * What the published sources say a product should sell for, plus every
     * price it used before.
     *
     * This page is a reference only: it never sets a price. The published DA
     * Region I figure, the BFAR/SEAFDEC seed rate and the class estimate are
     * what the sale starts from, and the CAC Manager can change the price of an
     * individual sale (with its discount) when the market differs.
     */
    public function index(Request $request, HarvestStock $stock, PriceHistory $history, PriceBook $book)
    {
        // Opening this page refreshes a DA list that is more than 12 hours old,
        // so a missed cron run does not leave the prices a day behind. A failed
        // fetch keeps the stored list.
        $book->load(true);

        $harvests = $stock->activeHarvests();
        $prices   = $stock->priceReferences();
        $priceList = $stock->priceList($harvests, $prices);

        // Only this farm's products are history-worthy; the DA feed also quotes
        // onion, chicken and squid, which the farm neither stocks nor sells.
        $products = array_values(array_unique(array_map(
            fn ($row) => $row['product'] ?? '',
            $priceList
        )));

        return view('price-reference.index', [
            'role'      => $request->attributes->get('firebase_role'),
            'priceList' => $priceList,
            'history'   => $history->all(500, $products),
        ]);
    }
}

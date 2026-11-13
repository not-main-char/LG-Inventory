<?php

namespace App\Http\Controllers;

use App\Support\FishStock;
use App\Support\HarvestStock;
use App\Support\PriceBook;
use Carbon\Carbon;
use Google\Cloud\Firestore\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Contract\Firestore;

class HarvestController extends Controller
{
    protected $firestore;

    public function __construct(Firestore $firestore)
    {
        $this->firestore = $firestore->database();
    }

    public function index(Request $request, HarvestStock $stock, FishStock $fishStock)
    {
        $role = $request->attributes->get('firebase_role');

        // Pond batches become selling stock here. The device records themselves
        // are never edited; the mirrored harvest documents keep sold quantities.
        try {
            $fishSync = $fishStock->sync();
        } catch (\Throwable $e) {
            \Log::warning('Fish sync failed: ' . $e->getMessage());
            $fishSync = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'error' => 'Fish stock could not be synced.'];
        }

        $harvests  = $stock->activeHarvests();
        $prices    = $stock->priceReferences();
        $available = $stock->availableProducts($harvests, $prices);

        // Plants and fish are shown on separate tabs; both newest first.
        $harvestRows = array_reverse(array_values(array_filter($harvests, fn ($h) => $h['kind'] !== 'fish')));
        $fishRows    = array_reverse(array_values(array_filter($harvests, fn ($h) => $h['kind'] === 'fish')));
        $batches = array_values(array_filter($harvests, fn ($h) => $h['remaining'] > 0.00001));

        $disposals = [];
        foreach ($this->firestore->collection('harvest_disposals')->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $d = $doc->data();
            if (($d['status'] ?? 'active') === 'archived') {
                continue;
            }
            $disposals[] = [
                'id'           => $doc->id(),
                'product'      => $d['product'] ?? '',
                'unit'         => $d['unit'] ?? 'kilos',
                'kind'         => $d['kind'] ?? 'plant',
                'disposalType' => $d['disposalType'] ?? 'self_consume',
                'quantity'     => (float) ($d['quantity'] ?? 0),
                'date'         => $d['date'] ?? '',
                'note'         => $d['note'] ?? '',
            ];
        }
        usort($disposals, fn ($a, $b) => strcmp($b['date'], $a['date']));

        $seedItems = $this->seedItems();

        // What a sale would start from, so the Add Harvest form can show the
        // price as soon as the crop and unit are chosen instead of leaving the
        // price invisible until someone opens the Sales page.
        $priceHints = $stock->priceHints(
            array_merge(array_column($seedItems, 'name'), array_keys(PriceBook::OWN_PRICES)),
            $prices
        );

        $plantOptions = $this->plantOptions($seedItems);

        return view('harvest.index', compact(
            'role', 'available', 'harvestRows', 'fishRows', 'fishSync', 'batches', 'disposals',
            'seedItems', 'priceHints', 'plantOptions'
        ));
    }

    /**
     * Crops the harvest form offers: inventory seed items plus every plant the
     * system has already recorded. That is why typing a new crop once (UBE, for
     * example) makes it appear in the dropdown next time.
     */
    protected function plantOptions(array $seedItems): array
    {
        $names = [];
        foreach ($seedItems as $item) {
            $names[$item['name']] = true;
        }

        try {
            foreach ($this->firestore->collection('product_catalog')->documents() as $doc) {
                if (!$doc->exists() || ($doc->data()['kind'] ?? 'plant') !== 'plant') {
                    continue;
                }
                $name = strtoupper(trim((string) ($doc->data()['name'] ?? '')));
                if ($name !== '') {
                    $names[$name] = true;
                }
            }

            // Everything already harvested counts too, archived included, so a
            // crop typed before this catalogue existed is still remembered.
            foreach ($this->firestore->collection('harvests')->documents() as $doc) {
                if (!$doc->exists() || ($doc->data()['kind'] ?? 'plant') === 'fish') {
                    continue;
                }
                $name = strtoupper(trim((string) ($doc->data()['product'] ?? '')));
                if ($name !== '' && !str_starts_with($name, 'DEV-')) {
                    $names[$name] = true;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Product catalog read failed: ' . $e->getMessage());
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /** Remember a plant so it stays in the dropdown, and which systems grew it. */
    protected function rememberProduct(string $product, string $source): void
    {
        $name = strtoupper(trim($product));
        if ($name === '') {
            return;
        }

        try {
            $ref = $this->firestore->collection('product_catalog')->document('plant-' . substr(sha1($name), 0, 20));
            $snap = $ref->snapshot();
            $systems = $snap->exists() ? (array) ($snap->data()['systems'] ?? []) : [];
            $systems[$source] = true;

            $ref->set([
                'name'      => $name,
                'kind'      => 'plant',
                'systems'   => array_keys($systems),
                'updatedAt' => Carbon::now('Asia/Manila'),
            ], ['merge' => true]);
        } catch (\Throwable $e) {
            \Log::warning('Catalog write failed: ' . $e->getMessage());
        }
    }

    /**
     * Seed-category inventory items, shown to the user by crop name.
     * "LETTUCE SEEDS" in inventory becomes "LETTUCE" for the harvest.
     */
    protected function seedItems(): array
    {
        $items = [];
        foreach ($this->firestore->collection('inventory_items')->where('type', '=', 'seed')->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $d = $doc->data();
            if (($d['status'] ?? 'active') === 'archived') {
                continue;
            }
            $raw   = trim((string) ($d['name'] ?? ''));
            $clean = trim(preg_replace('/\s+SEEDS?$/i', '', $raw));
            $name  = strtoupper($clean !== '' ? $clean : $raw);
            if ($name === '') {
                continue;
            }
            $items[$name] = ['id' => $doc->id(), 'name' => $name];
        }
        ksort($items);

        return array_values($items);
    }

    public function store(Request $request)
    {
        $v = $request->validate([
            // Fish is recorded from the ponds on its own tab, so this form only
            // ever writes plants.
            'source'      => 'required|in:hydroponics,landfarming',
            'kind'        => 'nullable|in:plant',
            'product'     => 'required|string|max:120',
            'seedItemId'  => 'nullable|string|max:100',
            'quantity'    => 'required|numeric|min:0.01',
            'unit'        => 'required|in:kilos,pcs',
            'harvestDate' => 'required|date',
            'notes'       => 'nullable|string|max:255',
        ]);

        $kind = 'plant';

        $qty = round((float) $v['quantity'], 4);

        $record = [
            'product'           => strtoupper(trim($v['product'])),
            'seedItemId'        => $kind === 'plant' ? ($v['seedItemId'] ?? null) : null,
            'kind'              => $kind,
            'source'            => $v['source'],
            'quantity'          => $qty,
            'remainingQuantity' => $qty,
            'unit'              => $v['unit'],
            'harvestDate'       => Carbon::parse($v['harvestDate'])->format('Y-m-d'),
            'notes'             => trim((string) ($v['notes'] ?? '')),
            'status'            => 'active',
            'createdBy'         => $request->attributes->get('firebase_user'),
            'createdAt'         => Carbon::now('Asia/Manila'),
        ];

        $this->firestore->collection('harvests')->newDocument()->set($record);
        $this->rememberProduct($record['product'], $v['source']);

        return redirect()->to(route('harvest.index') . '#plants')->with('success', 'Harvest recorded');
    }

    public function archive(string $id)
    {
        $ref  = $this->firestore->collection('harvests')->document($id);
        $snap = $ref->snapshot();
        if (!$snap->exists()) {
            return back()->withErrors(['error' => 'Harvest not found']);
        }

        $h = $snap->data();
        if (abs((float) ($h['remainingQuantity'] ?? 0) - (float) ($h['quantity'] ?? 0)) > 0.0001) {
            return back()->withErrors(['error' => 'This harvest already has sales or disposals. Archive those first, then archive the harvest.']);
        }

        $ref->set(['status' => 'archived', 'archivedAt' => Carbon::now('Asia/Manila')], ['merge' => true]);

        return redirect()->to(route('harvest.index') . '#records')->with('success', 'Harvest archived');
    }

    /** Self-consumed or given to the DA. Takes the quantity out of one harvest batch. */
    public function storeDisposal(Request $request)
    {
        $v = $request->validate([
            'harvestId'    => 'required|string',
            'disposalType' => 'required|in:self_consume,da',
            'quantity'     => 'required|numeric|min:0.01',
            'date'         => 'required|date',
            'note'         => 'nullable|string|max:255',
        ]);

        $qty  = round((float) $v['quantity'], 4);
        $date = Carbon::parse($v['date'])->format('Y-m-d');
        $user = $request->attributes->get('firebase_user');

        try {
            $this->firestore->runTransaction(function (Transaction $t) use ($v, $qty, $date, $user) {
                $ref  = $this->firestore->collection('harvests')->document($v['harvestId']);
                $snap = $t->snapshot($ref);

                if (!$snap->exists() || (($snap->data()['status'] ?? 'active') === 'archived')) {
                    throw new \RuntimeException('That harvest was not found.');
                }

                $h = $snap->data();
                $remaining = (float) ($h['remainingQuantity'] ?? 0);
                if ($qty > $remaining + 0.00001) {
                    throw new \RuntimeException("Only {$remaining} {$h['unit']} left in this harvest.");
                }

                $t->set($ref, ['remainingQuantity' => round($remaining - $qty, 4)], ['merge' => true]);
                $t->create($this->firestore->collection('harvest_disposals')->newDocument(), [
                    'harvestId'    => $v['harvestId'],
                    'product'      => $h['product'] ?? '',
                    'unit'         => $h['unit'] ?? 'kilos',
                    'kind'         => $h['kind'] ?? 'plant',
                    'disposalType' => $v['disposalType'],
                    'quantity'     => $qty,
                    'date'         => $date,
                    'note'         => $v['note'] ?? '',
                    'status'       => 'active',
                    'createdBy'    => $user,
                    'createdAt'    => Carbon::now('Asia/Manila'),
                ]);
            });
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        return redirect()->to(route('harvest.index') . '#disposals')->with('success', 'Recorded');
    }

    /** Undo a disposal: the quantity goes back into its harvest batch. */
    public function archiveDisposal(string $id)
    {
        try {
            $this->firestore->runTransaction(function (Transaction $t) use ($id) {
                $ref  = $this->firestore->collection('harvest_disposals')->document($id);
                $snap = $t->snapshot($ref);
                if (!$snap->exists()) {
                    throw new \RuntimeException('Record not found.');
                }

                $d = $snap->data();
                if (($d['status'] ?? 'active') === 'archived') {
                    return;
                }

                $harvestRef  = $this->firestore->collection('harvests')->document($d['harvestId'] ?? 'missing');
                $harvestSnap = $t->snapshot($harvestRef);
                if ($harvestSnap->exists()) {
                    $remaining = (float) ($harvestSnap->data()['remainingQuantity'] ?? 0);
                    $t->set($harvestRef, ['remainingQuantity' => round($remaining + (float) ($d['quantity'] ?? 0), 4)], ['merge' => true]);
                }

                $t->set($ref, ['status' => 'archived', 'archivedAt' => Carbon::now('Asia/Manila')], ['merge' => true]);
            });
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        return redirect()->to(route('harvest.index') . '#disposals')->with('success', 'Record archived and stock returned');
    }

    /** Add or update a product's reference retail price. */

    protected function fail(\Throwable $e)
    {
        if ($e instanceof \RuntimeException) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        Log::error('Harvest action failed: ' . $e->getMessage());

        return back()->withErrors(['error' => 'Something went wrong. Please try again.']);
    }
}

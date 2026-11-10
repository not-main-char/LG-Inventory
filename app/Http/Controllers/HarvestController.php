<?php

namespace App\Http\Controllers;

use App\Support\DeviceHarvests;
use App\Support\HarvestStock;
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

    public function index(Request $request, HarvestStock $stock, DeviceHarvests $deviceHarvests)
    {
        $role = $request->attributes->get('firebase_role');

        $harvests  = $stock->activeHarvests();
        $prices    = $stock->priceReferences();
        $available = $stock->availableProducts($harvests, $prices);
        $priceList = $stock->priceList($harvests, $prices);

        $harvestRows = array_reverse($harvests); // newest first
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

        // Harvests the devices recorded in planting_events. Only "harvested"
        // events are kept; anything still planted is not a harvest yet. They
        // are not records on their own: the Add Harvest form offers them as a
        // starting point, and the person saving the harvest confirms it.
        $deviceData  = $deviceHarvests->harvests();
        $deviceRows  = $deviceData['rows'];
        $deviceError = $deviceData['error'];

        // An event that already became a harvest record is not offered again.
        $recordedEvents = [];
        foreach ($harvests as $h) {
            if (!empty($h['deviceId']) && !empty($h['deviceEventKey'])) {
                $recordedEvents[DeviceHarvests::importId($h['deviceId'], $h['deviceEventKey'])] = true;
            }
        }
        foreach ($deviceRows as &$deviceRow) {
            $deviceRow['recorded'] = isset($recordedEvents[$deviceRow['stockId']]);
        }
        unset($deviceRow);

        return view('harvest.index', compact(
            'role', 'available', 'harvestRows', 'batches', 'disposals', 'prices', 'priceList', 'seedItems',
            'deviceRows', 'deviceError'
        ));
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
            'source'      => 'required|in:hydroponics,aquaculture,landfarming',
            'kind'        => 'nullable|in:plant,fish',
            'product'     => 'required|string|max:120',
            'seedItemId'  => 'nullable|string|max:100',
            'quantity'    => 'required|numeric|min:0.01',
            'unit'        => 'required|in:kilos,pcs',
            'harvestDate' => 'required|date',
            'notes'       => 'nullable|string|max:255',
            'deviceId'       => 'nullable|string|max:80',
            'deviceEventKey' => 'nullable|string|max:120',
        ]);

        // Aquaculture is always fish and landfarming is always plant;
        // a hydroponics system can give either, so the user chooses.
        $kind = match ($v['source']) {
            'aquaculture' => 'fish',
            'landfarming' => 'plant',
            default       => ($v['kind'] ?? 'plant'),
        };

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

        // Started from a device event: the device's own book is left untouched.
        // The record gets the event's fixed id, so the same harvesting cannot be
        // recorded twice.
        if (!empty($v['deviceId']) && !empty($v['deviceEventKey'])) {
            $record['deviceId']       = $v['deviceId'];
            $record['deviceEventKey'] = $v['deviceEventKey'];

            $ref = $this->firestore->collection('harvests')
                ->document(DeviceHarvests::importId($v['deviceId'], $v['deviceEventKey']));

            try {
                $ref->create($record);
            } catch (\Throwable $e) {
                if ($ref->snapshot()->exists()) {
                    return back()->withErrors(['error' => 'That device harvest was already recorded once. If this is a new harvest, add it without picking a device.'])->withInput();
                }

                return $this->fail($e);
            }

            return redirect()->to(route('harvest.index') . '#records')->with('success', 'Harvest recorded from the device');
        }

        $this->firestore->collection('harvests')->newDocument()->set($record);

        return redirect()->to(route('harvest.index') . '#records')->with('success', 'Harvest recorded');
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
    public function savePrice(Request $request)
    {
        $v = $request->validate([
            'product'        => 'required|string|max:120',
            'unit'           => 'required|in:kilos,pcs',
            'retailPrice'    => 'required|numeric|min:0',
            'wholesalePrice' => 'nullable|numeric|min:0',
            'floorPrice'     => 'nullable|numeric|min:0',
            'ceilingPrice'   => 'nullable|numeric|min:0',
            'piecesPerKg'    => 'nullable|numeric|min:0.01',
            'note'           => 'nullable|string|max:255',
        ]);

        $floor   = ($v['floorPrice'] ?? null) !== null ? round((float) $v['floorPrice'], 2) : null;
        $ceiling = ($v['ceilingPrice'] ?? null) !== null ? round((float) $v['ceilingPrice'], 2) : null;
        if ($floor !== null && $ceiling !== null && $floor > $ceiling) {
            return back()->withErrors(['floorPrice' => 'The usual low price must be lower than the usual high price.'])->withInput();
        }

        $product = strtoupper(trim($v['product']));

        $this->firestore->collection('price_reference')->document(HarvestStock::priceId($product, $v['unit']))->set([
            'product'        => $product,
            'unit'           => $v['unit'],
            'retailPrice'    => round((float) $v['retailPrice'], 2),
            'wholesalePrice' => ($v['wholesalePrice'] ?? null) !== null ? round((float) $v['wholesalePrice'], 2) : null,
            'floorPrice'     => $floor,
            'ceilingPrice'   => $ceiling,
            'piecesPerKg'    => ($v['piecesPerKg'] ?? null) !== null ? round((float) $v['piecesPerKg'], 2) : null,
            'note'           => $v['note'] ?? '',
            'updatedOn'      => Carbon::now('Asia/Manila')->format('Y-m-d'),
            'updatedAt'      => Carbon::now('Asia/Manila'),
        ], ['merge' => true]);

        return redirect()->to(route('harvest.index') . '#prices')->with('success', 'Price saved');
    }

    protected function fail(\Throwable $e)
    {
        if ($e instanceof \RuntimeException) {
            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        }

        Log::error('Harvest action failed: ' . $e->getMessage());

        return back()->withErrors(['error' => 'Something went wrong. Please try again.']);
    }
}

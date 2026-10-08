<?php

namespace App\Http\Controllers;

use App\Services\FirebaseService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SensorHistoryController extends Controller
{
    protected FirebaseService $firebase;

    // Devices pulled from the RTDB `devices` node.
    // If HYDROLG002 has a different real-world name, just change it here.
    protected array $devices = [
        'HYDROLG001' => 'Aquaponics 1',
        'HYDROLG002' => 'Aquaponics 2',
    ];

    // Fields as they appear on each devices/{id}/sensor_history/{pushKey} record.
    //
    // "zones" define the gauge's safe/watch/alert bands (used for the dial
    // color and the Optimal/Watch/Alert stamp). These are draft aquaponics
    // defaults (tilapia + leafy greens) — confirm the real numbers with
    // your adviser/paper and adjust here; nothing else needs to change.
    // waterLevel has no zones — it's rendered as a level bar, not a gauge,
    // since there's no established safe range for it yet.
    protected array $sensors = [
        'airTemperature' => [
            'label' => 'Air Temperature', 'unit' => '°C', 'kind' => 'gauge',
            'min' => 15, 'max' => 40,
            'zones' => [[15, 20, 'alert'], [20, 24, 'watch'], [24, 32, 'optimal'], [32, 35, 'watch'], [35, 40, 'alert']],
        ],
        'waterTemperature' => [
            'label' => 'Water Temperature', 'unit' => '°C', 'kind' => 'gauge',
            'min' => 18, 'max' => 36,
            'zones' => [[18, 22, 'alert'], [22, 26, 'watch'], [26, 30, 'optimal'], [30, 32, 'watch'], [32, 36, 'alert']],
        ],
        'turbidityPercent' => [
            'label' => 'Turbidity', 'unit' => '%', 'kind' => 'gauge',
            'min' => 0, 'max' => 100,
            'zones' => [[0, 20, 'optimal'], [20, 40, 'watch'], [40, 100, 'alert']],
        ],
        'humidity' => [
            'label' => 'Humidity', 'unit' => '%', 'kind' => 'gauge',
            'min' => 20, 'max' => 100,
            'zones' => [[20, 40, 'alert'], [40, 50, 'watch'], [50, 75, 'optimal'], [75, 85, 'watch'], [85, 100, 'alert']],
        ],
        'pH' => [
            'label' => 'pH Level', 'unit' => '', 'kind' => 'gauge',
            'min' => 4, 'max' => 10,
            'zones' => [[4, 6, 'alert'], [6, 6.5, 'watch'], [6.5, 8, 'optimal'], [8, 8.5, 'watch'], [8.5, 10, 'alert']],
        ],
        'waterLevel' => [
            'label' => 'Water Level', 'unit' => 'cm', 'kind' => 'level',
            'min' => 0, 'max' => 100,
            'zones' => null,
        ],
    ];

    public function __construct(FirebaseService $firebase)
    {
        $this->firebase = $firebase;
    }

    public function index(string $device = 'HYDROLG001')
    {
        if (!array_key_exists($device, $this->devices)) {
            $device = 'HYDROLG001';
        }

        return view('sensor-history.index', [
            'devices'      => $this->devices,
            'sensors'      => $this->sensors,
            'activeDevice' => $device,
        ]);
    }

    /**
     * AJAX endpoint: /sensor-history-data?device=HYDROLG001&range=day|week|month&date=Y-m-d
     */
    public function summaryData(Request $request)
    {
        $device = $request->get('device', 'HYDROLG001');
        $range  = in_array($request->get('range'), ['day', 'week', 'month'], true)
            ? $request->get('range')
            : 'day';

        if (!array_key_exists($device, $this->devices)) {
            $device = 'HYDROLG001';
        }

        $anchor = $request->get('date')
            ? Carbon::parse($request->get('date'), 'Asia/Manila')
            : Carbon::now('Asia/Manila');

        $cacheKey = "sensor_summary_{$device}_{$range}_{$anchor->format('Y-m-d')}";

        $result = Cache::remember($cacheKey, 300, function () use ($device, $range, $anchor) {
            [$start, $end] = $this->rangeBounds($range, $anchor);
            $readings = $this->fetchReadings($device, $start, $end);

            return $this->summarize($readings, $range, $start, $end);
        });

        $result['range_label'] = $this->rangeLabel($range, $anchor);
        $result['prev_date']   = $this->shift($range, $anchor, -1)->format('Y-m-d');
        $result['next_date']   = $this->shift($range, $anchor, 1)->format('Y-m-d');

        return response()->json($result);
    }

    protected function rangeBounds(string $range, Carbon $anchor): array
    {
        return match ($range) {
            'week'  => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()],
            'month' => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
            default => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
        };
    }

    protected function shift(string $range, Carbon $anchor, int $direction): Carbon
    {
        return match ($range) {
            'week'  => $anchor->copy()->addWeeks($direction),
            'month' => $anchor->copy()->addMonths($direction),
            default => $anchor->copy()->addDays($direction),
        };
    }

    protected function rangeLabel(string $range, Carbon $anchor): string
    {
        return match ($range) {
            'week'  => $anchor->copy()->startOfWeek()->format('M j').' - '.$anchor->copy()->endOfWeek()->format('M j, Y'),
            'month' => $anchor->format('F Y'),
            default => $anchor->format('F j, Y'),
        };
    }

    /**
     * Pulls devices/{device}/sensor_history from the Realtime Database and
     * filters to the given window in PHP. We deliberately avoid
     * orderByChild()/startAt()/endAt() here — Kreait's SDK throws
     * UnsupportedQuery unless a ".indexOn" rule exists for this path, and
     * we don't want to require changes to the project's Firebase rules.
     */
    protected function fetchReadings(string $device, Carbon $start, Carbon $end): array
    {
        $reference = $this->firebase->getDatabase()
            ->getReference("devices/{$device}/sensor_history");

        $all = $reference->getValue();
        if (!$all) {
            return [];
        }

        $startMs = $start->copy()->valueOf();
        $endMs   = $end->copy()->valueOf();

        $filtered = [];
        foreach ($all as $reading) {
            if (!is_array($reading)) {
                continue;
            }
            $time = $this->readingTime($reading);
            if (!$time) {
                continue;
            }
            $ms = $time->valueOf();
            if ($ms >= $startMs && $ms <= $endMs) {
                $filtered[] = $reading;
            }
        }

        return $filtered;
    }

    protected function summarize(array $readings, string $range, Carbon $start, Carbon $end): array
    {
        $sensorKeys = array_keys($this->sensors);
        [$slotMinutes, $labelFormat] = $this->slotConfig($range);

        // Build a fixed grid of time slots for the whole range — Day: 24
        // hourly slots. Week: 8 slots/day x 7 days = 56. Month: 2 slots/day.
        // Every slot appears on the chart even if no reading landed in it
        // (shows as a gap), so the x-axis is a consistent grid, not just
        // "whichever hours happened to have data".
        $slots = [];
        $cursor = $start->copy();
        while ($cursor->lt($end)) {
            $slots[] = $cursor->copy();
            $cursor->addMinutes($slotMinutes);
        }
        if (empty($slots)) {
            $slots[] = $start->copy();
        }

        $buckets = array_fill(0, count($slots), []);
        $startTs = $start->getTimestamp();

        foreach ($readings as $reading) {
            $time = $this->readingTime($reading);
            if (!$time) {
                continue;
            }
            $minutesFromStart = ($time->getTimestamp() - $startTs) / 60;
            if ($minutesFromStart < 0) {
                continue;
            }
            $index = intdiv((int) floor($minutesFromStart), $slotMinutes);
            if ($index < 0 || $index >= count($slots)) {
                continue;
            }
            $buckets[$index][] = $reading;
        }

        $labels = [];
        $series = [];
        foreach ($sensorKeys as $key) {
            $series[$key] = ['avg' => [], 'low' => [], 'high' => []];
        }

        foreach ($slots as $i => $slotStart) {
            $labels[] = $slotStart->format($labelFormat);
            $slotReadings = $buckets[$i];

            foreach ($sensorKeys as $key) {
                $values = array_values(array_filter(array_map(
                    fn ($r) => isset($r[$key]) ? (float) $r[$key] : null,
                    $slotReadings
                ), fn ($v) => $v !== null));

                $series[$key]['avg'][]  = count($values) ? round(array_sum($values) / count($values), 2) : null;
                $series[$key]['low'][]  = count($values) ? min($values) : null;
                $series[$key]['high'][] = count($values) ? max($values) : null;
            }
        }

        // Overall stat-card summary across the whole requested range
        // (this is what drives the 6 sensor cards, regardless of which
        // sensor's chart is currently open).
        $overall = [];
        foreach ($sensorKeys as $key) {
            $allValues = array_values(array_filter(array_map(
                fn ($r) => isset($r[$key]) ? (float) $r[$key] : null,
                $readings
            ), fn ($v) => $v !== null));

            $overall[$key] = [
                'avg'  => count($allValues) ? round(array_sum($allValues) / count($allValues), 2) : null,
                'low'  => count($allValues) ? min($allValues) : null,
                'high' => count($allValues) ? max($allValues) : null,
            ];
        }

        return [
            'labels'  => $labels,
            'series'  => $series,
            'overall' => $overall,
            'count'   => count($readings),
            'range'   => $range,
            'start'   => $start->format('Y-m-d'),
            'end'     => $end->format('Y-m-d'),
        ];
    }

    /**
     * [slot width in minutes, Carbon format for each slot's chart label].
     * Day: 60min slots -> 24 points. Week: 180min (3hr) slots -> 8/day x 7
     * days = 56 points. Month: 720min (12hr) slots -> 2/day.
     */
    protected function slotConfig(string $range): array
    {
        return match ($range) {
            'week'  => [180, 'D gA'],
            'month' => [720, 'M j gA'],
            default => [60, 'gA'],
        };
    }

    protected function readingTime(array $reading): ?Carbon
    {
        if (!empty($reading['dateTime'])) {
            try {
                return Carbon::parse($reading['dateTime'], 'Asia/Manila');
            } catch (\Throwable $e) {
                // fall through to timestamp
            }
        }

        if (!empty($reading['timestamp'])) {
            return Carbon::createFromTimestampMs($reading['timestamp'], 'Asia/Manila');
        }

        return null;
    }
}

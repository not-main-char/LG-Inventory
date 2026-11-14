<?php

namespace App\Http\Controllers;

use App\Support\FishStock;
use App\Support\SensorReadings;
use Kreait\Firebase\Contract\Database;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SensorHistoryController extends Controller
{
    protected Database $database;
    protected FishStock $stock;

    // Fields as they appear on each devices/{id}/sensor_history/{pushKey} record.
    //
    // "normal" is the [min, max] range a reading must stay inside to count
    // as Normal — these are the SAME thresholds the HydroSolar mobile app
    // uses (null = no limit on that side). Change them here and both the
    // dial colors and the Normal/Error stamp follow.
    // "min"/"max" are only the dial's display span, and "zones" are the
    // colored bands drawn on it (green = inside normal, red = outside).
    protected array $sensors = [
        'airTemperature' => [
            'label' => 'Air Temperature', 'unit' => '°C', 'kind' => 'gauge',
            'normal' => [35, 40],
            'min' => 0, 'max' => 50,
            'zones' => [[0, 35, 'alert'], [35, 40, 'optimal'], [40, 50, 'alert']],
        ],
        'waterTemperature' => [
            'label' => 'Water Temperature', 'unit' => '°C', 'kind' => 'gauge',
            'normal' => [28, 32],
            'min' => 15, 'max' => 40,
            'zones' => [[15, 28, 'alert'], [28, 32, 'optimal'], [32, 40, 'alert']],
        ],
        'turbidityPercent' => [
            'label' => 'Turbidity', 'unit' => '%', 'kind' => 'gauge',
            'normal' => [0, 50],
            'min' => 0, 'max' => 100,
            'zones' => [[0, 50, 'optimal'], [50, 100, 'alert']],
        ],
        'humidity' => [
            'label' => 'Humidity', 'unit' => '%', 'kind' => 'gauge',
            'normal' => [60, 70],
            'min' => 0, 'max' => 100,
            'zones' => [[0, 60, 'alert'], [60, 70, 'optimal'], [70, 100, 'alert']],
        ],
        'pH' => [
            'label' => 'pH Level', 'unit' => '', 'kind' => 'gauge',
            'normal' => [6.5, 7.2],
            'min' => 0, 'max' => 14,
            'zones' => [[0, 6.5, 'alert'], [6.5, 7.2, 'optimal'], [7.2, 14, 'alert']],
        ],
        // The mobile app lists this as "<50-80%"; read here as 50-80% is
        // normal. Edit 'normal' if the real rule is different.
        'waterLevel' => [
            'label' => 'Water Level', 'unit' => '%', 'kind' => 'level',
            'normal' => [50, 80],
            'min' => 0, 'max' => 100,
            'zones' => null,
        ],
    ];

    public function __construct(Database $database, FishStock $stock)
    {
        $this->database = $database;
        $this->stock    = $stock;
    }

    public function index(string $device = 'HYDROLG001')
    {
        $devices = $this->stock->devices();

        if (!array_key_exists($device, $devices)) {
            $device = 'HYDROLG001';
        }

        return view('sensor-history.index', [
            'devices'      => $devices,
            'sensors'      => $this->sensors,
            'activeDevice' => $device,
        ]);
    }

    /**
     * AJAX endpoint: /sensor-history-data?device=HYDROLG001&range=hour|day|week|month&date=Y-m-d&hour=0-23
     */
    public function summaryData(Request $request)
    {
        $device = $request->get('device', 'HYDROLG001');
        $range  = in_array($request->get('range'), ['hour', 'day', 'week', 'month'], true)
            ? $request->get('range')
            : 'day';

        if (!array_key_exists($device, $this->stock->devices())) {
            $device = 'HYDROLG001';
        }

        $anchor = $request->get('date')
            ? Carbon::parse($request->get('date'), 'Asia/Manila')
            : Carbon::now('Asia/Manila');

        // Hour view zooms into one clock hour of the chosen date.
        if ($range === 'hour') {
            $hour   = max(0, min(23, (int) $request->get('hour', $anchor->hour)));
            $anchor = $anchor->copy()->setTime($hour, 0, 0);
        }

        $stamp    = $anchor->format($range === 'hour' ? 'Y-m-d-H' : 'Y-m-d');
        $cacheKey = "sensor_summary_{$device}_{$range}_{$stamp}";

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

    /**
     * AJAX endpoint: /sensor-history-days?device=HYDROLG001&month=Y-m
     * Which days of that month actually have readings, and how many.
     */
    public function daysData(Request $request)
    {
        $device = $request->get('device', 'HYDROLG001');
        if (!array_key_exists($device, $this->stock->devices())) {
            $device = 'HYDROLG001';
        }

        $month = (string) $request->get('month', '');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            $month = Carbon::now('Asia/Manila')->format('Y-m');
        }

        $days = Cache::remember("sensor_days_{$device}_{$month}", 300, function () use ($device, $month) {
            $all = $this->database->getReference("devices/{$device}/sensor_history")->getValue();
            $counts = [];
            foreach ((array) $all as $reading) {
                if (!is_array($reading)) {
                    continue;
                }
                $time = $this->readingTime($reading);
                if (!$time || $time->format('Y-m') !== $month) {
                    continue;
                }
                $day = $time->format('Y-m-d');
                $counts[$day] = ($counts[$day] ?? 0) + 1;
            }
            ksort($counts);

            return $counts;
        });

        return response()->json(['month' => $month, 'days' => $days]);
    }

    protected function rangeBounds(string $range, Carbon $anchor): array
    {
        return match ($range) {
            'hour'  => [$anchor->copy()->startOfHour(), $anchor->copy()->endOfHour()],
            'week'  => [$anchor->copy()->startOfWeek(), $anchor->copy()->endOfWeek()],
            'month' => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth()],
            default => [$anchor->copy()->startOfDay(), $anchor->copy()->endOfDay()],
        };
    }

    protected function shift(string $range, Carbon $anchor, int $direction): Carbon
    {
        return match ($range) {
            'hour'  => $anchor->copy()->addHours($direction),
            'week'  => $anchor->copy()->addWeeks($direction),
            'month' => $anchor->copy()->addMonths($direction),
            default => $anchor->copy()->addDays($direction),
        };
    }

    protected function rangeLabel(string $range, Carbon $anchor): string
    {
        return match ($range) {
            'hour'  => $anchor->format('F j, Y, g A').' to '.$anchor->copy()->addHour()->format('g A'),
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
        $reference = $this->database
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
        // Hour view shows every raw reading as its own point (no averaging).
        if ($range === 'hour') {
            return $this->summarizeHour($readings, $start, $end);
        }

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
            $series[$key] = ['avg' => [], 'low' => [], 'high' => [], 'count' => []];
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
                $series[$key]['low'][]  = count($values) ? round(min($values), 2) : null;
                $series[$key]['high'][] = count($values) ? round(max($values), 2) : null;
                // How many raw readings landed in this time slot (shown in the chart tooltip).
                $series[$key]['count'][] = count($values);
            }
        }

        $overall = $this->overallStats($readings);

        return [
            'labels'  => $labels,
            // Start time of each slot, so the page can zoom in when a dot is clicked.
            'slots'   => array_map(fn ($slot) => $slot->format('Y-m-d\TH:i'), $slots),
            'series'  => $series,
            'overall' => $overall,
            'count'   => count($readings),
            'range'   => $range,
            'start'   => $start->format('Y-m-d'),
            'end'     => $end->format('Y-m-d'),
        ];
    }

    /**
     * Avg / lowest / highest per sensor across every reading in the range
     * (drives the 6 sensor tiles).
     */
    protected function overallStats(array $readings): array
    {
        $overall = [];
        foreach (array_keys($this->sensors) as $key) {
            $allValues = array_values(array_filter(array_map(
                fn ($r) => isset($r[$key]) ? (float) $r[$key] : null,
                $readings
            ), fn ($v) => $v !== null));

            $overall[$key] = [
                'avg'  => count($allValues) ? round(array_sum($allValues) / count($allValues), 2) : null,
                'low'  => count($allValues) ? round(min($allValues), 2) : null,
                'high' => count($allValues) ? round(max($allValues), 2) : null,
            ];
        }

        return $overall;
    }

    /**
     * Hour view: one point per raw reading, in time order, labelled with its
     * exact time. low = high = avg here because nothing is grouped.
     */
    protected function summarizeHour(array $readings, Carbon $start, Carbon $end): array
    {
        $sensorKeys = array_keys($this->sensors);

        $timed = [];
        foreach ($readings as $reading) {
            $time = $this->readingTime($reading);
            if ($time) {
                $timed[] = [$time, $reading];
            }
        }
        usort($timed, fn ($a, $b) => $a[0]->getTimestamp() <=> $b[0]->getTimestamp());

        $labels = [];
        $slots  = [];
        $series = [];
        foreach ($sensorKeys as $key) {
            $series[$key] = ['avg' => [], 'low' => [], 'high' => [], 'count' => []];
        }

        foreach ($timed as [$time, $reading]) {
            $labels[] = $time->format('g:i:s A');
            $slots[]  = $time->format('Y-m-d\TH:i');
            foreach ($sensorKeys as $key) {
                $value = isset($reading[$key]) ? round((float) $reading[$key], 2) : null;
                $series[$key]['avg'][]   = $value;
                $series[$key]['low'][]   = $value;
                $series[$key]['high'][]  = $value;
                $series[$key]['count'][] = $value === null ? 0 : 1;
            }
        }

        return [
            'labels'  => $labels,
            'slots'   => $slots,
            'series'  => $series,
            'overall' => $this->overallStats($readings),
            'count'   => count($timed),
            'range'   => 'hour',
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
        return SensorReadings::at($reading);
    }
}

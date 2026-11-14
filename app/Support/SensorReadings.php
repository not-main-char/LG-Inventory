<?php

namespace App\Support;

use Carbon\Carbon;

/** One reading written by the ponds, read without trusting its shape. */
class SensorReadings
{
    /** Sensor columns a report shows, in order. */
    public const COLUMNS = [
        'airTemperature',
        'waterTemperature',
        'turbidityPercent',
        'humidity',
        'pH',
        'waterLevel',
    ];

    /** Timestamp of a reading, whatever shape the device wrote there. */
    public static function at(array $reading): ?Carbon
    {
        // dateTime is the most precise, timestamp is epoch milliseconds.
        foreach (['dateTime', 'timestamp', 'createdAt'] as $field) {
            $value = $reading[$field] ?? null;

            try {
                if (is_numeric($value) && (float) $value > 1000000000) {
                    return Carbon::createFromTimestampMs((int) $value, 'Asia/Manila');
                }
                if (is_string($value) && trim($value) !== '') {
                    return Carbon::parse($value, 'Asia/Manila');
                }
            } catch (\Throwable $e) {
                // Try the next field rather than losing the whole reading.
            }
        }

        return null;
    }
}

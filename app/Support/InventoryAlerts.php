<?php

namespace App\Support;

class InventoryAlerts
{
    /**
     * Days remaining for a daily-use item, or null if not applicable.
     */
    public static function daysRemaining(array $data): ?int
    {
        if (($data['usageFrequency'] ?? null) === 'daily') {
            $dailyAmount = (float)($data['dailyConsumptionAmount'] ?? 0);
            $conversionRate = (float)($data['conversionRate'] ?? 1);
            
            if ($dailyAmount > 0) {
                // Calculate daily rate in terms of the base unit (e.g., how many sacks per day)
                $dailyRateInBaseUnit = $dailyAmount / $conversionRate;
                
                if ($dailyRateInBaseUnit > 0) {
                    return (int) floor(($data['currentStock'] ?? 0) / $dailyRateInBaseUnit);
                }
            }
        }
        return null;
    }

    /**
     * Harvest/planting cycles remaining for a seasonal item, or null if not applicable.
     */
    public static function cyclesRemaining(array $data): ?int
    {
        $perCycle = $data['seedsPerCycle'] ?? 0;
        if (($data['usageFrequency'] ?? null) === 'seasonal' && $perCycle > 0) {
            return (int) floor(($data['currentStock'] ?? 0) / $perCycle);
        }
        return null;
    }

    /**
     * Whether this item should be flagged as a low-stock / needs-action alert.
     * Fully automatic — there is no manually-set threshold anymore.
     *
     *  - Daily-use items: alert when fewer than 7 days of stock remain.
     *  - Seasonal items: alert when stock can't cover at least 1 more full cycle/season.
     *  - Manual items: never auto-alert (no consumption rate to project from).
     */
    public static function isLow(array $data): bool
    {
        $days = self::daysRemaining($data);
        if ($days !== null) {
            return $days < 7;
        }

        $cycles = self::cyclesRemaining($data);
        if ($cycles !== null) {
            return $cycles < 1;
        }

        return false;
    }
}

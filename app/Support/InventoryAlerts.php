<?php

namespace App\Support;

class InventoryAlerts
{
    /**
     * Seeds warn once fewer than this many are left, regardless of frequency,
     * because a planting cycle cannot be covered with a nearly-empty packet.
     */
    public const SEED_LOW_THRESHOLD = 50;

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
     *  - Seeds: alert below 50 on hand (SEED_LOW_THRESHOLD).
     *  - Seasonal items (legacy): alert when stock can't cover 1 more cycle.
     *  - Manual items: never auto-alert (no consumption rate to project from).
     */
    /**
     * Seeds get a harder alert than general low stock: a seed that cannot cover
     * another planting cycle (or is already at zero) blocks the next harvest,
     * so it is flagged as critical rather than merely low.
     */
    public static function isCritical(array $data): bool
    {
        $stock = (float) ($data['currentStock'] ?? 0);

        // A minimum set on the item is the clearest rule: at or below it, act now.
        if (isset($data['minStock']) && $data['minStock'] !== '' && $stock <= (float) $data['minStock'] + 0.00001) {
            return true;
        }

        if (($data['type'] ?? '') !== 'seed') {
            return false;
        }

        if ($stock <= 0.00001) {
            return true;
        }

        $perCycle = (float) ($data['seedsPerCycle'] ?? 0);

        return $perCycle > 0 && $stock < $perCycle;
    }

    public static function isLow(array $data): bool
    {
        // Twice the minimum is the warning band, so there is time to restock
        // before the item becomes critical.
        $minimum = $data['minStock'] ?? null;
        if ($minimum !== null && $minimum !== '' && (float) $minimum > 0
            && (float) ($data['currentStock'] ?? 0) <= (float) $minimum * 2) {
            return true;
        }

        // Seeds: warn below 50 on hand. They are usually manual items, so without
        // this rule they would only surface once they hit zero.
        if (($data['type'] ?? '') === 'seed'
            && (float) ($data['currentStock'] ?? 0) < self::SEED_LOW_THRESHOLD) {
            return true;
        }

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

<?php

namespace App\Support;

use Carbon\Carbon;
use Google\Cloud\Firestore\FirestoreClient;

/**
 * Keeps the notification bell holding one live alert per low-stock item.
 *
 * Alerts used to be written only when stock changed (auto deduction, manual
 * deduction), so an item that was already low — a manual seed like PECHAY —
 * never reached the bell, and an alert that had been read stayed silent while
 * the item was still low.
 *
 * This pass runs when the inventory or dashboard is opened:
 *
 *  - item low, no unread alert for it  -> create one (so the bell always shows
 *    it again after it has been read, until the shortfall is fixed)
 *  - item low, unread alert exists     -> leave it alone
 *  - item no longer low                -> mark its unread alert(s) read, so a
 *    restock clears the bell by itself
 */
class LowStockNotifier
{
    /**
     * @return int number of notifications created
     */
    public static function sync(FirestoreClient $firestore): int
    {
        $now = Carbon::now('Asia/Manila');
        $created = 0;

        // Unread alerts grouped by item id, newest not needed — any one is enough
        // to know the bell already shows that item.
        $unreadByItem = [];
        foreach ($firestore->collection('notifications')->where('read', '==', false)->documents() as $doc) {
            $itemId = (string) (($doc->data()['itemId'] ?? ''));
            if ($itemId !== '') {
                $unreadByItem[$itemId][] = $doc->reference();
            }
        }

        foreach ($firestore->collection('inventory_items')->documents() as $doc) {
            $item = $doc->data();

            if (($item['status'] ?? '') === 'archived') {
                continue;
            }

            $id = $doc->id();
            $isLow = InventoryAlerts::isLow($item);
            $hasUnread = isset($unreadByItem[$id]);

            if (! $isLow) {
                // Restocked (or the minimum was lowered): the warning no longer
                // applies, so clear it from the unread count.
                if ($hasUnread) {
                    foreach ($unreadByItem[$id] as $ref) {
                        $ref->set(['read' => true], ['merge' => true]);
                    }
                }
                continue;
            }

            if ($hasUnread) {
                continue;
            }

            $critical = InventoryAlerts::isCritical($item);
            $name = $item['name'] ?? 'Stock';
            $stock = $item['currentStock'] ?? 0;
            $unit = $item['unit'] ?? '';

            $firestore->collection('notifications')->newDocument()->set([
                'userId' => 'all',
                'title' => $critical ? 'Critical Stock Alert' : 'Low Stock Alert',
                'message' => $name . ' is ' . ($critical ? 'critical' : 'low') . " ({$stock} {$unit} left)",
                'itemId' => $id,
                'itemName' => $name,
                'read' => false,
                'createdAt' => $now,
                'type' => $critical ? 'critical_stock' : 'low_stock',
            ]);

            $created++;
        }

        return $created;
    }
}

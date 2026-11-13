<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Kreait\Firebase\Contract\Firestore;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class NotificationController extends Controller
{
    protected $firestore;
    
    public function __construct(Firestore $firestore)
    {
        $this->firestore = $firestore->database();
    }
    
        public function index(Request $request)
    {
        $uid = $request->attributes->get('firebase_user') ?? 'guest';
        $role = $request->attributes->get('firebase_role') ?? 'viewer';
        
        $cacheKey = 'user_notifications_' . $uid;
        
        try {
            // Return cached data if available
            if (Cache::has($cacheKey)) {
                return response()->json(Cache::get($cacheKey));
            }

            // Fetch notifications and sort in PHP to avoid composite index requirements
            $notifications = $this->firestore->collection('notifications')
                ->where('userId', 'in', [$uid, 'all'])
                ->documents();
            
            $notifList = [];
            foreach ($notifications as $doc) {
                $notifList[] = ['id' => $doc->id(), 'data' => $doc->data()];
            }

            // Sort by createdAt desc in PHP
            usort($notifList, function($a, $b) {
                $timeA = $a['data']['createdAt'] ?? 0;
                $timeB = $b['data']['createdAt'] ?? 0;
                
                $valA = ($timeA instanceof \Google\Cloud\Core\Timestamp) ? $timeA->get()->getTimestamp() : 0;
                $valB = ($timeB instanceof \Google\Cloud\Core\Timestamp) ? $timeB->get()->getTimestamp() : 0;
                
                return $valB <=> $valA;
            });

            // Limit to 20
            $notifList = array_slice($notifList, 0, 20);
            
            $data = [];
            foreach ($notifList as $item) {
                $n = $item['data'];

                // A low-stock alert for an archived item is stale — hide it.
                if ($this->isLowStockForArchivedItem($n, $this->archivedAlertKeys())) {
                    continue;
                }
                
                if ($role !== 'admin' && isset($n['deletedByAdmin']) && $n['deletedByAdmin'] === true) {
                    continue;
                }
                
                $createdAtTime = 'Just now';
                if (isset($n['createdAt'])) {
                    try {
                        $rawTime = $n['createdAt'];
                        if ($rawTime instanceof \Google\Cloud\Core\Timestamp) {
                            $dateTime = $rawTime->get();
                        } elseif (is_object($rawTime) && method_exists($rawTime, 'toDateTime')) {
                            $dateTime = $rawTime->toDateTime();
                        } elseif (is_object($rawTime) && method_exists($rawTime, 'get')) {
                            $dateTime = $rawTime->get();
                        } else {
                            $dateTime = $rawTime;
                        }
                        $createdAtTime = \Carbon\Carbon::instance($dateTime)->diffForHumans();
                    } catch (\Throwable $e) {
                        $createdAtTime = 'Just now';
                    }
                }
                
                $data[] = [
                    'id' => $item['id'],
                    'title' => $n['title'] ?? 'Alert',
                    'message' => $n['message'] ?? '',
                    'read' => $n['read'] ?? false,
                    'createdAt' => $createdAtTime,
                    'type' => $n['type'] ?? 'info'
                ];
            }

            Cache::put($cacheKey, $data, 15);
            return response()->json($data);

        } catch (\Throwable $e) {
            \Log::error('Notifications query failed: ' . $e->getMessage());
            
            // Fallback: If the ordered query fails (likely due to missing index), 
            // try a simpler query without ordering as a safety measure.
            try {
                $notifications = $this->firestore->collection('notifications')
                    ->where('userId', 'in', [$uid, 'all'])
                    ->limit(20)
                    ->documents();
                
                $data = [];
                foreach ($notifications as $doc) {
                    $n = $doc->data();
                    $data[] = [
                        'id' => $doc->id(),
                        'title' => $n['title'] ?? 'Alert',
                        'message' => $n['message'] ?? '',
                        'read' => $n['read'] ?? false,
                        'createdAt' => 'Recent',
                        'type' => $n['type'] ?? 'info'
                    ];
                }
                return response()->json($data);
            } catch (\Throwable $e2) {
                return response()->json([]);
            }
        }
    }

    
    /**
     * Item ids and names of archived inventory items. A low-stock alert for one
     * of these must never reach the notification bell.
     */
    protected function archivedAlertKeys(): array
    {
        return Cache::remember('archived_inventory_alert_keys', 60, function () {
            $keys = ['ids' => [], 'names' => []];

            $archived = $this->firestore->collection('inventory_items')
                ->where('status', '==', 'archived')
                ->documents();

            foreach ($archived as $doc) {
                $keys['ids'][$doc->id()] = true;

                $name = trim((string) ($doc->data()['name'] ?? ''));
                if ($name !== '') {
                    $keys['names'][] = $name;
                }
            }

            return $keys;
        });
    }

    /** Whether this notification is a low-stock alert for an archived item. */
    protected function isLowStockForArchivedItem(array $notification, array $archived): bool
    {
        if (($notification['type'] ?? '') !== 'low_stock') {
            return false;
        }

        $itemId = $notification['itemId'] ?? null;
        if ($itemId !== null) {
            return isset($archived['ids'][$itemId]);
        }

        // Alerts written before itemId was stored only carry the item name.
        $message = (string) ($notification['message'] ?? '');
        foreach ($archived['names'] as $name) {
            if (str_starts_with($message, $name . ' is low')) {
                return true;
            }
        }

        return false;
    }

    public function markRead($id, Request $request)
    {
        $this->firestore->collection('notifications')->document($id)->set(['read' => true], ['merge' => true]);
        
        //Clear this user's notification cache so the UI updates instantly
        $uid = $request->attributes->get('firebase_user');
        Cache::forget('user_notifications_' . $uid);
        
        return response()->json(['success' => true]);
    }
    
}

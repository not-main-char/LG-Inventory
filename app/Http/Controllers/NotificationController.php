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

    
    public function markRead($id, Request $request)
    {
        $this->firestore->collection('notifications')->document($id)->set(['read' => true], ['merge' => true]);
        
        //Clear this user's notification cache so the UI updates instantly
        $uid = $request->attributes->get('firebase_user');
        Cache::forget('user_notifications_' . $uid);
        
        return response()->json(['success' => true]);
    }
    
    public function destroy($id, Request $request)
    {
        $uid = $request->attributes->get('firebase_user');
        $role = $request->attributes->get('firebase_role');
        
        if ($role === 'admin') {
            $this->firestore->collection('notifications')->document($id)->delete();
        } else {
            $this->firestore->collection('notifications')->document($id)->set(['deletedByAdmin' => true], ['merge' => true]);
        }
        
        //Clear cache structures on change
        Cache::forget('user_notifications_' . $uid);
        
        return response()->json(['success' => true]);
    }
    
    public function restore($id, Request $request)
    {
        $this->firestore->collection('notifications')->document($id)->set(['deletedByAdmin' => false], ['merge' => true]);
        
        //Clear cache structures on change
        $uid = $request->attributes->get('firebase_user');
        Cache::forget('user_notifications_' . $uid);
        
        return response()->json(['success' => true]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\SupportMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class ChatController extends Controller
{
    // --- Customer Endpoints ---

    public function getMessages(Request $request)
    {
        $sessionId = $request->header('X-Session-ID');
        $user = auth('sanctum')->user();

        $query = SupportMessage::query();

        if ($user) {
            // For logged in users, we fetch their messages
            $query->where('user_id', $user->id);
        } else if ($sessionId) {
            // For guests, we fetch by session ID
            $query->where('session_id', $sessionId)->whereNull('user_id');
        } else {
            return response()->json([]);
        }

        return response()->json($query->orderBy('created_at', 'asc')->get());
    }

    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $sessionId = $request->header('X-Session-ID');
        $user = auth('sanctum')->user();

        $message = SupportMessage::create([
            'user_id' => $user ? $user->id : null,
            'session_id' => $sessionId,
            'is_admin_reply' => false,
            'message' => $validated['message'],
        ]);

        // Send notification to admin
        try {
            $adminUser = (object)['email' => env('ADMIN_EMAIL', 'mannabridalsupport@gmail.com'), 'name' => 'Admin'];
            $messageData = [
                'name' => $user ? $user->name : 'Guest Customer',
                'email' => $user ? $user->email : 'guest@mannabridal.com',
                'message' => $validated['message'],
            ];
            \Illuminate\Support\Facades\Notification::send($adminUser, new \App\Notifications\ChatNotification($messageData, 'admin'));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Support msg notification error: ' . $e->getMessage());
        }

        return response()->json($message, 201);
    }

    // --- Admin Endpoints ---

    public function adminGetConversations()
    {
        // Get latest message for each user/session group
        $conversations = DB::select("
            SELECT t1.*, u.name as user_name, u.email as user_email
            FROM support_messages t1
            INNER JOIN (
                SELECT 
                    COALESCE(user_id, 0) as u_id,
                    COALESCE(session_id, '') as s_id,
                    MAX(created_at) as max_date
                FROM support_messages
                GROUP BY COALESCE(user_id, 0), COALESCE(session_id, '')
            ) t2 ON 
                COALESCE(t1.user_id, 0) = t2.u_id AND 
                COALESCE(t1.session_id, '') = t2.s_id AND 
                t1.created_at = t2.max_date
            LEFT JOIN users u ON t1.user_id = u.id
            ORDER BY t1.created_at DESC
        ");

        return response()->json($conversations);
    }

    public function adminGetThread(Request $request)
    {
        $userId = $request->query('user_id');
        $sessionId = $request->query('session_id');

        $query = SupportMessage::query();
        if ($userId) {
            $query->where('user_id', $userId);
        } else {
            $query->where('session_id', $sessionId)->whereNull('user_id');
        }

        // Mark unread messages as read
        $query->clone()->where('is_admin_reply', false)->where('is_read', false)->update(['is_read' => true]);

        return response()->json($query->orderBy('created_at', 'asc')->get());
    }

    public function adminReply(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
            'user_id' => 'nullable|integer',
            'session_id' => 'nullable|string',
        ]);

        $message = SupportMessage::create([
            'user_id' => $validated['user_id'] ?? null,
            'session_id' => $validated['session_id'] ?? null,
            'is_admin_reply' => true,
            'message' => $validated['message'],
        ]);

        // Send email to customer
        try {
            $user = null;
            if (!empty($validated['user_id'])) {
                $user = \App\Models\User::find($validated['user_id']);
            }
            if ($user && $user->email) {
                $messageData = [
                    'name' => $user->name,
                    'message' => $validated['message']
                ];
                \Illuminate\Support\Facades\Notification::send($user, new \App\Notifications\ChatNotification($messageData, 'customer'));
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Chat reply email error: ' . $e->getMessage());
        }

        return response()->json($message, 201);
    }
}

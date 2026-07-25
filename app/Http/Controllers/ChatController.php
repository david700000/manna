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
        $user = auth('sanctum')->user();

        if (!$user) {
            return response()->json([], 401);
        }

        $query = SupportMessage::where('user_id', $user->id);

        return response()->json($query->orderBy('created_at', 'asc')->get());
    }

    public function sendMessage(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string',
        ]);

        $user = auth('sanctum')->user();
        if (!$user) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $isFirst = SupportMessage::where('user_id', $user->id)->count() === 0;

        $message = SupportMessage::create([
            'user_id' => $user->id,
            'session_id' => null,
            'is_admin_reply' => false,
            'message' => $validated['message'],
        ]);

        if ($isFirst) {
            SupportMessage::create([
                'user_id' => $user->id,
                'session_id' => null,
                'is_admin_reply' => true,
                'message' => 'Thank you for reaching out! We will connect you to our sales rep shortly. 😊',
            ]);
        }

        // Send notification to admin
        try {
            $admins = \App\Models\User::where('role', 'admin')->get();
            $messageData = [
                'name' => $user ? $user->name : 'Guest Customer',
                'email' => $user ? $user->email : 'guest@mannabridal.com',
                'message' => $validated['message'],
            ];
            foreach ($admins as $adminUser) {
                (new \App\Notifications\ChatNotification($messageData, 'admin'))->send($adminUser);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Support msg notification error: ' . $e->getMessage());
        }

        return response()->json($message, 201);
    }

    // --- Admin Endpoints ---

    public function adminGetConversations()
    {
        $allMessages = \App\Models\SupportMessage::with('user')
            ->orderBy('created_at', 'desc')
            ->get();
            
        $conversations = $allMessages
            ->unique(function ($item) {
                return ($item->user_id ?? 0) . '-' . ($item->session_id ?? '');
            })
            ->values();
            
        $result = $conversations->map(function($msg) use ($allMessages) {
            $msg->u_id = $msg->user_id ?? 0;
            $msg->s_id = $msg->session_id ?? '';
            $msg->user_name = $msg->user ? $msg->user->name : null;
            $msg->user_email = $msg->user ? $msg->user->email : null;
            
            $msg->unread_count = $allMessages->where('user_id', $msg->user_id)
                                             ->where('session_id', $msg->session_id)
                                             ->where('is_admin_reply', false)
                                             ->where('is_read', false)
                                             ->count();
            return $msg;
        });

        return response()->json($result);
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

        $query = SupportMessage::where('is_admin_reply', true);
        if (!empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        } elseif (!empty($validated['session_id'])) {
            $query->where('session_id', $validated['session_id']);
        }
        $isAdminFirstReply = !$query->exists();

        if ($isAdminFirstReply) {
            $adminName = $request->user() ? $request->user()->name : 'Admin';
            SupportMessage::create([
                'user_id' => $validated['user_id'] ?? null,
                'session_id' => $validated['session_id'] ?? null,
                'is_admin_reply' => true,
                'message' => "Hello, my name is {$adminName}. I will be the one in charge of your requests.",
            ]);
        }

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
                (new \App\Notifications\ChatNotification($messageData, 'customer'))->send($user);
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Chat reply email error: ' . $e->getMessage());
        }

        return response()->json($message, 201);
    }
}

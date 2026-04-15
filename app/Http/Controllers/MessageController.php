<?php

namespace App\Http\Controllers;

use App\Models\Message;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class MessageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    /** POST /messages/send */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message'     => 'required|string|max:1000',
        ]);

        // Prevent messaging yourself
        if ($request->receiver_id == Auth::id()) {
            return response()->json(['error' => 'You cannot message yourself.'], 422);
        }

        $message = Message::create([
            'sender_id'   => Auth::id(),
            'receiver_id' => $request->receiver_id,
            'message'     => $request->message,
        ]);

        return response()->json([
            'message' => 'Message sent.',
            'data'    => $message->load(['sender:id,name,user_image', 'receiver:id,name,user_image']),
        ], 201);
    }

    /** GET /messages/conversations/{userId} */
    public function getConversation($userId)
    {
        $messages = Message::where(function ($q) use ($userId) {
                $q->where('sender_id', Auth::id())->where('receiver_id', $userId);
            })
            ->orWhere(function ($q) use ($userId) {
                $q->where('sender_id', $userId)->where('receiver_id', Auth::id());
            })
            ->with(['sender:id,name,user_image', 'receiver:id,name,user_image'])
            ->orderBy('created_at', 'asc')
            ->get();

        // Mark all incoming as read in one query
        Message::where('sender_id', $userId)
            ->where('receiver_id', Auth::id())
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json($messages);
    }

    /** GET /messages/conversations */
    public function getConversations()
    {
        $userId = Auth::id();

        $conversations = Message::where('sender_id', $userId)
            ->orWhere('receiver_id', $userId)
            ->with(['sender:id,name,user_image', 'receiver:id,name,user_image'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique(function ($message) use ($userId) {
                return $message->sender_id == $userId
                    ? $message->receiver_id
                    : $message->sender_id;
            })
            ->values();

        return response()->json($conversations);
    }

    /** GET /messages/unread */
    public function unreadCount()
    {
        $count = Message::where('receiver_id', Auth::id())
            ->where('is_read', false)
            ->count();

        return response()->json(['unread_count' => $count]);
    }

    /** PATCH /messages/{id}/read */
    public function markAsRead($messageId)
    {
        $message = Message::where('id', $messageId)
            ->where('receiver_id', Auth::id())
            ->firstOrFail();

        $message->update(['is_read' => true]);

        return response()->json(['message' => 'Message marked as read.']);
    }

    /** DELETE /messages/{id} */
    public function deleteMessage($messageId)
    {
        $message = Message::where('id', $messageId)
            ->where('sender_id', Auth::id())
            ->firstOrFail();

        $message->delete();

        return response()->json(['message' => 'Message deleted successfully.']);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// 📚 LEARNING: The Message model represents a single chat turn — either a user message
// or an AI response. The "role" column tells us which one.
//
// Database row example:
//   id | conversation_id | role  | content                  | created_at
//    1 |        1        | user  | "What is PHP?"           | 2026-05-23 ...
//    2 |        1        | model | "PHP is a scripting..."  | 2026-05-23 ...
//    3 |        1        | user  | "Show me an example"     | 2026-05-23 ...

class Message extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',               // 'user' or 'model'
        'content',
        // 📚 LEARNING — PHASE 3: Token tracking columns.
        // These are only populated for AI response rows (role = 'model').
        // User messages are the INPUT so they don't have token data — those columns stay NULL.
        'prompt_tokens',      // Tokens sent to Gemini (system prompt + full history + user message)
        'completion_tokens',  // Tokens in the AI's reply
        'total_tokens',       // Sum of both
    ];

    // ─────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────

    /**
     * A message belongs to a conversation.
     *
     * 📚 LEARNING: belongsTo() is the inverse of hasMany().
     * It tells Eloquent: "find the conversations row where id = $this->conversation_id"
     *
     * Usage: $message->conversation → the parent Conversation object
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}

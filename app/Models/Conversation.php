<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

// 📚 LEARNING: An Eloquent Model is a PHP class that represents one row in a database table.
// By convention, the class name "Conversation" maps to the table "conversations" (pluralised).
// You get CRUD operations for free: Conversation::create(), $conv->save(), $conv->delete(), etc.

class Conversation extends Model
{
    // 📚 LEARNING: $fillable is a security whitelist.
    // Only these columns can be set via mass-assignment (e.g. Conversation::create([...]))
    // without $fillable, Laravel throws a MassAssignmentException to protect you from
    // accidentally inserting unexpected fields sent by a malicious user.
    protected $fillable = [
        'session_id',
        'title',
    ];

    // ─────────────────────────────────────────────────────────────────
    // RELATIONSHIPS
    // ─────────────────────────────────────────────────────────────────

    /**
     * A conversation has many messages.
     *
     * 📚 LEARNING: hasMany() defines the "one-to-many" side of the relationship.
     * It tells Eloquent: "look in the messages table for rows where conversation_id = $this->id"
     *
     * Usage examples:
     *   $conversation->messages           → all messages (Collection)
     *   $conversation->messages()->count() → how many messages
     *   $conversation->messages()->create([...]) → add a new message
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}

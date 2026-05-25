<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 📚 LEARNING: This migration creates the "messages" table which stores
// every individual chat message — both from the user and from the AI.
// The table has a FOREIGN KEY relationship to "conversations":
//   One conversation HAS MANY messages.
//   Each message BELONGS TO one conversation.
// This is a classic "one-to-many" database relationship.

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();

            // 📚 LEARNING: foreignId() creates a BIGINT UNSIGNED column named "conversation_id"
            // that references the "id" column on the "conversations" table.
            // constrained() enforces referential integrity at the DB level:
            //   → You cannot insert a message with a conversation_id that doesn't exist.
            // cascadeOnDelete() means: if the parent conversation is deleted,
            //   ALL its child messages are automatically deleted too.
            //   This prevents "orphaned" rows with no parent.
            $table->foreignId('conversation_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // 📚 LEARNING: "role" tells us WHO sent this message.
            // We use the exact strings Gemini API expects:
            //   'user'  → the human chatting with the AI
            //   'model' → the AI's response
            // NOTE: OpenAI uses 'assistant' instead of 'model'. Gemini uses 'model'.
            // This is why we can't just swap AI providers without updating this field!
            $table->enum('role', ['user', 'model']);

            // The actual text content of the message.
            // TEXT type stores up to ~65,000 characters — enough for long AI responses.
            $table->text('content');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * 📚 LEARNING: We drop "messages" BEFORE "conversations" because
     // of the foreign key constraint. If we tried to drop "conversations"
     // first while "messages" still references it, the DB would throw an error.
     // Always drop child tables before parent tables when there are FK constraints.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};

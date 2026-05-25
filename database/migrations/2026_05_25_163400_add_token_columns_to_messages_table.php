<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// ═══════════════════════════════════════════════════════════════════
// 📚 LEARNING — PHASE 3: TOKEN TRACKING
//
// We add 3 nullable integer columns to the existing "messages" table.
// Only AI response rows (role = 'model') will have values here.
// User message rows will have NULL — they are the input, not the output.
//
// Why per-message instead of per-conversation?
//   Because token usage CHANGES with every request:
//   → Message 1: prompt_tokens = 12  (only the first user message)
//   → Message 2: prompt_tokens = 45  (context grows! includes all prior history)
//   → Message 3: prompt_tokens = 98  (even bigger!)
//   Storing per-message lets you SEE this growth — it's the whole lesson.
//
// Token columns explained:
//   prompt_tokens     → Total tokens sent to Gemini (system prompt + history + new message)
//   completion_tokens → Tokens in the AI's reply
//   total_tokens      → Sum (prompt_tokens + completion_tokens)
//
// Real-world cost calculation example (Gemini 2.0 Flash pricing):
//   Input:  $0.10 per 1M tokens  → 100 prompt_tokens = $0.00001
//   Output: $0.40 per 1M tokens  → 300 completion_tokens = $0.00012
// ═══════════════════════════════════════════════════════════════════

return new class extends Migration
{
    /**
     * Add token tracking columns to the messages table.
     *
     * 📚 LEARNING: Schema::table() MODIFIES an existing table (adds columns).
     * Schema::create() would create a NEW table.
     * We use ->after('content') to control column order in the DB — purely cosmetic.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // 📚 LEARNING: unsignedInteger() = positive integers only (tokens can't be negative).
            // nullable() = allows NULL, because user messages have no token data.
            // ->after('content') places the column right after the content column in the DB schema.
            $table->unsignedInteger('prompt_tokens')
                  ->nullable()
                  ->after('content')
                  ->comment('Total tokens sent to the AI (system prompt + history + user message)');

            $table->unsignedInteger('completion_tokens')
                  ->nullable()
                  ->after('prompt_tokens')
                  ->comment('Tokens used in the AI reply');

            $table->unsignedInteger('total_tokens')
                  ->nullable()
                  ->after('completion_tokens')
                  ->comment('Sum of prompt_tokens + completion_tokens');
        });
    }

    /**
     * Reverse the migration.
     *
     * 📚 LEARNING: dropColumn() removes specific columns.
     * We list all 3 in one call — more efficient than 3 separate calls.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['prompt_tokens', 'completion_tokens', 'total_tokens']);
        });
    }
};

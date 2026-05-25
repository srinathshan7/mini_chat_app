<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

// ═══════════════════════════════════════════════════════════════════
// 📚 LEARNING — PHASE 2: CONVERSATION MEMORY
//
// The core challenge: LLMs (Large Language Models) are STATELESS.
// Every time you call the Gemini API, it starts with a completely
// blank slate — it remembers NOTHING from previous calls.
//
// Solution: WE are responsible for maintaining memory.
// On each new message, we:
//   1. Save the user's message to our database
//   2. Load ALL previous messages from the database
//   3. Format them into Gemini's "role-based" history format
//   4. Send the entire conversation history to Gemini every time
//   5. Save Gemini's reply to the database
//
// This is called "Context Passing" or "Chat Memory Architecture".
// ═══════════════════════════════════════════════════════════════════

class ChatController extends Controller
{
    // ─────────────────────────────────────────────────────────────
    // SHOW CHAT PAGE
    // ─────────────────────────────────────────────────────────────

    /**
     * Show the main chat page, loading any existing conversation history.
     *
     * 📚 LEARNING: firstOrCreate() is an Eloquent convenience method.
     * It runs: SELECT ... WHERE session_id = ? LIMIT 1
     *   → If found: returns the existing Conversation
     *   → If not found: INSERTs a new one and returns it
     * This is an "upsert" pattern — one DB query instead of two.
     */
    public function index(Request $request)
    {
        // 📚 LEARNING: session()->getId() returns the unique ID Laravel assigned
        // to this browser's session (stored in a cookie). This is our lightweight
        // "user identity" before we add authentication in a later phase.
        $sessionId = $request->session()->getId();

        // Get or create the conversation for this browser session
        $conversation = Conversation::firstOrCreate(
            ['session_id' => $sessionId]
        );

        // 📚 LEARNING: Eager loading with ->with('messages') fetches the conversation
        // AND all its messages in a single efficient SQL query (a JOIN), instead of
        // running a separate query per message (the "N+1 problem").
        // orderBy('created_at') ensures messages appear oldest-first.
        $messages = $conversation->messages()->orderBy('created_at')->get();

        // ── Phase 3: Calculate cumulative token totals for this conversation ──
        // 📚 LEARNING: We sum the token columns across all AI messages in this conversation.
        // Only 'model' rows have token data; 'user' rows are NULL and are ignored by SUM().
        // This gives us a running total to display in the Token Stats Bar on page load.
        $tokenTotals = [
            'prompt'     => $conversation->messages()->sum('prompt_tokens'),
            'completion' => $conversation->messages()->sum('completion_tokens'),
            'total'      => $conversation->messages()->sum('total_tokens'),
        ];

        // Pass data to the Blade view
        return view('chat', compact('conversation', 'messages', 'tokenTotals'));
    }

    // ─────────────────────────────────────────────────────────────
    // HANDLE NEW MESSAGE
    // ─────────────────────────────────────────────────────────────

    /**
     * Receive a user message, persist it, build history, call Gemini, save reply.
     */
    public function send(Request $request)
    {
        $request->validate([
            'message' => 'required|string|max:2000',
        ]);

        $userMessage = $request->input('message');

        // ── Step 1: Get the current conversation ────────────────
        // 📚 LEARNING: We must look up the conversation BEFORE saving the new message
        // because we need the conversation_id as the FK for the message row.
        $sessionId    = $request->session()->getId();
        $conversation = Conversation::firstOrCreate(
            ['session_id' => $sessionId]
        );

        // ── Step 2: Save the user's message to the database ─────
        // 📚 LEARNING: We save the user message IMMEDIATELY (before calling the API).
        // This means if the API call fails, we still have a record that the user
        // asked something. This is important for audit trails in real apps.
        $conversation->messages()->create([
            'role'    => 'user',
            'content' => $userMessage,
        ]);

        // ── Step 3: Load the full conversation history ──────────
        // 📚 LEARNING: We reload ALL messages (including the one we just saved)
        // to build the complete history array we'll send to Gemini.
        // This includes the current user message as the last item.
        $allMessages = $conversation->messages()->orderBy('created_at')->get();

        // ── Step 4: Format history for Gemini API ───────────────
        //
        // 📚 LEARNING: This is the core of "context passing."
        // Gemini expects messages in this exact format:
        //
        // "contents": [
        //   { "role": "user",  "parts": [{ "text": "Hello" }] },
        //   { "role": "model", "parts": [{ "text": "Hi there!" }] },
        //   { "role": "user",  "parts": [{ "text": "What is PHP?" }] }  ← current message
        // ]
        //
        // Key differences from OpenAI:
        //   Gemini  → role: "model"    (for AI responses)
        //   OpenAI  → role: "assistant" (for AI responses)
        //
        // The "parts" array allows multi-modal inputs (text + images + etc.).
        // For text-only chat we always have one part: [{ "text": "..." }]
        //
        // By sending ALL previous messages, Gemini "remembers" the conversation.
        // Without this, every reply would be completely unrelated to what came before.
        $contents = $allMessages->map(function (Message $msg) {
            return [
                'role'  => $msg->role,
                'parts' => [
                    ['text' => $msg->content]
                ],
            ];
        })->values()->toArray();

        // ── Step 5: Call the Gemini API ─────────────────────────
        $apiKey = env('GEMINI_API_KEY');
        $model  = env('GEMINI_MODEL', 'gemini-2.0-flash');
        $url    = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

        try {
            $response = Http::timeout(30)->post($url, [
                'systemInstruction' => [
                    'parts' => [
                        [
                            'text' => 'You are a helpful, friendly AI assistant. ' .
                                      'Keep your responses concise, clear, and well-formatted. ' .
                                      'Use markdown when helpful (e.g. code blocks, bullet points).'
                        ]
                    ]
                ],

                // 📚 LEARNING: We now send the FULL conversation history here.
                // In Phase 1 this array had only 1 item (the current message).
                // In Phase 2 it has ALL messages — this is what gives the AI "memory".
                'contents' => $contents,

                'generationConfig' => [
                    'maxOutputTokens' => 1024,
                    'temperature'     => 0.7,
                ],
            ]);

            if ($response->successful()) {
                $data  = $response->json();
                $reply = $data['candidates'][0]['content']['parts'][0]['text']
                         ?? 'Sorry, I could not generate a response.';

                // ── Step 6 (Phase 3): Extract token usage from the Gemini response ──
                //
                // 📚 LEARNING: The Gemini API always returns a "usageMetadata" object.
                // It looks like this:
                //
                // "usageMetadata": {
                //   "promptTokenCount": 45,       ← ALL tokens sent (system + history + message)
                //   "candidatesTokenCount": 312,  ← tokens in the AI reply
                //   "totalTokenCount": 357         ← sum of both
                // }
                //
                // promptTokenCount is the KEY metric for understanding cost:
                //   → It GROWS with every message because we send the full history each time.
                //   → Message 1: prompt = 30 tokens (just the system prompt + 1 message)
                //   → Message 5: prompt = 200 tokens (system + 4 prior exchanges + new message)
                //
                // This is why long conversations get expensive — and why context windows matter!
                $usage            = $data['usageMetadata'] ?? [];
                $promptTokens     = $usage['promptTokenCount']     ?? null;
                $completionTokens = $usage['candidatesTokenCount'] ?? null;
                $totalTokens      = $usage['totalTokenCount']       ?? null;

                // ── Step 7: Save AI reply + token data to the database ──
                // 📚 LEARNING: We save tokens only on the 'model' row, not the 'user' row.
                // The user message is the INPUT — tokens are the cost of PROCESSING it.
                $conversation->messages()->create([
                    'role'              => 'model',
                    'content'          => $reply,
                    'prompt_tokens'     => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'total_tokens'      => $totalTokens,
                ]);

                // ── Step 8: Return reply + token data to the frontend ──
                // 📚 LEARNING: By returning token data in the JSON response,
                // the frontend can update the live stats bar WITHOUT a page refresh.
                // This is the "real-time" UX — no reload needed.
                return response()->json([
                    'reply'  => $reply,
                    'tokens' => [
                        'prompt'     => $promptTokens,
                        'completion' => $completionTokens,
                        'total'      => $totalTokens,
                    ],
                ]);
            }

            $errorMessage = $response->json('error.message') ?? 'The AI service returned an error.';
            return response()->json(['error' => $errorMessage], $response->status());

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return response()->json(
                ['error' => 'Could not connect to the AI service. Check your internet connection.'],
                503
            );
        } catch (\Exception $e) {
            return response()->json(
                ['error' => 'An unexpected error occurred. Please try again.'],
                500
            );
        }
    }

    // ─────────────────────────────────────────────────────────────
    // START A NEW CONVERSATION
    // ─────────────────────────────────────────────────────────────

    /**
     * Delete the current conversation (and all its messages) and redirect to a fresh chat.
     *
     * 📚 LEARNING: Because we set cascadeOnDelete() on the messages foreign key,
     * deleting the conversation automatically deletes ALL child message rows too.
     * We don't need a separate DELETE FROM messages query — the database handles it.
     *
     * This teaches an important DB design principle: let the database enforce
     * data integrity (referential integrity) rather than relying on your application code.
     */
    public function newConversation(Request $request)
    {
        $sessionId    = $request->session()->getId();
        $conversation = Conversation::where('session_id', $sessionId)->first();

        if ($conversation) {
            $conversation->delete(); // Cascades to messages automatically
        }

        // 📚 LEARNING: redirect('/') sends an HTTP 302 response to the browser,
        // which tells it to navigate to the homepage — starting a fresh conversation.
        return redirect('/');
    }
}

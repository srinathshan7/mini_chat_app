<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mini Chat — Gemini AI</title>
    <meta name="description" content="A simple AI chat application powered by Google Gemini. Built with Laravel for learning AI integration basics.">

    {{--
        📚 LEARNING: The @csrf directive generates a hidden input with a CSRF token.
        CSRF (Cross-Site Request Forgery) is a security attack where a malicious
        site tricks your browser into making requests to YOUR site.
        Laravel blocks any POST request that doesn't include this token.
        We embed it in a <meta> tag so our JavaScript can read it and send it
        with every fetch() request as the X-CSRF-TOKEN header.
    --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{--
        📚 LEARNING: @vite() tells Vite (the build tool) to inject the correct
        <link> and <script> tags for your CSS and JS files.
        In development mode, Vite serves files with hot module replacement (HMR).
        In production (after npm run build), it injects the hashed/minified files.
    --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    {{-- marked.js — lightweight markdown parser so AI responses render properly --}}
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

    {{-- Google Fonts --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
</head>
<body>

<div class="chat-app">

    {{-- ===== HEADER ===== --}}
    <header class="chat-header">
        <div class="header-content">
            <div class="header-brand">
                <div class="brand-icon">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2L13.5 8.5L20 10L13.5 11.5L12 18L10.5 11.5L4 10L10.5 8.5L12 2Z" fill="currentColor"/>
                        <path d="M19 17L19.75 19.25L22 20L19.75 20.75L19 23L18.25 20.75L16 20L18.25 19.25L19 17Z" fill="currentColor" opacity="0.6"/>
                        <path d="M5 2L5.5 3.5L7 4L5.5 4.5L5 6L4.5 4.5L3 4L4.5 3.5L5 2Z" fill="currentColor" opacity="0.4"/>
                    </svg>
                </div>
                <div class="brand-text">
                    <h1>Gemini Chat</h1>
                    <span class="brand-subtitle">Powered by Google Gemini 2.0 Flash</span>
                </div>
            </div>
            <div class="header-actions">
                {{--
                    📚 LEARNING — PHASE 2: "New Chat" button.
                    This submits a form with a POST request to /chat/new.
                    Why a <form> with POST instead of a simple <a href> link?
                    Because deleting data should NEVER be a GET request —
                    GET requests are meant to be safe/idempotent (no side effects).
                    A form POST is the correct HTTP verb for a state-changing action.
                --}}
                <form action="{{ route('chat.new') }}" method="POST" id="newChatForm">
                    @csrf
                    <button type="submit" class="new-chat-btn" id="newChatBtn" title="Start a new conversation">
                        <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" width="16" height="16">
                            <path d="M12 5v14M5 12h14" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"/>
                        </svg>
                        New Chat
                    </button>
                </form>
                <div class="header-status">
                    <span class="status-dot"></span>
                    <span>Online</span>
                </div>
            </div>
        </div>
    </header>

    {{--
        ===== PHASE 3: TOKEN STATS BAR =====

        📚 LEARNING: This bar shows cumulative token usage for the current conversation.
        $tokenTotals is passed from ChatController::index() — it's the SUM of all
        token columns across every AI message in this conversation.

        Key concept: "prompt_tokens" grows with every message because we send
        the full conversation history to Gemini each time. Watch this number
        climb as you have longer conversations!

        The data-* attributes let JavaScript update these numbers in real-time
        after each new message — no page refresh needed.
    --}}
    <div class="token-stats-bar" id="tokenStatsBar">
        <div class="token-stats-inner">
            <span class="token-stats-label">
                <svg viewBox="0 0 24 24" fill="none" width="13" height="13">
                    <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                Token Usage
            </span>
            <div class="token-stats-pills">
                <div class="token-pill token-pill--prompt" title="Tokens sent to Gemini (grows as conversation grows)">
                    <span class="token-pill-icon">↑</span>
                    <span class="token-pill-label">Prompt</span>
                    <span class="token-pill-value" id="statPrompt">{{ $tokenTotals['prompt'] }}</span>
                </div>
                <div class="token-pill token-pill--completion" title="Tokens in AI replies">
                    <span class="token-pill-icon">↓</span>
                    <span class="token-pill-label">Reply</span>
                    <span class="token-pill-value" id="statCompletion">{{ $tokenTotals['completion'] }}</span>
                </div>
                <div class="token-pill token-pill--total" title="Total tokens used this conversation">
                    <span class="token-pill-icon">Σ</span>
                    <span class="token-pill-label">Total</span>
                    <span class="token-pill-value" id="statTotal">{{ $tokenTotals['total'] }}</span>
                </div>
            </div>
            <span class="token-stats-hint">📚 Prompt tokens grow as history grows &mdash; <a href="https://ai.google.dev/gemini-api/docs/tokens" target="_blank" class="token-learn-link">learn why</a></span>
        </div>
    </div>

    {{-- ===== MESSAGES AREA ===== --}}
    <main class="chat-messages" id="chatMessages">

        {{--
            ===== PHASE 2: RENDER SAVED HISTORY FROM DATABASE =====

            📚 LEARNING: $messages is the Collection of Message models passed from
            ChatController::index(). We loop over them with @foreach and render
            each one as a chat bubble.

            This is the key difference from Phase 1:
            - Phase 1: Page always starts empty (history lost on refresh)
            - Phase 2: Page loads with the full saved conversation history

            The @if($messages->isEmpty()) block shows the welcome message
            only when there's no prior history — otherwise history fills the screen.
        --}}

        @if($messages->isEmpty())
            {{-- Welcome message shown only on a fresh conversation --}}
            <div class="message-row ai-row" id="welcomeMessage">
                <div class="avatar ai-avatar">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2L13.5 8.5L20 10L13.5 11.5L12 18L10.5 11.5L4 10L10.5 8.5L12 2Z" fill="currentColor"/>
                    </svg>
                </div>
                <div class="bubble ai-bubble">
                    <p>Hello! 👋 I'm your <strong>Gemini AI assistant</strong>. Ask me anything — I'm here to help.</p>
                    <p style="margin-top: 8px; opacity: 0.7; font-size: 0.8rem;">
                        ⚡ <em>Phase 3 active — watch the <strong>Token Usage</strong> bar above update in real-time as we chat!</em>
                    </p>
                </div>
            </div>
        @else
            {{--
                📚 LEARNING: We render saved messages from the database here.
                Each $message has: $message->role ('user' or 'model') and $message->content.
                We use the role to decide which CSS class and avatar to apply.

                Note: Blade's {{ }} syntax auto-escapes HTML (safe for user content).
                We use {!! !!} for AI content because we want markdown rendered as HTML.
                This is intentional — AI output is trusted, user input is not.
            --}}
            @foreach($messages as $message)
                @if($message->role === 'user')
                    <div class="message-row user-row">
                        <div class="avatar user-avatar">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                            </svg>
                        </div>
                        <div class="bubble user-bubble">{{ $message->content }}</div>
                    </div>
                @else
                    <div class="message-row ai-row">
                        <div class="avatar ai-avatar">
                            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 2L13.5 8.5L20 10L13.5 11.5L12 18L10.5 11.5L4 10L10.5 8.5L12 2Z" fill="currentColor"/>
                            </svg>
                        </div>
                        {{--
                            📚 LEARNING: marked.parse() runs client-side (in the browser) via JS.
                            For server-rendered history, we output the raw markdown from DB
                            and let JavaScript convert it on DOMContentLoaded.
                            We add data-markdown="true" so app.js knows to parse this bubble.
                        --}}
                        <div class="bubble ai-bubble" data-markdown="true">{{ $message->content }}</div>
                    </div>
                @endif
            @endforeach
        @endif

    </main>

    {{-- ===== TYPING INDICATOR (hidden by default) ===== --}}
    <div class="typing-row" id="typingIndicator" style="display: none;">
        <div class="avatar ai-avatar small">
            <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M12 2L13.5 8.5L20 10L13.5 11.5L12 18L10.5 11.5L4 10L10.5 8.5L12 2Z" fill="currentColor"/>
            </svg>
        </div>
        <div class="typing-bubble">
            <span class="dot"></span>
            <span class="dot"></span>
            <span class="dot"></span>
        </div>
    </div>

    {{-- ===== INPUT AREA ===== --}}
    <footer class="chat-input-area">
        <form class="chat-form" id="chatForm">
            {{--
                📚 LEARNING: @csrf inside a <form> generates:
                <input type="hidden" name="_token" value="...">
                For our AJAX fetch() approach, we read the token from the <meta> tag instead.
                But it's good practice to include it here too for non-JS fallback.
            --}}
            @csrf

            <div class="input-wrapper">
                <textarea
                    id="messageInput"
                    name="message"
                    class="message-textarea"
                    placeholder="Ask me anything… (Enter to send, Shift+Enter for new line)"
                    rows="1"
                    maxlength="2000"
                    autocomplete="off"
                ></textarea>
                <button type="submit" class="send-btn" id="sendButton" aria-label="Send message">
                    <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22 2L11 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M22 2L15 22L11 13L2 9L22 2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </div>
            <p class="input-hint">
                ⚡ Phase 3 active &mdash; token usage tracked above. Click <strong>New Chat</strong> to reset.
            </p>
        </form>
    </footer>

</div>

</body>
</html>

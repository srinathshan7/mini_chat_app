/**
 * Mini Chat App — Frontend Logic (Phase 3: Token Tracking)
 *
 * 📚 LEARNING: Phase 3 changes in this file:
 *   1. After each AI reply, read data.tokens from the JSON response
 *   2. Append a token badge row under the AI bubble (per-message cost)
 *   3. Update the cumulative Token Stats Bar (running totals, with flash animation)
 *
 * Key concept: We track TWO levels of token data:
 *   Per-message  → shown as a badge under each AI bubble
 *   Cumulative   → shown in the stats bar (sum of all messages this session)
 *
 * Both update in real-time via JavaScript — no page refresh needed.
 */

// ── Wait for the DOM to be fully loaded before running our code ──
document.addEventListener('DOMContentLoaded', () => {

    // ── Grab references to the elements we need ──
    const chatForm        = document.getElementById('chatForm');
    const messageInput    = document.getElementById('messageInput');
    const chatMessages    = document.getElementById('chatMessages');
    const sendButton      = document.getElementById('sendButton');
    const typingIndicator = document.getElementById('typingIndicator');

    // ── Phase 3: Token Stats Bar element references ──
    // 📚 LEARNING: We grab these once on load and update them in updateTokenStats().
    // Reading the DOM once and caching the reference is faster than calling
    // getElementById() repeatedly (avoids repeated DOM traversal).
    const statPrompt     = document.getElementById('statPrompt');
    const statCompletion = document.getElementById('statCompletion');
    const statTotal      = document.getElementById('statTotal');

    // Running cumulative totals (start from whatever was loaded from DB in Blade)
    // 📚 LEARNING: parseInt(el.textContent) reads the server-rendered DB value.
    // This seeds our JS counters so they stay in sync with the DB from the start.
    let cumulativePrompt     = parseInt(statPrompt.textContent)     || 0;
    let cumulativeCompletion = parseInt(statCompletion.textContent) || 0;
    let cumulativeTotal      = parseInt(statTotal.textContent)      || 0;

    // ─────────────────────────────────────────────────────────────
    // PHASE 2: PARSE MARKDOWN IN SERVER-RENDERED HISTORY
    //
    // 📚 LEARNING: When the page loads, the Blade template renders saved
    // AI messages as raw markdown text (from the database).
    // We find all bubbles with [data-markdown="true"] and run marked.parse()
    // on their text content to convert markdown → HTML.
    //
    // Why not do this in PHP/Blade directly?
    // We could, but using JS marked.js keeps the same rendering pipeline
    // as new messages appended dynamically — consistency is important.
    // ─────────────────────────────────────────────────────────────
    document.querySelectorAll('[data-markdown="true"]').forEach(bubble => {
        const rawText = bubble.textContent;
        bubble.innerHTML = marked.parse(rawText);
    });

    // ─────────────────────────────────────────────────────────────
    // PHASE 2: AUTO-SCROLL ON PAGE LOAD
    // If there's conversation history, scroll to the bottom immediately.
    // ─────────────────────────────────────────────────────────────
    scrollToBottom();

    // ─────────────────────────────────────────────────────────────
    // AUTO-RESIZE TEXTAREA
    // Makes the input grow vertically as the user types, up to a max height.
    // ─────────────────────────────────────────────────────────────
    messageInput.addEventListener('input', () => {
        messageInput.style.height = 'auto';
        messageInput.style.height = Math.min(messageInput.scrollHeight, 160) + 'px';
    });

    // ─────────────────────────────────────────────────────────────
    // KEYBOARD SHORTCUT: Enter to send, Shift+Enter for new line
    // ─────────────────────────────────────────────────────────────
    messageInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault(); // Stop the default newline behavior
            chatForm.requestSubmit(); // Programmatically submit the form
        }
    });

    // ─────────────────────────────────────────────────────────────
    // FORM SUBMIT HANDLER — The heart of the chat logic
    // ─────────────────────────────────────────────────────────────
    chatForm.addEventListener('submit', async (e) => {
        // 📚 LEARNING: preventDefault() stops the browser from doing a full-page
        // form submission (which would refresh the page). We handle it ourselves with fetch().
        e.preventDefault();

        const message = messageInput.value.trim();

        // ── Basic frontend validation ──
        if (!message) return;

        // ── 1. Render user's message immediately (Optimistic UI) ──
        // 📚 LEARNING: "Optimistic UI" = show the result immediately before the server confirms.
        // This makes the app feel fast and responsive.
        appendMessage(message, 'user');

        // ── 2. Clear the input and reset its height ──
        messageInput.value = '';
        messageInput.style.height = 'auto';

        // ── 3. Disable the send button + show typing indicator while waiting ──
        setLoading(true);

        try {
            // ── 4. Send the message to our Laravel backend ──
            //
            // 📚 LEARNING: Notice we still only send { message: "..." } — not the history!
            // The backend fetches history from the DB and builds the Gemini payload.
            // This is "separation of concerns": the frontend doesn't need to know about
            // conversation management — that's the backend's job.
            const response = await fetch('/chat/send', {
                method: 'POST',
                headers: {
                    'Content-Type':  'application/json',
                    'Accept':        'application/json',
                    'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                },
                body: JSON.stringify({ message }),
            });

            // ── 5. Parse the JSON response ──
            // 📚 LEARNING: response.json() reads the response body and parses it from
            // a JSON string back into a JavaScript object. It returns a Promise, so we await it.
            const data = await response.json();

            // ── 6. Handle the result ──
            if (response.ok && data.reply) {
                // ✅ Success — show the AI's reply
                appendMessage(data.reply, 'ai');

                // ── Phase 3: Append per-message token badge ──
                // 📚 LEARNING: data.tokens is the token breakdown for THIS specific message.
                // We show it under the AI bubble as a "cost receipt" for that exchange.
                if (data.tokens) {
                    appendTokenBadge(data.tokens);
                    // Update the running totals in the stats bar
                    updateTokenStats(data.tokens);
                }

            } else {
                // ❌ API-level error — the server responded but with an error message
                const errorText = data.error || 'Something went wrong. Please try again.';
                appendMessage(`⚠️ **Error:** ${errorText}`, 'error');
            }

        } catch (networkError) {
            // ❌ Network error — no response at all (offline, server down, etc.)
            console.error('Network error:', networkError);
            appendMessage('⚠️ **Network Error:** Could not reach the server. Are you online?', 'error');
        } finally {
            // 📚 LEARNING: The 'finally' block always runs, whether the try succeeded or failed.
            // This is the right place to clean up state (re-enable the button, hide the spinner).
            setLoading(false);
        }
    });

    // ─────────────────────────────────────────────────────────────
    // HELPER: Append a message bubble to the chat
    // ─────────────────────────────────────────────────────────────
    /**
     * @param {string} text    - The message text (may contain markdown for AI messages)
     * @param {'user'|'ai'|'error'} type - Who sent the message
     */
    function appendMessage(text, type) {
        const row    = document.createElement('div');
        const bubble = document.createElement('div');

        row.classList.add('message-row');

        if (type === 'user') {
            row.classList.add('user-row');
            bubble.classList.add('bubble', 'user-bubble');

            // Add user avatar
            row.appendChild(createAvatar('user'));

            // User messages are plain text — escape HTML to prevent XSS
            // 📚 LEARNING: XSS (Cross-Site Scripting) is when malicious script is injected via user input.
            // Never use innerHTML with user-supplied text! Use textContent instead.
            bubble.textContent = text;

        } else if (type === 'ai') {
            row.classList.add('ai-row');
            bubble.classList.add('bubble', 'ai-bubble');

            // Add AI avatar
            row.appendChild(createAvatar('ai'));

            // AI responses may contain markdown — we render it with marked.js
            // 📚 LEARNING: marked.parse() converts markdown syntax to HTML.
            bubble.innerHTML = marked.parse(text);

        } else if (type === 'error') {
            row.classList.add('ai-row');
            bubble.classList.add('bubble', 'error-bubble');
            row.appendChild(createAvatar('ai'));
            bubble.innerHTML = marked.parse(text);
        }

        row.appendChild(bubble);
        chatMessages.appendChild(row);

        // Auto-scroll to the latest message
        scrollToBottom();
    }

    // ─────────────────────────────────────────────────────────────
    // HELPER: Create an avatar element
    // ─────────────────────────────────────────────────────────────
    function createAvatar(type) {
        const avatar = document.createElement('div');
        avatar.classList.add('avatar');

        if (type === 'ai') {
            avatar.classList.add('ai-avatar');
            avatar.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L13.5 8.5L20 10L13.5 11.5L12 18L10.5 11.5L4 10L10.5 8.5L12 2Z" fill="currentColor"/>
                </svg>`;
        } else {
            avatar.classList.add('user-avatar');
            avatar.innerHTML = `
                <svg viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <circle cx="12" cy="7" r="4" stroke="currentColor" stroke-width="2"/>
                </svg>`;
        }
        return avatar;
    }

    // ─────────────────────────────────────────────────────────────
    // HELPER: Toggle loading state
    // ─────────────────────────────────────────────────────────────
    function setLoading(isLoading) {
        sendButton.disabled           = isLoading;
        messageInput.disabled         = isLoading;
        typingIndicator.style.display = isLoading ? 'flex' : 'none';

        if (isLoading) scrollToBottom();
    }

    // ─────────────────────────────────────────────────────────────
    // HELPER: Scroll to the bottom of the chat
    // ─────────────────────────────────────────────────────────────
    function scrollToBottom() {
        // Use requestAnimationFrame to scroll AFTER the DOM has been updated
        requestAnimationFrame(() => {
            chatMessages.scrollTop = chatMessages.scrollHeight;
        });
    }

    // ─────────────────────────────────────────────────────────────
    // PHASE 3 HELPER: Append a token badge below the latest AI bubble
    //
    // 📚 LEARNING: This function appends a small row of pills showing
    // the token cost of the LAST exchange (prompt + reply + total).
    //
    // It targets the LAST .message-row in the chat (which is always the
    // AI response we just received) and inserts the badge AFTER it.
    //
    // Token data shape: { prompt: 45, completion: 312, total: 357 }
    // ─────────────────────────────────────────────────────────────
    function appendTokenBadge(tokens) {
        if (!tokens || (!tokens.prompt && !tokens.completion)) return;

        const badge = document.createElement('div');
        badge.className = 'message-token-badge';
        badge.innerHTML = `
            <span class="token-badge-pill token-badge-pill--prompt"
                  title="Tokens sent to Gemini this turn (includes all prior history)">
                ↑ ${tokens.prompt ?? '?'} prompt
            </span>
            <span class="token-badge-pill token-badge-pill--reply"
                  title="Tokens in this AI reply">
                ↓ ${tokens.completion ?? '?'} reply
            </span>
            <span class="token-badge-pill token-badge-pill--total"
                  title="Total tokens for this turn">
                Σ ${tokens.total ?? '?'} total
            </span>
        `;

        // Insert the badge AFTER the last message row in the chat
        chatMessages.appendChild(badge);
        scrollToBottom();
    }

    // ─────────────────────────────────────────────────────────────
    // PHASE 3 HELPER: Update the cumulative Token Stats Bar
    //
    // 📚 LEARNING: We update the running totals in the stats bar.
    // We use a "flash" CSS animation to draw the user's eye to
    // which number just changed — a micro-interaction technique.
    //
    // The trick: add the .flash class, then REMOVE it after the animation
    // ends so it can be re-added (and re-triggered) next time.
    // ─────────────────────────────────────────────────────────────
    function updateTokenStats(tokens) {
        if (!tokens) return;

        // Increment running totals
        cumulativePrompt     += (tokens.prompt     ?? 0);
        cumulativeCompletion += (tokens.completion ?? 0);
        cumulativeTotal      += (tokens.total      ?? 0);

        // Update DOM values
        statPrompt.textContent     = cumulativePrompt;
        statCompletion.textContent = cumulativeCompletion;
        statTotal.textContent      = cumulativeTotal;

        // Flash animation: remove then re-add the class so it re-triggers
        // 📚 LEARNING: void el.offsetWidth forces a reflow — this is a browser
        // trick to "restart" a CSS animation on an element that already has the class.
        [statPrompt, statCompletion, statTotal].forEach(el => {
            el.classList.remove('flash');
            void el.offsetWidth; // force reflow
            el.classList.add('flash');
        });
    }

    // ── Focus the input on page load ──
    messageInput.focus();
});

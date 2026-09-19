{{-- Floating shop assistant. Sits above the page on every storefront view. --}}

<div id="chatWidget">

    <button type="button" id="chatToggle" class="chat-bubble" aria-expanded="false"
            aria-controls="chatPanel" aria-label="Ask the shop assistant">
        <span id="chatToggleIcon" aria-hidden="true">&#128172;</span>
    </button>

    <section id="chatPanel" class="chat-panel" hidden aria-live="polite">

        <header class="chat-head">
            <div>
                <div class="chat-title">{{ config('app.name') }} assistant</div>
                <div class="chat-sub">Stock, prices and delivery</div>
            </div>
            <button type="button" class="chat-x" id="chatClose" aria-label="Close">&times;</button>
        </header>

        <div class="chat-log" id="chatLog">
            <div class="chat-msg from-bot">
                Hi! Ask me what we have in stock, what delivery costs, or where your order is.
            </div>
        </div>

        <form class="chat-form" id="chatForm" autocomplete="off">
            <input type="text" id="chatInput" maxlength="1000"
                   placeholder="Ask about products or delivery…" aria-label="Your message" required>
            <button type="submit" class="chat-send" id="chatSend" aria-label="Send">&#10148;</button>
        </form>

    </section>

</div>

<style>
    .chat-bubble {
        position: fixed; bottom: 20px; right: 20px; z-index: 1050;
        width: 58px; height: 58px; border-radius: 50%;
        border: 0; background: var(--brand); color: #fff;
        font-size: 24px; line-height: 1;
        box-shadow: 0 10px 28px rgba(12, 28, 20, .26);
        display: grid; place-items: center;
        transition: transform .15s ease, background .15s ease;
    }
    .chat-bubble:hover { background: var(--brand-2); transform: translateY(-2px); }

    .chat-panel {
        position: fixed; bottom: 88px; right: 20px; z-index: 1050;
        width: min(370px, calc(100vw - 32px));
        height: min(520px, calc(100vh - 140px));
        background: var(--surface);
        border: 1px solid var(--line);
        border-radius: var(--r-lg);
        box-shadow: var(--sh-3);
        display: flex; flex-direction: column; overflow: hidden;
    }

    .chat-head {
        display: flex; justify-content: space-between; align-items: center; gap: 10px;
        padding: 14px 16px;
        background: var(--brand); color: #fff;
    }
    .chat-title { font-weight: 800; font-size: 15px; letter-spacing: -.02em; }
    .chat-sub { font-size: 12px; opacity: .85; }

    .chat-x {
        border: 0; background: transparent; color: #fff;
        font-size: 24px; line-height: 1; padding: 0 4px;
    }

    .chat-log {
        flex: 1; overflow-y: auto;
        padding: 14px; display: flex; flex-direction: column; gap: 10px;
        background: var(--bg);
    }

    .chat-msg {
        max-width: 85%; padding: 9px 13px; border-radius: 15px;
        font-size: 14px; line-height: 1.55;
        white-space: pre-wrap; word-wrap: break-word;
    }
    .from-bot {
        align-self: flex-start; background: var(--surface);
        border: 1px solid var(--line); border-bottom-left-radius: 5px;
        color: var(--ink);
    }
    .from-me {
        align-self: flex-end; background: var(--brand); color: #fff;
        border-bottom-right-radius: 5px;
    }
    .from-bot.is-error { border-color: var(--bad); color: var(--bad); }

    /* What the assistant offers to do. A button the customer presses —
       the assistant never changes the cart on its own. */
    .chat-action {
        align-self: flex-start; max-width: 85%;
        display: flex; align-items: center; gap: 8px;
        padding: 9px 14px; border-radius: 999px;
        border: 1px solid var(--brand); background: var(--brand-wash);
        color: var(--brand-2); font-size: 13.5px; font-weight: 700;
        font-family: inherit; text-align: left; cursor: pointer;
    }
    .chat-action:hover { background: var(--brand); color: #fff; }
    .chat-action:disabled { opacity: .55; cursor: default; }
    .chat-action .price { font-weight: 600; opacity: .8; }

    .chat-typing { display: inline-flex; gap: 4px; }
    .chat-typing span {
        width: 6px; height: 6px; border-radius: 50%; background: var(--ink-3);
        animation: chatBlink 1.2s infinite;
    }
    .chat-typing span:nth-child(2) { animation-delay: .2s; }
    .chat-typing span:nth-child(3) { animation-delay: .4s; }
    @keyframes chatBlink { 0%, 60%, 100% { opacity: .25 } 30% { opacity: 1 } }

    .chat-form {
        display: flex; gap: 8px; padding: 12px;
        border-top: 1px solid var(--line); background: var(--surface);
    }
    .chat-form input {
        flex: 1; min-width: 0;
        border: 1px solid var(--line-2); border-radius: 999px;
        padding: 10px 15px; font-size: 14px; font-family: inherit;
        background: var(--surface); color: var(--ink);
    }
    .chat-form input:focus { outline: 0; border-color: var(--brand); box-shadow: 0 0 0 3px var(--ring); }

    .chat-send {
        flex: 0 0 40px; width: 40px; height: 40px; border-radius: 50%;
        border: 0; background: var(--brand); color: #fff; font-size: 15px;
    }
    .chat-send:disabled { opacity: .5; }
</style>

<script>
window.siteLang = @json(app()->getLocale() === 'kh' ? 'kh' : 'en');

(function () {
    const toggle = document.getElementById('chatToggle');
    const icon = document.getElementById('chatToggleIcon');
    const panel = document.getElementById('chatPanel');
    const close = document.getElementById('chatClose');
    const form = document.getElementById('chatForm');
    const input = document.getElementById('chatInput');
    const send = document.getElementById('chatSend');
    const log = document.getElementById('chatLog');

    const endpoint = @json(route('chat.handle'));
    const csrf = @json(csrf_token());

    function open(state) {
        panel.hidden = !state;
        toggle.setAttribute('aria-expanded', String(state));
        icon.innerHTML = state ? '&times;' : '&#128172;';
        if (state) input.focus();
    }

    toggle.addEventListener('click', () => open(panel.hidden));
    close.addEventListener('click', () => open(false));

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !panel.hidden) open(false);
    });

    function bubble(text, who, isError) {
        const div = document.createElement('div');
        div.className = 'chat-msg from-' + who + (isError ? ' is-error' : '');
        // textContent, never innerHTML: the reply is text from a model and
        // must never be able to put markup into the page.
        div.textContent = text;
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }

    function typing() {
        const div = document.createElement('div');
        div.className = 'chat-msg from-bot';
        div.innerHTML = '<span class="chat-typing"><span></span><span></span><span></span></span>';
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
        return div;
    }

    /**
     * Render an offer the assistant made.
     *
     * Pressing it posts to the ordinary cart route, so the same validation,
     * stock check and CSRF protection apply as anywhere else on the site.
     */
    function addAction(action) {
        if (!action || action.type !== 'add_to_cart') return;

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'chat-action';

        const label = document.createElement('span');
        label.textContent = action.label;

        const price = document.createElement('span');
        price.className = 'price';
        price.textContent = action.price;

        button.append(label, price);

        button.addEventListener('click', async function () {
            button.disabled = true;

            const body = new FormData();
            body.append('_token', csrf);
            body.append('quantity', action.quantity);

            try {
                const res = await fetch(action.url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: body,
                });

                if (res.ok || res.redirected) {
                    label.textContent = 'Added to cart ✓';
                    price.textContent = '';
                    // The cart badge in the header is rendered server-side,
                    // so reload it rather than letting it go stale.
                    setTimeout(() => window.location.reload(), 700);
                } else {
                    label.textContent = 'Could not add that';
                    button.disabled = false;
                }
            } catch (e) {
                label.textContent = 'No connection';
                button.disabled = false;
            }
        });

        log.appendChild(button);
        log.scrollTop = log.scrollHeight;
    }

    /** The basket, for a guest whose cart lives only in this browser. */
    function cartPayload() {
        try {
            const raw = localStorage.getItem('cart');
            const parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed.slice(0, 100) : [];
        } catch (e) {
            return [];
        }
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();

        const message = input.value.trim();
        if (!message) return;

        bubble(message, 'me');
        input.value = '';
        send.disabled = true;

        const dots = typing();

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({
                    message: message,
                    lang: window.siteLang,
                    cart: cartPayload(),
                }),
            });

            const data = await response.json().catch(() => null);
            dots.remove();

            if (data && data.reply) {
                bubble(data.reply, 'bot', response.status >= 400);
                (data.actions || []).forEach(addAction);
            } else {
                bubble('Sorry, something went wrong. Please try again.', 'bot', true);
            }
        } catch (e) {
            dots.remove();
            bubble('No connection. Please check your internet and try again.', 'bot', true);
        } finally {
            send.disabled = false;
            input.focus();
        }
    });
})();
</script>

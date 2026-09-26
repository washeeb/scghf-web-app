/**
 * The live-chat widget.
 *
 * Three states — closed, the pre-chat form, the conversation — and four
 * endpoints (routes/web.php, "Live chat"). The conversation's id and token
 * live in localStorage so a visitor who reloads, or comes back tomorrow,
 * finds the same chat. While the panel is open the page asks for new lines
 * every four seconds; while it is closed, every twenty, to keep the unread
 * badge honest without hammering a shared server.
 *
 * No framework and no eval: the public site's Content-Security-Policy is
 * strict, and this must run on a low-end phone over a slow connection.
 */

const STORE = 'scghf_chat';
const POLL_OPEN_MS = 4000;
const POLL_CLOSED_MS = 20000;

function stored() {
    try {
        const raw = localStorage.getItem(STORE);
        const value = raw ? JSON.parse(raw) : null;

        return value && typeof value.id === 'string' && typeof value.token === 'string' ? value : null;
    } catch {
        return null;
    }
}

function remember(value) {
    try {
        if (value) {
            localStorage.setItem(STORE, JSON.stringify(value));
        } else {
            localStorage.removeItem(STORE);
        }
    } catch {
        // Private mode: the chat still works for this page view.
    }
}

export function initChat() {
    const root = document.querySelector('[data-chat]');

    if (!root) {
        return;
    }

    const el = {
        launcher: root.querySelector('[data-chat-launcher]'),
        panel: root.querySelector('[data-chat-panel]'),
        close: root.querySelector('[data-chat-close]'),
        end: root.querySelector('[data-chat-end]'),
        form: root.querySelector('[data-chat-form]'),
        awayNote: root.querySelector('[data-chat-away-note]'),
        error: root.querySelector('[data-chat-error]'),
        thread: root.querySelector('[data-chat-thread]'),
        messages: root.querySelector('[data-chat-messages]'),
        reply: root.querySelector('[data-chat-reply]'),
        closed: root.querySelector('[data-chat-closed]'),
        newChat: root.querySelector('[data-chat-new]'),
        status: root.querySelector('[data-chat-status]'),
        dot: root.querySelector('[data-chat-dot]'),
        unread: root.querySelector('[data-chat-unread]'),
        agentBar: root.querySelector('[data-chat-agent-bar]'),
        agentName: root.querySelector('[data-chat-agent-name]'),
        human: root.querySelector('[data-chat-human]'),
        typing: root.querySelector('[data-chat-typing]'),
    };

    let labels = {};
    try {
        labels = JSON.parse(root.querySelector('[data-chat-labels]')?.textContent || '{}');
    } catch {
        labels = {};
    }

    const startUrl = root.dataset.chatStartUrl;
    const baseUrl = root.dataset.chatBaseUrl;
    const csrf = root.dataset.chatCsrf;

    let session = stored();
    let lastId = 0;
    let open = false;
    let timer = null;
    let unread = 0;
    let closedState = false;
    let agentActive = false;

    const headers = (withToken = true) => {
        const h = { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' };
        if (withToken && session) {
            h['X-Chat-Token'] = session.token;
        }

        return h;
    };

    function setOnline(online) {
        el.status.textContent = online ? labels.online : labels.away;
        el.dot.classList.toggle('bg-green-300', online);
        el.dot.classList.toggle('bg-white/50', !online);
        if (el.awayNote) {
            el.awayNote.hidden = online;
        }
    }

    function showForm() {
        el.form.hidden = false;
        el.thread.hidden = true;
        el.end.hidden = true;
    }

    function showThread() {
        el.form.hidden = true;
        el.thread.hidden = false;
        el.end.hidden = closedState;
        el.reply.hidden = closedState;
        el.closed.hidden = !closedState;
        setAgent(agentActive);
    }

    /**
     * Who the visitor is talking to.
     *
     * The bar with "talk to a person" is shown for exactly as long as the
     * assistant is answering, and disappears the moment a person has it —
     * at which point the button would be a lie.
     */
    function setAgent(active) {
        agentActive = Boolean(active);

        if (el.agentBar) {
            el.agentBar.hidden = !agentActive || closedState;
        }

        if (el.agentName && labels.agentStatus) {
            el.agentName.textContent = labels.agentStatus;
        }
    }

    function setTyping(on) {
        if (el.typing) {
            el.typing.hidden = !on;
        }
    }

    function append(message) {
        if (message.id <= lastId) {
            return;
        }

        lastId = message.id;

        const li = document.createElement('li');
        const mine = message.sender === 'visitor';
        const system = message.sender === 'system';
        const fromAgent = message.sender === 'agent';

        // The assistant's bubble is deliberately not the same as a person's:
        // a dashed edge and the assistant's name on every line, so nobody
        // scrolling back has to work out which of them said what.
        li.className = system
            ? 'self-center rounded-full bg-[var(--surface-sunken)] px-3 py-1 text-center text-xs text-[var(--text-muted)]'
            : (mine
                ? 'max-w-[85%] self-end rounded-[var(--radius-lg)] rounded-br-sm bg-[var(--brand-primary)] px-3 py-2 text-[var(--text-on-brand)]'
                : (fromAgent
                    ? 'max-w-[85%] self-start rounded-[var(--radius-lg)] rounded-bl-sm border border-dashed border-[var(--border-interactive)] bg-[var(--surface)] px-3 py-2'
                    : 'max-w-[85%] self-start rounded-[var(--radius-lg)] rounded-bl-sm bg-[var(--surface-sunken)] px-3 py-2'));

        if (!system) {
            const who = document.createElement('span');
            who.className = 'block text-[0.7rem] font-semibold opacity-80';
            who.textContent = mine ? labels.you : (message.name || (fromAgent ? labels.agent : labels.office));
            li.appendChild(who);
        }

        const body = document.createElement('span');
        body.className = 'whitespace-pre-wrap break-words';
        body.textContent = message.body;
        li.appendChild(body);

        el.messages.appendChild(li);
        el.messages.scrollTop = el.messages.scrollHeight;

        if (!open && !mine && !system) {
            unread += 1;
            el.unread.textContent = String(unread);
            el.unread.hidden = false;
        }
    }

    async function poll() {
        if (!session) {
            return;
        }

        try {
            const response = await fetch(`${baseUrl}/${session.id}/messages?after=${lastId}`, { headers: headers() });

            if (response.status === 404) {
                // The conversation is gone (retention, or a token that no longer matches): start afresh.
                reset();

                return;
            }

            if (!response.ok) {
                return;
            }

            const data = await response.json();
            setOnline(Boolean(data.online));
            setAgent(data.agent && data.agent.active);
            (data.messages || []).forEach(append);

            if (data.status === 'closed' && !closedState) {
                closedState = true;
                showThread();
            }
        } catch {
            // Offline for a moment; the next poll will try again.
        }
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            await poll();
            schedule();
        }, open ? POLL_OPEN_MS : POLL_CLOSED_MS);
    }

    async function refreshStatus() {
        try {
            const response = await fetch(`${baseUrl}/status`, { headers: headers(false) });
            if (response.ok) {
                const data = await response.json();
                setOnline(Boolean(data.online));
            }
        } catch {
            // Leave whatever the page said.
        }
    }

    function reset() {
        session = null;
        remember(null);
        lastId = 0;
        closedState = false;
        el.messages.replaceChildren();
        showForm();
    }

    function setOpen(next) {
        open = next;
        el.panel.hidden = !open;
        el.launcher.setAttribute('aria-expanded', open ? 'true' : 'false');

        if (open) {
            unread = 0;
            el.unread.hidden = true;

            if (session) {
                showThread();
                el.reply.querySelector('textarea')?.focus();
            } else {
                showForm();
                refreshStatus();
                (el.form.querySelector('input[name=name]') || el.form.querySelector('textarea'))?.focus();
            }

            poll();
        } else {
            el.launcher.focus();
        }

        schedule();
    }

    // ── Wiring ────────────────────────────────────────────────────────────

    el.launcher.addEventListener('click', () => setOpen(!open));
    el.close.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && open) {
            setOpen(false);
        }
    });

    el.form.addEventListener('submit', async (event) => {
        event.preventDefault();
        el.error.hidden = true;

        const fields = new FormData(el.form);
        const payload = {
            name: fields.get('name') || root.dataset.chatUserName || '',
            email: fields.get('email') || root.dataset.chatUserEmail || '',
            message: fields.get('message') || '',
            website: fields.get('website') || '',
            page: window.location.href,
        };

        setTyping(true);

        try {
            const response = await fetch(startUrl, { method: 'POST', headers: headers(false), body: JSON.stringify(payload) });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                el.error.textContent = data.message || labels.failed;
                el.error.hidden = false;

                return;
            }

            session = { id: data.id, token: data.token };
            remember(session);
            closedState = false;
            el.messages.replaceChildren();
            lastId = 0;
            setAgent(data.agent && data.agent.active);
            (data.messages || []).forEach(append);
            setOnline(Boolean(data.online));
            el.form.reset();
            showThread();
            el.reply.querySelector('textarea')?.focus();
            schedule();
        } catch {
            el.error.textContent = labels.failed;
            el.error.hidden = false;
        } finally {
            setTyping(false);
        }
    });

    el.reply.addEventListener('submit', async (event) => {
        event.preventDefault();
        const textarea = el.reply.querySelector('textarea');
        const body = (textarea.value || '').trim();

        if (!body || !session) {
            return;
        }

        textarea.disabled = true;
        setTyping(agentActive);

        try {
            const response = await fetch(`${baseUrl}/${session.id}/messages`, { method: 'POST', headers: headers(), body: JSON.stringify({ body }) });

            if (response.status === 409) {
                closedState = true;
                showThread();

                return;
            }

            if (response.ok) {
                const data = await response.json();
                append(data.message);
                textarea.value = '';
                setAgent(data.agent && data.agent.active);

                // The assistant's answer was written while that request was in
                // flight; fetch it now rather than leaving the visitor watching
                // nothing for up to four seconds.
                await poll();
            }
        } finally {
            setTyping(false);
            textarea.disabled = false;
            textarea.focus();
        }
    });

    // Enter sends; Shift+Enter is a new line.
    el.reply.querySelector('textarea')?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            el.reply.requestSubmit();
        }
    });

    el.end.addEventListener('click', async () => {
        if (!session) {
            return;
        }

        try {
            await fetch(`${baseUrl}/${session.id}/close`, { method: 'POST', headers: headers() });
        } catch {
            // The next poll will show the closing line if it went through.
        }

        closedState = true;
        showThread();
        poll();
    });

    el.human?.addEventListener('click', async () => {
        if (!session) {
            return;
        }

        el.human.disabled = true;

        try {
            const response = await fetch(`${baseUrl}/${session.id}/human`, { method: 'POST', headers: headers() });

            if (response.ok) {
                const data = await response.json();
                setAgent(data.agent && data.agent.active);
            }
        } catch {
            // The next poll will show the hand-over line if it went through.
        } finally {
            el.human.disabled = false;
            await poll();
        }
    });

    el.newChat.addEventListener('click', () => {
        reset();
        el.form.querySelector('textarea')?.focus();
    });

    // A conversation from before this page load: fetch it and keep the badge honest.
    if (session) {
        poll().then(schedule);
    }
}

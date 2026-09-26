/**
 * The live thermometer's refresh.
 *
 * Polls the feed URL on <body data-screen-feed> every few seconds and
 * writes the numbers into the page. Only elements that exist are touched,
 * so an appeal with no goal (no bar, no percentage) is fine. A failed poll
 * is ignored — the projector keeps the last good figures — and polling
 * pauses while the tab is hidden, which matters on the phone somebody
 * opened the screen on to test it.
 */
export function initScreen() {
    const body = document.body;
    const feed = body.dataset.screenFeed;

    if (!body.hasAttribute('data-screen') || !feed) return;

    const every = Math.max(2000, parseInt(body.dataset.screenPoll || '5000', 10));
    const el = (name) => document.querySelector(`[data-screen-${name}]`);
    const template = document.querySelector('template[data-screen-gift]');

    let lastRaised = null;

    const render = (data) => {
        const raised = el('raised');
        if (raised) raised.textContent = data.raised.formatted;

        const count = el('count');
        if (count) count.textContent = new Intl.NumberFormat().format(data.count);

        if (data.goal) {
            const percent = Math.min(100, data.percent ?? 0);
            const fill = el('fill');
            const bar = el('bar');
            const pct = el('percent');
            const goal = el('goal');
            if (fill) fill.style.width = `${percent}%`;
            if (bar) bar.setAttribute('aria-valuenow', String(percent));
            if (pct) pct.textContent = `${data.percent ?? 0}%`;
            if (goal) goal.textContent = data.goal.formatted;
        }

        const list = el('recent');
        if (list && template) {
            list.replaceChildren(
                ...data.recent.map((gift) => {
                    const item = template.content.firstElementChild.cloneNode(true);
                    item.querySelector('[data-name]').textContent = gift.name;
                    const at = item.querySelector('[data-at]');
                    if (gift.at) {
                        at.textContent = `· ${gift.at}`;
                    } else {
                        at.remove();
                    }

                    return item;
                }),
            );
        }

        // A gift landed: let the total announce itself.
        if (lastRaised !== null && data.raised.minor > lastRaised && raised) {
            raised.classList.add('scale-105');
            setTimeout(() => raised.classList.remove('scale-105'), 700);
        }
        lastRaised = data.raised.minor;
    };

    const poll = async () => {
        if (document.hidden) return;

        try {
            const response = await fetch(feed, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (response.ok) render(await response.json());
        } catch {
            // Keep the last good figures.
        }
    };

    setInterval(poll, every);
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) poll();
    });
}

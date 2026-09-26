/**
 * The hero slider.
 *
 * ── Plain script, no framework ──────────────────────────────────────────────
 *
 * The public site's Content-Security-Policy has no `unsafe-eval`, so Alpine's
 * expression evaluator cannot run here — the same reason `chat.js` and
 * `navigation.js` are written this way. There is no library either: a
 * fade-between-slides carousel is about a hundred lines, and a carousel
 * library is thirty kilobytes on a connection where the hero photograph is
 * already the budget.
 *
 * ── It stops, in every way a person might mean "stop" ───────────────────────
 *
 * WCAG 2.2 2.2.2 requires anything that moves for more than five seconds to
 * be pausable. This honours the pause button, `prefers-reduced-motion` (in
 * which case it never starts), a pointer over the band, keyboard focus inside
 * it, and a hidden tab — that last one because a slideshow ticking away in a
 * background tab is battery and data spent on nothing, which on the phones
 * this site is built for is not a small thing.
 *
 * Once a visitor touches any control, autoplay is finished for the session:
 * a slideshow that resumes and pulls the page out from under somebody who
 * deliberately went back a slide is worse than one that never moved.
 */

const REDUCED_MOTION = '(prefers-reduced-motion: reduce)';

function initSlider(root) {
    const slides = Array.from(root.querySelectorAll('[data-hero-slide]'));

    if (slides.length < 2) {
        return;
    }

    const dots = Array.from(root.querySelectorAll('[data-hero-dot]'));
    const status = root.querySelector('[data-hero-status]');
    const pauseButton = root.querySelector('[data-hero-pause]');
    const pauseIcon = root.querySelector('[data-hero-pause-icon]');
    const playIcon = root.querySelector('[data-hero-play-icon]');

    const interval = Math.max(0, parseInt(root.dataset.autoplay || '0', 10)) * 1000;
    const reduced = window.matchMedia && window.matchMedia(REDUCED_MOTION).matches;

    let current = 0;
    let timer = null;
    let stoppedByVisitor = reduced || interval === 0;
    let hovered = false;
    let focused = false;

    function show(next, announce) {
        const target = (next + slides.length) % slides.length;

        if (target === current) {
            return;
        }

        slides.forEach((slide, i) => {
            const on = i === target;
            slide.classList.toggle('opacity-0', !on);
            /*
             * This one matters as much as the opacity: the markup ships with
             * `pointer-events-none` on every slide but the first, and leaving
             * it there meant the second slide faded in with its buttons dead.
             * `inert` covers it in browsers that have it — this covers the
             * rest, and is what the class is for.
             */
            slide.classList.toggle('pointer-events-none', !on);
            // `inert` is what stops a keyboard tabbing into the buttons of a
            // slide that is not on screen; `aria-hidden` stops it being read.
            slide.toggleAttribute('inert', !on);
            slide.setAttribute('aria-hidden', on ? 'false' : 'true');
        });

        dots.forEach((dot, i) => dot.setAttribute('aria-current', i === target ? 'true' : 'false'));

        current = target;

        // Only once the visitor is driving. An announcement on every
        // auto-advance interrupts whatever a screen reader was reading.
        if (announce && status) {
            status.setAttribute('aria-live', 'polite');
            status.textContent = slides[target].getAttribute('aria-label') || '';
        }

        restart();
    }

    // ── The clock ────────────────────────────────────────────────────────────

    function running() {
        return !stoppedByVisitor && !hovered && !focused && !document.hidden;
    }

    function paint() {
        if (!dots[current]) {
            return;
        }

        const fill = dots[current].querySelector('[data-hero-dot-fill]');

        if (!fill) {
            return;
        }

        dots.forEach((dot) => {
            const other = dot.querySelector('[data-hero-dot-fill]');
            if (other) {
                other.style.transform = 'scaleX(0)';
            }
        });

        if (!running()) {
            return;
        }

        // Two frames: the browser needs to see the 0 before the 1 for the
        // transition to run at all.
        fill.style.transitionDuration = '0ms';
        fill.style.transform = 'scaleX(0)';

        window.requestAnimationFrame(() => {
            window.requestAnimationFrame(() => {
                fill.style.transitionDuration = interval + 'ms';
                fill.style.transform = 'scaleX(1)';
            });
        });
    }

    function restart() {
        window.clearTimeout(timer);
        timer = null;

        if (!running()) {
            paint();
            return;
        }

        timer = window.setTimeout(() => show(current + 1, false), interval);
        paint();
    }

    function stopForGood() {
        stoppedByVisitor = true;
        window.clearTimeout(timer);
        timer = null;
        paint();
        setPauseLabel();
    }

    function setPauseLabel() {
        if (!pauseButton) {
            return;
        }

        const playing = !stoppedByVisitor;
        pauseButton.setAttribute('aria-label', pauseButton.dataset[playing ? 'labelPause' : 'labelPlay'] || pauseButton.getAttribute('aria-label') || '');

        if (pauseIcon && playIcon) {
            pauseIcon.classList.toggle('hidden', !playing);
            playIcon.classList.toggle('hidden', playing);
        }
    }

    // ── Controls ─────────────────────────────────────────────────────────────

    const prev = root.querySelector('[data-hero-prev]');
    const next = root.querySelector('[data-hero-next]');

    if (prev) {
        prev.addEventListener('click', () => {
            stopForGood();
            show(current - 1, true);
        });
    }

    if (next) {
        next.addEventListener('click', () => {
            stopForGood();
            show(current + 1, true);
        });
    }

    dots.forEach((dot, i) => {
        dot.addEventListener('click', () => {
            stopForGood();
            show(i, true);
        });
    });

    if (pauseButton) {
        pauseButton.addEventListener('click', () => {
            if (stoppedByVisitor) {
                stoppedByVisitor = false;
                setPauseLabel();
                restart();
                return;
            }

            stopForGood();
        });
    }

    // Arrow keys, when the band itself has focus — the pattern a screen
    // reader user expects from something calling itself a carousel.
    root.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') {
            return;
        }

        event.preventDefault();
        stopForGood();
        show(current + (event.key === 'ArrowRight' ? 1 : -1), true);
    });

    // A swipe, on the phones most of this site's visitors are holding.
    // Passive listeners: this never calls preventDefault, so the page must
    // not be made to wait on it before it scrolls.
    let touchX = null;
    let touchY = null;

    root.addEventListener('touchstart', (event) => {
        const touch = event.changedTouches[0];
        touchX = touch.clientX;
        touchY = touch.clientY;
    }, { passive: true });

    root.addEventListener('touchend', (event) => {
        if (touchX === null) {
            return;
        }

        const touch = event.changedTouches[0];
        const dx = touch.clientX - touchX;
        const dy = touch.clientY - touchY;
        touchX = null;
        touchY = null;

        // Horizontal, and meant: a 40px drift while scrolling is not a swipe.
        if (Math.abs(dx) < 40 || Math.abs(dx) < Math.abs(dy)) {
            return;
        }

        stopForGood();
        show(current + (dx < 0 ? 1 : -1), true);
    }, { passive: true });

    root.addEventListener('pointerenter', () => {
        hovered = true;
        restart();
    });

    root.addEventListener('pointerleave', () => {
        hovered = false;
        restart();
    });

    root.addEventListener('focusin', () => {
        focused = true;
        restart();
    });

    root.addEventListener('focusout', (event) => {
        if (!root.contains(event.relatedTarget)) {
            focused = false;
            restart();
        }
    });

    document.addEventListener('visibilitychange', restart);

    setPauseLabel();
    restart();
}

export function initHeroSliders() {
    document.querySelectorAll('[data-hero-slider]').forEach(initSlider);
}

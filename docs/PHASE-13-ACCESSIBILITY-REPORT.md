# Accessibility audit — WCAG 2.2 AA, September 2026

## Method

1. **axe-core 4.x**, rule tags `wcag2a wcag2aa wcag21a wcag21aa wcag22aa
   best-practice`, run in a Chromium browser pane against every kind of
   public page, in the light theme and the dark theme (the theme cookie),
   and against the open states a fetch cannot reach: the cookie notice,
   the preferences dialog, the newsletter popup.
2. **A structural audit in the test suite** (`tests/Feature/AccessibilityTest.php`)
   that runs on every commit against 21 public pages, the 404 page and
   the account pages: one `h1`, no skipped heading levels, the four
   landmarks, the skip link, `lang`, `alt` on every image, a label on
   every control, no positive `tabindex`, no link without a destination,
   no button without a name, a live region present, errors tied to fields
   with `aria-describedby` and announced with `role="alert"`, and
   `prefers-reduced-motion` honoured.
3. **Keyboard pass** on the donation form, the basket, the menus, the
   theme toggle, both dialogs and the door page.
4. **Not run here:** a screen reader (NVDA/VoiceOver) and Lighthouse —
   both need a person at a real browser. The section at the end says how.

## Pages tested with axe

Home · CMS page (About) · News index · Post · Projects index · Project ·
Appeals index · Appeal · Shop index · Product · Events index · Event ·
Volunteer index · Role · FAQ · Contact · Donate (with and without an
appeal) · Give (offline) · Impact · Login · Register · Search · Partner
with us · Accessibility · Privacy · Safeguarding · 404 — each in light
and dark.

## Findings and fixes

| # | Rule | Where | Severity | Fix |
|---|---|---|---|---|
| 1 | **target-size** (WCAG 2.2 2.5.8) — a 130 × 17 px link | Footer "Cookie preferences" link (added in Phase 12) on every page | serious | `inline-block py-1` gives it a 24 px target; a focus ring added while there. |
| 2 | **heading-order** — `h1` → `h3` | Projects index, Appeals index: the cards' titles were `h3` with no `h2` between | moderate | Card components take a `level` prop; the index pages pass `h2`. The home page, where the cards sit under a block `h2`, keeps `h3`. |
| 3 | **heading-order** — `h1` → `h3` | FAQ page: uncategorised questions (no group heading) | moderate | The question heading is `h2` in a group with no heading, `h3` under one. |
| 4 | **live region** — a payment-status change reloaded the page with nothing announced | Donation thank-you page while a Mobile Money prompt is pending | moderate | The poll now writes "Payment update received. Refreshing the page." to the page's `aria-live` region before the reload. |

Everything else axe checks passed on every page in both themes — colour
contrast included, which the theme editor's contrast checker (Phase 5)
enforces at the point of choosing a colour.

## What was already right (from Phases 4–12), confirmed

- Semantic landmarks (`header`, `nav`, `main`, `footer`), one `h1` per
  page from the page shell, a "Skip to content" link that moves focus.
- Every form control has a visible `<label for>`; the hint and the error
  are tied with `aria-describedby`; errors carry `role="alert"` and
  `aria-invalid`; required fields carry the attribute and a visible
  marker.
- Menus are `<details>`/`<summary>` (keyboard-operable with no script);
  dialogs are native `<dialog>` (focus trapped, Escape closes, focus
  restored); the theme toggle is a three-state control with visible
  labels; the testimonials block is a list, not a carousel — nothing
  auto-plays, nothing moves that cannot be stopped.
- Images: alt text is mandatory in the media library before an image can
  be published; decorative images are marked and get `alt=""`; captions
  are rendered as `<figcaption>`.
- `prefers-reduced-motion: reduce` collapses every animation and
  transition (`resources/css/app.css`).
- Text resize to 200 % and reflow at 320 px: the layout is fluid Tailwind
  with no fixed widths on content; the mobile viewport test (Phase 6)
  covers 375 px, and no horizontal scroll appears at 320 px on the
  pages spot-checked.
- Colour is never the only signal: statuses carry text, errors carry an
  icon-free sentence, links are underlined on hover and focus and
  distinguished by weight.
- Focus is visible everywhere: `focus-visible:outline-2` with the theme's
  focus-ring token on every interactive element.

## Known limitations (in the public statement)

- Uploaded PDFs on the reports page may not be accessible documents;
  the statement offers the content in another form on request.
- The admin panel (Filament) is not covered by this audit; it is used by
  staff and Filament publishes its own accessibility work.

## Runbook: the checks a person makes

1. **Lighthouse** — Chrome DevTools → Lighthouse → Accessibility + SEO +
   Performance, mobile, on the home, donate and an appeal page of the
   staging site. Expect Accessibility 100, SEO ≥ 95. Performance depends
   on the host; see `PHASE-13-SEO-AND-CONTENT.md` for the budget.
2. **Screen reader** — NVDA (Windows, free) or VoiceOver (Mac/iPhone):
   land on the home page, `H` through the headings, `D` through the
   landmarks, tab to "Donate", complete a GH₵ 5 test gift with Paystack
   test keys, confirm the thank-you page announces itself. Ten minutes;
   do it after any redesign.
3. **Keyboard only** — unplug the mouse; every menu, the theme toggle,
   the cookie preferences, the newsletter popup, the basket and the
   checkout must be reachable and operable; focus must always be
   visible; Escape must close every dialog.
4. **axe again** — the browser extension (free) on any page you change.
   The Pest audit will catch the structural regressions on its own.

## Statement

The public Accessibility page (seeded from
`database/seeders/content/legal/accessibility.html`, a draft until the
trustees publish it) now names this audit, the two fixes, and the
automated check.

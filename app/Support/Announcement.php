<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * The strip across the top of every page.
 *
 * ── It has an end date, and that is the whole point ─────────────────────────
 *
 * An announcement with no expiry is one somebody has to remember to take down,
 * and nobody ever does. That is how a foundation's website ends up advertising
 * last December's carol service in March — visible to every donor, invisible to
 * the staff who stopped reading their own header months ago.
 *
 * So the window is part of the setting rather than part of somebody's memory.
 * `starts_at` and `ends_at` are optional, and `ends_at` is inclusive of the day
 * it names: "hide after 2026-12-25" means the bar is still up on Christmas Day.
 *
 * ── A bad date does not take the site down ──────────────────────────────────
 *
 * The dates are typed by hand into a text field, so "25/12/26" and "next
 * Friday" will both eventually be entered. An unparseable bound is treated as
 * absent rather than thrown: the failure mode of a mistyped end date is a bar
 * that stays up too long, which somebody notices, and the failure mode of
 * throwing is a 500 on every page of the site, which is not a trade anybody
 * would make deliberately.
 *
 * ── Why a class rather than four `setting()` calls in the layout ────────────
 *
 * Because "is it showing?" is a real question with a real answer, and the
 * layout is not where date arithmetic belongs. It is also what lets the admin
 * screen say "this is live now" or "this starts on Tuesday" instead of leaving
 * an editor to work it out from two text fields.
 */
class Announcement
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Whether the bar should be drawn right now.
     *
     * Message present, and inside the window. An unfilled `{{PLACEHOLDER}}`
     * counts as absent — `Settings::get()` handles that — so a seeded default
     * never reaches a visitor.
     */
    public function isShowing(): bool
    {
        return $this->message() !== null && $this->isWithinWindow();
    }

    public function message(): ?string
    {
        $message = $this->settings->get('announcement.message');

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    /**
     * The link, or null.
     *
     * A URL with no label is a link with nothing to click; a label with no URL
     * is text pretending to be one. Both halves or neither.
     */
    public function url(): ?string
    {
        $url = $this->settings->get('announcement.link_url');

        return (is_string($url) && trim($url) !== '' && $this->linkLabel() !== null) ? trim($url) : null;
    }

    public function linkLabel(): ?string
    {
        $label = $this->settings->get('announcement.link_label');

        return is_string($label) && trim($label) !== '' ? trim($label) : null;
    }

    public function startsAt(): ?CarbonImmutable
    {
        return $this->parse($this->settings->get('announcement.starts_at'));
    }

    /** Inclusive: "hide after the 25th" leaves it up all of the 25th. */
    public function endsAt(): ?CarbonImmutable
    {
        return $this->parse($this->settings->get('announcement.ends_at'))?->endOfDay();
    }

    /**
     * A sentence for the admin screen.
     *
     * Two date fields do not answer "is this on the site right now?", and that
     * is the only question the person editing them has.
     */
    public function status(): string
    {
        if ($this->message() === null) {
            return __('No announcement — nothing is shown.');
        }

        $now = CarbonImmutable::now();
        $starts = $this->startsAt();
        $ends = $this->endsAt();

        if ($starts !== null && $now->lt($starts)) {
            return __('Scheduled — goes up :when.', ['when' => $starts->toFormattedDayDateString()]);
        }

        if ($ends !== null && $now->gt($ends)) {
            return __('Finished — it came down :when.', ['when' => $ends->toFormattedDayDateString()]);
        }

        return $ends === null
            ? __('Live now, with no end date — it stays up until somebody removes it.')
            : __('Live now, until :when.', ['when' => $ends->toFormattedDayDateString()]);
    }

    private function isWithinWindow(): bool
    {
        $now = CarbonImmutable::now();
        $starts = $this->startsAt();
        $ends = $this->endsAt();

        return ! ($starts !== null && $now->lt($starts))
            && ! ($ends !== null && $now->gt($ends));
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse(trim($value))->startOfDay();
        } catch (Throwable) {
            // Mistyped. Treated as no bound — see the note at the top.
            return null;
        }
    }
}

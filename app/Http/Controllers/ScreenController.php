<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Cause;
use App\Models\Donation;
use App\Support\PageMeta;
use App\Support\SiteCache;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The live thermometer — `/screen/{appeal}`.
 *
 * A page for a projector at a fundraising dinner: no header, no footer,
 * the total in the largest type on the site, a bar that climbs, the last
 * few gifts by first name, and a QR code guests scan to give from their
 * seat. Dark by default because a projector in a dim hall is where it will
 * be shown; `?theme=light` for a bright lobby screen.
 *
 * ── The numbers ─────────────────────────────────────────────────────────────
 *
 * The page polls `/screen/{appeal}/feed.json` every few seconds. The feed
 * reads the cached `raised_minor` the progress bar already uses, cached
 * again for a few seconds under the site generation — which every
 * completed gift bumps — so a room of people refreshing costs the database
 * one query per gift, not one per person per second.
 *
 * ── Privacy ─────────────────────────────────────────────────────────────────
 *
 * The recent gifts honour `is_anonymous` and the foundation's donor-wall
 * switch exactly as the appeal page does, and go one step further: FIRST
 * NAMES only (this is a screen in a room, not a list a donor chose to be
 * on) and no amounts, for the reason given on `CauseController` — an
 * amount beside "Anonymous" identifies the anonymous donor to whoever knows
 * what they gave.
 *
 * Public and unauthenticated, because the link is projected and typed into
 * a phone; `noindex`, because it is not a page for a search engine.
 */
class ScreenController extends Controller
{
    /** Gifts shown on the screen. */
    private const RECENT = 5;

    /** How long the feed may be stale, in seconds, when no gift has landed. */
    private const FEED_TTL = 5;

    /** How often the page asks, in milliseconds. */
    public const POLL_MS = 5000;

    public function show(Cause $cause): View
    {
        if (! $cause->isLive()) {
            throw new NotFoundHttpException;
        }

        $cause->load('featuredImage');

        $theme = request()->query('theme') === 'light' ? 'light' : 'dark';

        return view('screen', [
            'cause' => $cause,
            'theme' => $theme,
            'feed' => $this->payload($cause),
            'feedUrl' => route('screen.feed', $cause),
            'donateUrl' => $this->donateUrl($cause),
            'qr' => $this->qr($this->donateUrl($cause)),
            'pollMs' => self::POLL_MS,
            'meta' => PageMeta::site($cause->title, noindex: true),
        ]);
    }

    public function feed(Cause $cause): JsonResponse
    {
        if (! $cause->isLive()) {
            throw new NotFoundHttpException;
        }

        return response()->json($this->payload($cause))
            ->setCache(['public' => true, 'max_age' => self::FEED_TTL]);
    }

    /**
     * What the screen shows, as plain values. Cached as an array — never an
     * object — under the site generation, so a completed gift (which bumps
     * the generation) is on the screen at the next poll.
     *
     * @return array<string, mixed>
     */
    private function payload(Cause $cause): array
    {
        return SiteCache::remember('screen.'.$cause->getKey(), function () use ($cause): array {
            $cause->refresh();

            $raised = $cause->raisedAmount();
            $goal = $cause->goal;

            return [
                'title' => $cause->title,
                'raised' => ['minor' => $raised->toMinor(), 'formatted' => $raised->format(), 'currency' => $cause->currency],
                'goal' => $goal === null || $goal->isZero()
                    ? null
                    : ['minor' => $goal->toMinor(), 'formatted' => $goal->format(), 'currency' => $cause->currency],
                'percent' => $cause->progressPercent(),
                'count' => (int) $cause->donation_count,
                'recent' => $this->recent($cause),
                'generated_at' => now()->toIso8601String(),
            ];
        }, self::FEED_TTL);
    }

    /** @return array<int, array{name: string, at: string}> */
    private function recent(Cause $cause): array
    {
        if (! setting('site.show_donor_wall', true)) {
            return [];
        }

        return Donation::query()
            ->where('cause_id', $cause->getKey())
            ->completed()
            ->latest('paid_at')
            ->latest('id') // two gifts in the same second: the later one first
            ->limit(self::RECENT)
            ->get()
            ->map(fn (Donation $donation): array => [
                'name' => $donation->is_anonymous
                    ? __('Anonymous')
                    : (Str::of((string) $donation->donor_name)->trim()->explode(' ')->first() ?: __('A supporter')),
                'at' => $donation->paid_at?->diffForHumans(short: true) ?? '',
            ])
            ->all();
    }

    private function donateUrl(Cause $cause): string
    {
        return route('donate', ['cause' => $cause->slug, 'utm_source' => 'screen', 'utm_medium' => 'qr']);
    }

    /** The QR code as inline SVG; text, so no image library on the server. */
    private function qr(string $url): string
    {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'outputBase64' => false,
            'eccLevel' => EccLevel::M,
            'addQuietzone' => true,
            'svgAddXmlHeader' => false,
            'svgUseFillAttributes' => true,
        ]);

        return (new QRCode($options))->render($url);
    }
}

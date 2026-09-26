<?php

declare(strict_types=1);

namespace App\Chat\Agent;

use App\Models\Faq;
use App\Models\Page;
use App\Models\PageSection;
use App\Support\SiteCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What the assistant is allowed to know: the foundation's own published
 * words, and nothing else.
 *
 * ── Why there is no vector database ─────────────────────────────────────────
 *
 * This runs on InMotion shared hosting: no Redis, no extension anybody can
 * install, no background service. And it does not need one. The whole corpus
 * is a few dozen FAQs and a few dozen published pages — a few hundred
 * kilobytes of text. Keyword scoring over that, in PHP, takes single-digit
 * milliseconds, and is honest about what it is: a way of choosing which
 * paragraphs to put in front of the model, not a search engine.
 *
 * ── Only what a visitor could already read ──────────────────────────────────
 *
 * Published FAQs, live pages. Nothing from a draft, nothing from a
 * beneficiary record, no donation, no order, no case. The assistant is a
 * receptionist with the brochure open, not a member of staff with the filing
 * cabinet — and the way to guarantee that is for the private records never to
 * enter this file, rather than for a prompt to ask it nicely.
 *
 * ── It is capped, because every character is paid for on every turn ─────────
 *
 * `ai.knowledge.max_characters`. A knowledge base that grows with the site is
 * a bill that grows with the site.
 */
class KnowledgeBase
{
    /**
     * Keyed on the site's cache generation, which every content save bumps
     * (SiteCacheObserver). So a corrected FAQ reaches the assistant on the
     * next question rather than in an hour, without this file having to know
     * which models matter — the same mechanism the public page fragments use.
     */
    private const CACHE_KEY = 'chat:agent:corpus';

    /** Words too common to tell one page from another. */
    private const STOP_WORDS = [
        'the', 'and', 'for', 'are', 'you', 'your', 'our', 'with', 'from', 'that', 'this',
        'have', 'has', 'can', 'will', 'what', 'how', 'when', 'where', 'who', 'why', 'does',
        'about', 'please', 'would', 'could', 'there', 'their', 'they', 'not', 'but', 'any',
    ];

    /**
     * The passages most likely to answer this question, as one block of text
     * for the system prompt.
     */
    public function for(string $question): string
    {
        $terms = $this->terms($question);
        $budget = max(1_000, (int) config('ai.knowledge.max_characters', 12_000));

        $ranked = $this->corpus()
            ->sortByDesc(fn (Passage $passage): int => $passage->score($terms))
            ->values();

        $out = [];
        $used = 0;

        foreach ($ranked as $passage) {
            // Nothing that matched nothing: padding the prompt with unrelated
            // pages makes the answer worse as well as dearer. The first one
            // goes in regardless, so the model always has something.
            if ($out !== [] && $passage->score($terms) <= 0) {
                continue;
            }

            $block = $passage->toPrompt();

            if ($used + strlen($block) > $budget) {
                continue;
            }

            $out[] = $block;
            $used += strlen($block);
        }

        return implode("\n\n", $out);
    }

    /**
     * Every passage the assistant may draw on, cached.
     *
     * Cleared by the site cache, which every content save already clears — so
     * a corrected FAQ reaches the assistant on the next question rather than
     * in an hour.
     *
     * @return Collection<int, Passage>
     */
    public function corpus(): Collection
    {
        $minutes = max(1, (int) config('ai.knowledge.cache_minutes', 60));

        /** @var array<int, array{title: string, url: string|null, body: string}> $rows */
        $rows = Cache::remember(
            SiteCache::key(self::CACHE_KEY),
            now()->addMinutes($minutes),
            fn (): array => $this->build()
                ->map(fn (Passage $passage): array => ['title' => $passage->title, 'url' => $passage->url, 'body' => $passage->body])
                ->all(),
        );

        return collect($rows)->map(fn (array $row): Passage => Passage::make($row['title'], $row['url'], $row['body']))->values();
    }

    public function forget(): void
    {
        Cache::forget(SiteCache::key(self::CACHE_KEY));
    }

    /** @return Collection<int, Passage> */
    private function build(): Collection
    {
        return $this->faqs()
            ->concat($this->pages())
            ->reject(fn (Passage $passage): bool => $passage->isEmpty())
            ->values();
    }

    /** @return Collection<int, Passage> */
    private function faqs(): Collection
    {
        return Faq::query()
            ->where('is_published', true)
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->limit(max(1, (int) config('ai.knowledge.max_faqs', 12)) * 4)
            ->get(['question', 'answer'])
            ->map(fn (Faq $faq): Passage => Passage::make(
                (string) $faq->question,
                null,
                $this->plain((string) $faq->answer),
            ));
    }

    /**
     * Live pages, as their headings and prose.
     *
     * The text of a page is in its sections' `data`, which is a JSON blob of
     * whatever fields that block declares — so this pulls the fields that hold
     * words and ignores the rest (an image id is not knowledge).
     *
     * @return Collection<int, Passage>
     */
    private function pages(): Collection
    {
        $pages = Page::query()
            ->live()
            ->orderBy('sort_order')
            ->limit(max(1, (int) config('ai.knowledge.max_pages', 6)) * 8)
            ->get(['id', 'title', 'slug', 'path', 'excerpt']);

        if ($pages->isEmpty()) {
            return collect();
        }

        $sections = PageSection::query()
            ->whereIn('page_id', $pages->pluck('id'))
            ->orderBy('page_id')
            ->orderBy('sort_order')
            ->get(['page_id', 'data'])
            ->groupBy('page_id');

        return $pages->map(function (Page $page) use ($sections): Passage {
            $words = collect($sections->get($page->getKey(), collect()))
                ->flatMap(fn (PageSection $section): array => $this->words((array) ($section->data ?? [])))
                ->filter()
                ->implode("\n");

            return Passage::make(
                $page->title,
                url($page->path ?: '/'.$page->slug),
                trim(($page->excerpt ? $page->excerpt."\n" : '').$words),
            );
        });
    }

    /**
     * The fields of a block that hold prose, flattened.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function words(array $data): array
    {
        $keys = ['eyebrow', 'heading', 'subheading', 'intro', 'body', 'text', 'question', 'answer', 'title', 'quote'];
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out = array_merge($out, $this->words($value));

                continue;
            }

            if (in_array($key, $keys, true) && is_string($value) && trim($value) !== '') {
                $out[] = $this->plain($value);
            }
        }

        return $out;
    }

    private function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    }

    /** @return array<int, string> */
    private function terms(string $question): array
    {
        return collect(preg_split('/[^\p{L}\p{N}]+/u', Str::lower($question)) ?: [])
            ->filter(fn (string $word): bool => strlen($word) > 2 && ! in_array($word, self::STOP_WORDS, true))
            ->unique()
            ->take(20)
            ->values()
            ->all();
    }
}

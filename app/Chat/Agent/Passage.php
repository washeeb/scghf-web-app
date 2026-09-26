<?php

declare(strict_types=1);

namespace App\Chat\Agent;

use Illuminate\Support\Str;

/**
 * One piece of the foundation's own published writing, ready to put in front
 * of the model: an FAQ, or a page.
 *
 * An object rather than an array shape because the haystack — the lower-cased
 * text the scoring reads — must be built from the title and body together and
 * must never drift from them. Here that is impossible by construction.
 */
final readonly class Passage
{
    private function __construct(
        public string $title,
        public ?string $url,
        public string $body,
        public string $haystack,
    ) {}

    public static function make(string $title, ?string $url, string $body): self
    {
        $body = Str::limit(trim($body), 1_400, '…');

        return new self($title, $url, $body, Str::lower($title.' '.$body));
    }

    public function isEmpty(): bool
    {
        return trim($this->body) === '';
    }

    /**
     * How well this answers a question: how many times its words appear.
     *
     * Deliberately crude. It chooses which paragraphs to show the model, and
     * the model does the understanding — dressing this up as search would
     * cost more and help less.
     *
     * @param  array<int, string>  $terms
     */
    public function score(array $terms): int
    {
        $score = 0;

        foreach ($terms as $term) {
            $score += substr_count($this->haystack, $term);
        }

        return $score;
    }

    /** As it appears in the prompt. */
    public function toPrompt(): string
    {
        return '## '.$this->title."\n".($this->url !== null ? $this->url."\n" : '').$this->body;
    }
}

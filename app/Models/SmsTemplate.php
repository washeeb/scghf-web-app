<?php

declare(strict_types=1);

namespace App\Models;

use App\Communications\SmsSegmenter;
use App\Communications\TemplateRenderer;
use App\Models\Concerns\RecordsAuthor;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * An editable SMS, looked up by key.
 *
 * Separate from EmailTemplate rather than a channel column on it, because the
 * constraints are genuinely different — and the difference costs money.
 *
 * Every save re-measures the body: characters, encoding, segments. A template
 * that would exceed the segment ceiling is REFUSED, because the alternative is
 * a message that quietly costs three times what was budgeted, for every
 * recipient, until somebody reads an invoice.
 *
 * The measurement also catches the specific trap this project sets for itself:
 * CLAUDE.md mandates "GH₵ 1,234.56" as the display format, and ₵ is not in the
 * GSM-7 alphabet. Correct for the website, expensive for SMS.
 *
 * @see SmsSegmenter
 */
class SmsTemplate extends Model
{
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;
    use SoftDeletes;

    public const CATEGORY_TRANSACTIONAL = 'transactional';

    public const CATEGORY_MARKETING = 'marketing';

    public const CATEGORY_SYSTEM = 'system';

    protected $fillable = [
        'key', 'name', 'description', 'category', 'body', 'sender_id',
        'available_variables', 'required_variables',
        'max_segments', 'is_active', 'is_locked', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => self::CATEGORY_TRANSACTIONAL,
        'encoding' => SmsSegmenter::ENCODING_GSM7,
        'character_count' => 0,
        'estimated_segments' => 1,
        'is_active' => true,
        'is_locked' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'available_variables' => 'array',
            'required_variables' => 'array',
            'max_segments' => 'integer',
            'character_count' => 'integer',
            'estimated_segments' => 'integer',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            $template->max_segments ??= (int) config('communications.sms.max_segments', 2);

            $measurement = app(SmsSegmenter::class)->measure((string) $template->body);

            $template->encoding = $measurement['encoding'];
            $template->character_count = $measurement['characters'];
            $template->estimated_segments = $measurement['segments'];

            /*
             * Refused, not warned about.
             *
             * A warning on an admin screen is read once and dismissed; the cost
             * is paid on every message for as long as the template lives. And a
             * three-part SMS on a feature phone arrives as three messages that
             * can turn up out of order, which is not a message, it is a puzzle.
             */
            if ($measurement['segments'] > $template->max_segments) {
                throw new RuntimeException(sprintf(
                    'This message is %d segments and the limit is %d. %s '
                    .'Shorten it, or send an email with an SMS pointing at it.',
                    $measurement['segments'],
                    $template->max_segments,
                    app(SmsSegmenter::class)->explain((string) $template->body),
                ));
            }

            $max = (int) config('communications.sms.sender_id_max_length', 11);

            if ($template->sender_id !== null && mb_strlen($template->sender_id) > $max) {
                throw new RuntimeException(
                    "A sender ID may be at most {$max} characters. Longer ones are rejected "
                    .'by the networks, and rejection here is silent.'
                );
            }

            // Same reasoning as EmailTemplate: switching off a transactional
            // template stops messages without stopping anything visible.
            if ($template->is_locked && ! $template->is_active) {
                throw new RuntimeException(
                    "The [{$template->key}] SMS template cannot be deactivated. Edit the wording "
                    .'instead — deactivating it would stop messages silently.'
                );
            }
        });

        static::deleting(function (self $template): void {
            if ($template->is_locked && ! $template->isForceDeleting()) {
                throw new RuntimeException(
                    "The [{$template->key}] SMS template cannot be deleted. Code depends on it "
                    .'and has no fallback wording.'
                );
            }
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public static function forKey(string $key): self
    {
        $template = static::query()->where('key', $key)->first();

        if ($template === null) {
            throw new RuntimeException(
                "No SMS template exists for [{$key}]. Templates are seeded, so a missing one "
                .'means the seeder has not run on this environment.'
            );
        }

        if (! $template->is_active) {
            throw new RuntimeException(
                "The [{$key}] SMS template is deactivated, so this message cannot be sent."
            );
        }

        return $template;
    }

    /**
     * Render the body against a set of values.
     *
     * Not escaped: SMS is plain text, and `&amp;` in a text message is simply
     * a mistake.
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(array $variables): string
    {
        return app(TemplateRenderer::class)
            ->render($this->body, $variables, $this->required_variables ?? []);
    }

    /**
     * Measure the body once the variables are filled in.
     *
     * The stored `estimated_segments` is measured on the raw template, where
     * `{{donor_name}}` is thirteen characters. A real name might be twenty-five.
     * So the dispatcher measures again after rendering, and it is the rendered
     * measurement that is logged and costed.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function measureRendered(array $variables): array
    {
        return app(SmsSegmenter::class)->measure($this->render($variables));
    }

    public function senderId(): string
    {
        return $this->sender_id ?? (string) config('communications.sms.sender_id');
    }

    public function explain(): string
    {
        return app(SmsSegmenter::class)->explain((string) $this->body);
    }

    /**
     * Placeholders used but never declared.
     *
     * @return array<int, string>
     */
    public function undeclaredVariables(): array
    {
        return app(TemplateRenderer::class)
            ->undeclared((string) $this->body, $this->available_variables ?? []);
    }

    /** Estimated cost of one send, in integer pesewas. */
    public function estimatedCostMinor(int $recipients = 1): int
    {
        return $this->estimated_segments
            * max(0, $recipients)
            * (int) config('communications.sms.cost_per_segment_minor', 4);
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    #[Scope]
    protected function ofCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }
}

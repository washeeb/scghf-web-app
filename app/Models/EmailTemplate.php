<?php

declare(strict_types=1);

namespace App\Models;

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
 * An editable email, looked up by key.
 *
 * CLAUDE.md's CMS rule applies to email as much as to a web page: the
 * foundation must be able to reword its own receipt covering letter without a
 * deployment. So the subject and body live here.
 *
 * What is NOT here is the GRA acknowledgement wording. That is composed by
 * App\Support\Acknowledgement from config/compliance.php and arrives as a
 * single `{{acknowledgement}}` variable. An editor can rewrite the letter
 * around it and cannot touch the statement made under s.97 of Act 896 — nor
 * activate the approval paragraph before the Notice of Approval exists.
 *
 * @see TemplateRenderer
 */
class EmailTemplate extends Model
{
    use HasFactory;
    use HasUlids;
    use RecordsAuthor;
    use SoftDeletes;

    public const CATEGORY_TRANSACTIONAL = 'transactional';

    public const CATEGORY_MARKETING = 'marketing';

    public const CATEGORY_SYSTEM = 'system';

    protected $fillable = [
        'key', 'name', 'description', 'category',
        'subject', 'preheader', 'body_html', 'body_text', 'layout',
        'from_name', 'from_address', 'reply_to', 'bcc',
        'available_variables', 'required_variables',
        'is_active', 'is_locked', 'updated_by',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => self::CATEGORY_TRANSACTIONAL,
        'layout' => 'mail.layouts.default',
        'is_active' => true,
        'is_locked' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'available_variables' => 'array',
            'required_variables' => 'array',
            'is_active' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $template): void {
            /*
             * A locked template may be REWORDED but not switched off.
             *
             * The failure this prevents is quiet: somebody tidying the template
             * list deactivates "Donation receipt" because it looks unused, and
             * receipts stop going out. Nothing throws, no page breaks, and it
             * surfaces weeks later as a donor asking where theirs is.
             */
            if ($template->is_locked && ! $template->is_active) {
                throw new RuntimeException(
                    "The [{$template->key}] template cannot be deactivated. Messages depend on "
                    .'it, and switching it off stops them silently rather than visibly. '
                    .'Edit the wording instead.'
                );
            }
        });

        static::deleting(function (self $template): void {
            if ($template->is_locked && ! $template->isForceDeleting()) {
                throw new RuntimeException(
                    "The [{$template->key}] template cannot be deleted. It is used by code that "
                    .'has no fallback wording to fall back to.'
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

    // ── Lookup ───────────────────────────────────────────────────────────────

    /**
     * The active template for a key, or an explanation of why there is none.
     *
     * Throws rather than returning null. Every caller is about to send
     * something, and a null here becomes a receipt that was never sent with
     * nothing anywhere recording that it was not.
     */
    public static function forKey(string $key): self
    {
        $template = static::query()->where('key', $key)->first();

        if ($template === null) {
            throw new RuntimeException(
                "No email template exists for [{$key}]. Templates are seeded, so a missing one "
                .'means the seeder has not run on this environment.'
            );
        }

        if (! $template->is_active) {
            throw new RuntimeException(
                "The [{$key}] email template is deactivated, so this message cannot be sent."
            );
        }

        return $template;
    }

    // ── Rendering ────────────────────────────────────────────────────────────

    /**
     * Render subject, HTML and plain text against a set of values.
     *
     * The HTML body escapes its values; the plain-text body does not, because
     * escaping is a property of the medium and `&amp;` in a text email is just
     * wrong. The subject is escaped too — a header is not a place to discover
     * that somebody's name contains a newline.
     *
     * @param  array<string, mixed>  $variables
     * @return array{subject: string, html: string, text: string|null, preheader: string|null}
     */
    public function render(array $variables): array
    {
        $renderer = app(TemplateRenderer::class);
        $required = $this->required_variables ?? [];

        return [
            'subject' => $this->stripNewlines(
                $renderer->render($this->subject, $variables, $required)
            ),
            'html' => $renderer->render($this->body_html, $variables, $required, escape: true),
            'text' => $this->body_text === null
                ? null
                : $renderer->render($this->body_text, $variables, $required),
            'preheader' => $this->preheader === null
                ? null
                : $this->stripNewlines($renderer->render($this->preheader, $variables)),
        ];
    }

    /**
     * Placeholders used in this template that nobody declared.
     *
     * Surfaced in the editor so `{{donor_nme}}` is a validation message at save
     * time rather than a blank in a donor's inbox.
     *
     * @return array<int, string>
     */
    public function undeclaredVariables(): array
    {
        $renderer = app(TemplateRenderer::class);
        $declared = $this->available_variables ?? [];

        return array_values(array_unique(array_merge(
            $renderer->undeclared($this->subject, $declared),
            $renderer->undeclared($this->body_html, $declared),
            $renderer->undeclared((string) $this->body_text, $declared),
        )));
    }

    /** Whether this message must carry a one-click unsubscribe link. */
    public function requiresUnsubscribe(): bool
    {
        return (bool) config("communications.categories.{$this->category}.requires_unsubscribe", false);
    }

    /** Whether the rendered body is worth keeping on every log row. */
    public function storesBody(): bool
    {
        return (bool) config("communications.categories.{$this->category}.store_body", true);
    }

    /**
     * Header injection defence, and a legibility one.
     *
     * A newline in a Subject: header lets whatever follows become a header of
     * its own — a Bcc:, for instance. The values here come from donor-supplied
     * names, so this is not theoretical.
     */
    private function stripNewlines(string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', $value));
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

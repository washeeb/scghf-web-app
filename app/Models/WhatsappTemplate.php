<?php

declare(strict_types=1);

namespace App\Models;

use App\Communications\TemplateRenderer;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A WhatsApp template — Wave 2 (1.3).
 *
 * ── Meta owns the words ─────────────────────────────────────────────────────
 *
 * On WhatsApp a business may only start a conversation with a template
 * Meta has approved, and the template's text lives on Meta's side under a
 * name and a language, with numbered placeholders. So this row is a
 * MAPPING, not a message: our key (`donation.receipt`), Meta's name and
 * language for it, and the order of our named variables in their
 * `{{1}}`, `{{2}}`. `body` is what the approved template says, kept here
 * so the log shows a readable message and the panel can preview one —
 * editing it changes nothing Meta sends.
 *
 * Unapproved is unsendable: `forKey()` refuses until `is_approved` is
 * ticked by whoever saw the approval in Meta's Business Manager.
 */
class WhatsappTemplate extends Model
{
    public const CATEGORY_TRANSACTIONAL = 'transactional';

    public const CATEGORY_MARKETING = 'marketing';

    protected $fillable = ['key', 'name', 'description', 'category', 'meta_name', 'language', 'variables', 'body', 'is_approved', 'is_active'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'category' => self::CATEGORY_TRANSACTIONAL,
        'language' => 'en',
        'is_approved' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'is_approved' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function forKey(string $key): self
    {
        $template = static::query()->where('key', $key)->first();

        if ($template === null) {
            throw new RuntimeException("No WhatsApp template exists for [{$key}]. Templates are seeded; a missing one means the seeder has not run.");
        }

        if (! $template->is_active) {
            throw new RuntimeException("The WhatsApp template [{$key}] is switched off.");
        }

        if (! $template->is_approved || blank($template->meta_name)) {
            throw new RuntimeException("The WhatsApp template [{$key}] has not been approved by Meta yet, or has no Meta template name. Nothing can be sent with it.");
        }

        return $template;
    }

    /** The readable message, for the log. */
    public function render(array $variables): string
    {
        return app(TemplateRenderer::class)->render((string) $this->body, $variables, $this->variables ?? []);
    }

    /**
     * The values in Meta's order — what `{{1}}`, `{{2}}` … will be.
     *
     * @param  array<string, mixed>  $variables
     * @return array<int, string>
     */
    public function parameters(array $variables): array
    {
        $out = [];

        foreach ((array) $this->variables as $name) {
            if (! array_key_exists($name, $variables)) {
                throw new RuntimeException("The WhatsApp template [{$this->key}] needs [{$name}] and it was not given.");
            }

            // Meta refuses newlines, tabs and four-plus spaces in a parameter.
            $out[] = (string) preg_replace('/\s{4,}/', '   ', str_replace(["\n", "\t"], ' ', (string) $variables[$name]));
        }

        return $out;
    }
}

<?php

declare(strict_types=1);

namespace App\Communications;

use App\Communications\Exceptions\UnresolvedVariable;
use Illuminate\Support\Str;

/**
 * Substitutes `{{variable}}` placeholders in a template body.
 *
 * ── Why not Blade ───────────────────────────────────────────────────────────
 *
 * These strings are edited by non-technical staff through a browser and stored
 * in a database column. Rendering a database column as Blade is arbitrary PHP
 * execution, one SQL injection or one compromised admin account away from being
 * somebody else's PHP. So the syntax here is deliberately not a language: it
 * substitutes declared names and does nothing else. No conditionals, no loops,
 * no method calls.
 *
 * ── Why it refuses ──────────────────────────────────────────────────────────
 *
 * With `templates.strict_variables` on, a missing required variable throws
 * rather than rendering an empty string.
 *
 * "Dear ," and "Dear {{donor_name}}," are both worse than a job that fails and
 * retries, because a message that has gone cannot be recalled and a failed job
 * can be fixed in five minutes. This is the same stance App\Support\Acknowledgement
 * takes on an unfilled {{TIN}}, for the same reason.
 */
class TemplateRenderer
{
    /**
     * Render a body against a set of values.
     *
     * @param  array<string, mixed>  $variables
     * @param  array<int, string>  $required  names that must resolve
     */
    public function render(string $body, array $variables, array $required = [], bool $escape = false): string
    {
        $values = array_merge($this->globals(), $variables);
        $missing = [];

        $rendered = $this->replace($body, $values, $escape, $missing);

        $unresolvedRequired = array_values(array_intersect($required, $missing));

        if ($unresolvedRequired !== [] && config('communications.templates.strict_variables', true)) {
            throw UnresolvedVariable::forNames($unresolvedRequired);
        }

        return $rendered;
    }

    /**
     * Which declared placeholders a body actually uses.
     *
     * The editor uses this to warn about a variable that is declared and never
     * used — usually a rename that only got half done — and about one used and
     * never declared, which is the {{donor_nme}} case.
     *
     * @return array<int, string>
     */
    public function placeholders(string $body): array
    {
        [$open, $close] = $this->delimiters();

        preg_match_all(
            '/'.preg_quote($open, '/').'\s*([a-zA-Z0-9_.]+)\s*'.preg_quote($close, '/').'/',
            $body,
            $matches,
        );

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Placeholders used in the body that are neither declared nor global.
     *
     * @param  array<int, string>  $declared
     * @return array<int, string>
     */
    public function undeclared(string $body, array $declared): array
    {
        $known = array_merge($declared, array_keys($this->globals()));

        return array_values(array_diff($this->placeholders($body), $known));
    }

    /**
     * Values available to every template, drawn from the CMS settings layer.
     *
     * Global rather than declared per template because the alternative is the
     * site's own name and address hardcoded into forty templates — which is
     * exactly what CLAUDE.md's CMS rule forbids, and exactly what makes a
     * change of address a forty-template edit.
     *
     * @return array<string, string>
     */
    public function globals(): array
    {
        /** @var array<string, string|null> $map */
        $map = config('communications.templates.global_variables', []);
        $values = [];

        foreach ($map as $name => $settingKey) {
            $values[$name] = $settingKey === null
                ? $this->computedGlobal($name)
                : (string) (setting($settingKey) ?? '');
        }

        return array_filter($values, fn (string $v): bool => $v !== '');
    }

    /**
     * Turn a value into something safe to put in a message.
     *
     * Money is the one that matters. A Money object stringifies to the display
     * format, and everything financial in this application is a Money object
     * precisely so that a template cannot receive a raw integer of pesewas and
     * thank somebody for their gift of 5,000 cedis when they gave fifty.
     */
    public function stringify(mixed $value): string
    {
        return match (true) {
            $value === null, $value === false => '',
            $value === true => 'Yes',
            is_scalar($value) => (string) $value,
            $value instanceof \Stringable => (string) $value,
            $value instanceof \DateTimeInterface => $value->format('j F Y'),
            $value instanceof \BackedEnum => (string) $value->value,
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<int, string>  $missing  filled by reference
     */
    private function replace(string $body, array $values, bool $escape, array &$missing): string
    {
        [$open, $close] = $this->delimiters();

        return (string) preg_replace_callback(
            '/'.preg_quote($open, '/').'\s*([a-zA-Z0-9_.]+)\s*'.preg_quote($close, '/').'/',
            function (array $m) use ($values, $escape, &$missing): string {
                $name = $m[1];

                // Missing and empty are the same failure from the reader's
                // point of view: "Dear ," either way.
                $raw = data_get($values, $name);
                $value = $this->stringify($raw);

                if ($value === '') {
                    $missing[] = $name;

                    // Non-required placeholders collapse to nothing rather than
                    // leaving a raw token in the message. A donor must never be
                    // shown {{anything}} — the same rule the settings layer
                    // applies to unfilled {{PLACEHOLDER}} values.
                    return '';
                }

                return $escape ? e($value) : $value;
            },
            $body,
        );
    }

    /** @return array{0: string, 1: string} */
    private function delimiters(): array
    {
        /** @var array{0: string, 1: string} $d */
        $d = config('communications.templates.delimiters', ['{{', '}}']);

        return $d;
    }

    /**
     * Globals with no setting behind them.
     *
     * `site_url` comes from APP_URL rather than a setting, because it is not
     * content: the address the application answers on is decided by the
     * deployment, and a settings row holding a different one would produce
     * links into a site that is not this one.
     */
    private function computedGlobal(string $name): string
    {
        return match ($name) {
            'current_year' => (string) now()->year,
            'site_url' => rtrim((string) config('app.url'), '/'),
            default => '',
        };
    }

    /**
     * A human list of the placeholders in a body, for the editor's help text.
     */
    public function describe(string $body): string
    {
        $found = $this->placeholders($body);

        return $found === []
            ? 'No variables used.'
            : 'Uses: '.implode(', ', array_map(fn (string $n): string => '{{'.$n.'}}', $found));
    }

    /** Normalise a declared variable name the way the parser will see it. */
    public function normaliseName(string $name): string
    {
        return Str::of($name)->trim()->replace(['{{', '}}'], '')->trim()->toString();
    }
}

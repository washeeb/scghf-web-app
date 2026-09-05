<?php

declare(strict_types=1);

namespace App\Support;

/**
 * One answer on the Site Health page.
 *
 * ── Four states, and "unknown" is one of them ───────────────────────────────
 *
 * A check that cannot reach what it is checking must say so rather than pick a
 * side. "The SMS balance could not be read" is a different fact from "there are
 * no credits left", and reporting the second when the first is true sends
 * somebody to top up an account that is fine — or, far worse, reports green
 * because a failed request returned nothing and nothing looked like zero.
 *
 * ── Every check carries what to do about it ─────────────────────────────────
 *
 * A health page that says "Queue: warning" to a foundation administrator has
 * told them nothing they can act on. `advice` is the sentence that says what
 * broke and what to do — usually which cron line is missing.
 */
class HealthCheck
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $status,
        public readonly string $value,
        public readonly ?string $advice = null,
    ) {}

    public static function ok(string $key, string $label, string $value): self
    {
        return new self($key, $label, self::OK, $value);
    }

    public static function warning(string $key, string $label, string $value, string $advice): self
    {
        return new self($key, $label, self::WARNING, $value, $advice);
    }

    public static function critical(string $key, string $label, string $value, string $advice): self
    {
        return new self($key, $label, self::CRITICAL, $value, $advice);
    }

    public static function unknown(string $key, string $label, string $value, ?string $advice = null): self
    {
        return new self($key, $label, self::UNKNOWN, $value, $advice);
    }

    public function isProblem(): bool
    {
        return $this->status === self::WARNING || $this->status === self::CRITICAL;
    }

    /** The Filament badge colour for this state. */
    public function colour(): string
    {
        return match ($this->status) {
            self::OK => 'success',
            self::WARNING => 'warning',
            self::CRITICAL => 'danger',
            default => 'gray',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::OK => __('Fine'),
            self::WARNING => __('Needs attention'),
            self::CRITICAL => __('Broken'),
            default => __('Cannot tell'),
        };
    }
}

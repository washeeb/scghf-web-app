<?php

declare(strict_types=1);

namespace App\Communications;

/**
 * What a provider said about one message.
 *
 * Normalised, so nothing above this layer has to know the difference between
 * Arkesel's response shape and Hubtel's.
 */
final readonly class SmsResult
{
    /** @param array<string, mixed> $raw */
    private function __construct(
        public bool $successful,
        public ?string $providerMessageId = null,
        public ?string $providerStatus = null,
        public ?string $message = null,
        public array $raw = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function accepted(
        ?string $providerMessageId = null,
        ?string $providerStatus = null,
        array $raw = [],
    ): self {
        return new self(true, $providerMessageId, $providerStatus, null, $raw);
    }

    /** @param array<string, mixed> $raw */
    public static function rejected(string $message, ?string $providerStatus = null, array $raw = []): self
    {
        return new self(false, null, $providerStatus, $message, $raw);
    }
}

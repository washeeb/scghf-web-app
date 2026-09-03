<?php

declare(strict_types=1);

namespace App\Payments;

use App\ValueObjects\Money;

/**
 * A gateway's answer, normalised.
 *
 * Immutable, and deliberately narrow: it carries what the application needs to
 * decide what happened, and nothing that would let Paystack's payload shape
 * leak into the donation code.
 *
 * `raw` is the scrubbed payload, kept for the audit trail rather than for
 * reading — anything the application actually depends on has a named property
 * here, so a change in the gateway's JSON breaks the mapping in one place
 * instead of silently changing behaviour in five.
 */
final readonly class GatewayResult
{
    /**
     * @param  array<string, mixed>  $raw  scrubbed payload
     * @param  array<string, mixed>  $authorization  card/MoMo metadata, never card data
     */
    private function __construct(
        public bool $successful,
        public string $status,
        public ?string $gatewayReference = null,
        public ?Money $amount = null,
        public ?Money $fee = null,
        public ?string $authorizationUrl = null,
        public ?string $accessCode = null,
        public ?string $channel = null,
        public ?\DateTimeInterface $paidAt = null,
        public ?string $message = null,
        public array $raw = [],
        public array $authorization = [],
    ) {}

    /** @param array<string, mixed> $raw */
    public static function initialised(
        string $gatewayReference,
        ?string $authorizationUrl = null,
        ?string $accessCode = null,
        array $raw = [],
    ): self {
        return new self(
            successful: true,
            status: 'initialised',
            gatewayReference: $gatewayReference,
            authorizationUrl: $authorizationUrl,
            accessCode: $accessCode,
            raw: $raw,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $authorization
     */
    public static function succeeded(
        string $gatewayReference,
        Money $amount,
        ?Money $fee = null,
        ?string $channel = null,
        ?\DateTimeInterface $paidAt = null,
        array $raw = [],
        array $authorization = [],
    ): self {
        return new self(
            successful: true,
            status: 'success',
            gatewayReference: $gatewayReference,
            amount: $amount,
            fee: $fee,
            channel: $channel,
            paidAt: $paidAt,
            raw: $raw,
            authorization: $authorization,
        );
    }

    /** @param array<string, mixed> $raw */
    public static function failed(
        string $status,
        string $message,
        ?string $gatewayReference = null,
        array $raw = [],
    ): self {
        return new self(
            successful: false,
            status: $status,
            gatewayReference: $gatewayReference,
            message: $message,
            raw: $raw,
        );
    }

    public function isSuccess(): bool
    {
        return $this->status === 'success';
    }

    /**
     * Whether the gateway is still deciding.
     *
     * Mobile money in Ghana is frequently here for a minute or more while the
     * donor approves the prompt on their handset — it is a normal state, not a
     * problem, and treating it as a failure would abandon live payments.
     */
    public function isPending(): bool
    {
        return in_array($this->status, ['pending', 'ongoing', 'processing', 'send_otp', 'pay_offline'], true);
    }
}

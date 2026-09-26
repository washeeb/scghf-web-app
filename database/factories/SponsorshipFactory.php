<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Sponsorship;
use App\ValueObjects\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sponsorship>
 */
class SponsorshipFactory extends Factory
{
    protected $model = Sponsorship::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'beneficiary_id' => Beneficiary::factory(),
            'amount' => Money::ofMinor(15000),
            'frequency' => 'monthly',
        ];
    }

    /**
     * Cleared to receive news, but not photographs.
     *
     * The flags are set explicitly and never by default: a sponsorship that has
     * not been through the consent check tells the sponsor nothing at all, and
     * a factory that defaulted them on would let that gate rot untested.
     */
    public function cleared(bool $photographs = false, bool $givenName = false): static
    {
        return $this->afterCreating(fn (Sponsorship $s) => $s->forceFill([
            'status' => Sponsorship::STATUS_ACTIVE,
            'may_receive_updates' => true,
            'may_receive_photographs' => $photographs,
            'may_know_given_name' => $givenName,
            'started_on' => now()->toDateString(),
        ])->save());
    }
}

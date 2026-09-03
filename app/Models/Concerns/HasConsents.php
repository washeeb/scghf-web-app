<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Consent;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Content whose publication depends on somebody having agreed to it.
 *
 * The gate is deliberately CLOSED by default: `hasConsentFor()` returns false
 * when no consent row exists at all. An absent record is not permission — it is
 * the most common way permission ends up assumed.
 *
 * @see Consent
 */
trait HasConsents
{
    /** @return MorphMany<Consent, $this> */
    public function consents(): MorphMany
    {
        return $this->morphMany(Consent::class, 'consentable');
    }

    /**
     * Whether a granted, unexpired, unrevoked consent of this type exists.
     *
     * @param  string  $scope  the publication channel — website, print, social
     */
    public function hasConsentFor(string $type, string $scope = Consent::SCOPE_WEBSITE): bool
    {
        return $this->consents
            ->where('consent_type', $type)
            ->filter(fn (Consent $consent): bool => $consent->isValid() && $consent->coversScope($scope))
            ->isNotEmpty();
    }

    /** The valid consent of this type, for showing who gave it and when. */
    public function consentFor(string $type, string $scope = Consent::SCOPE_WEBSITE): ?Consent
    {
        return $this->consents
            ->where('consent_type', $type)
            ->first(fn (Consent $consent): bool => $consent->isValid() && $consent->coversScope($scope));
    }

    /**
     * Every consent type this record needs before it may be published.
     *
     * Overridden per model — a story with a photograph needs more than a story
     * without one, and only the model knows which.
     *
     * @return array<int, string>
     */
    public function requiredConsentTypes(): array
    {
        return [];
    }

    /**
     * The consent types that are required but not held.
     *
     * Returned as a list rather than a boolean so the administrator is told
     * WHICH consent is missing. "Cannot publish" with no explanation is how a
     * consent requirement gets worked around instead of satisfied.
     *
     * @return array<int, string>
     */
    public function missingConsents(string $scope = Consent::SCOPE_WEBSITE): array
    {
        return array_values(array_filter(
            $this->requiredConsentTypes(),
            fn (string $type): bool => ! $this->hasConsentFor($type, $scope),
        ));
    }

    public function hasAllRequiredConsents(string $scope = Consent::SCOPE_WEBSITE): bool
    {
        return $this->missingConsents($scope) === [];
    }
}

<?php

declare(strict_types=1);

namespace App\Payments;

use App\Models\Donor;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Fold one donor record into another.
 *
 * ── Why this exists ─────────────────────────────────────────────────────────
 *
 * Donors are matched on email, then phone. Somebody who gives once from
 * their work address and again from their personal one is two records, and
 * their lifetime total is split across them. Merging is the correction.
 *
 * ── The money moves, the person is kept once ────────────────────────────────
 *
 * Every financial row that pointed at the duplicate — donations, regular
 * gifts, pledges, sponsorships — is re-pointed at the survivor. Nothing is
 * deleted from those tables: a donation is a six-year statutory record and
 * a merge is a change of who it is filed under, not of what it was.
 *
 * The survivor's contact details win. Blank fields are filled from the
 * duplicate; a filled one is never overwritten, because the person doing
 * the merge chose which record to keep. Consent is adopted only where the
 * survivor has none and the duplicate had it, and its evidence (text, IP,
 * time) comes with it, so the survivor never claims consent it cannot show.
 *
 * The duplicate is soft-deleted with a note saying where it went and audited
 * as `donor.merged`, so an erasure request or a dispute can be traced back.
 */
final class DonorMerger
{
    /** @var list<string> The tables that file something under a donor. */
    private const TABLES = ['donations', 'subscriptions', 'pledges', 'sponsorships'];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, int> Rows moved, per table. */
    public function merge(Donor $survivor, Donor $duplicate, ?User $by = null): array
    {
        if ($survivor->is($duplicate)) {
            throw new InvalidArgumentException('A donor cannot be merged into themselves.');
        }

        if ($duplicate->trashed()) {
            throw new InvalidArgumentException('The duplicate donor has already been removed.');
        }

        return DB::transaction(function () use ($survivor, $duplicate, $by): array {
            $moved = [];

            foreach (self::TABLES as $table) {
                $moved[$table] = DB::table($table)
                    ->where('donor_id', $duplicate->getKey())
                    ->update(['donor_id' => $survivor->getKey()]);
            }

            $moved['tags'] = $this->moveTags($survivor, $duplicate);

            $this->adoptDetails($survivor, $duplicate);
            $survivor->save();
            $survivor->recalculateTotals();

            // One account, one donor: the site login now resolves to the survivor.
            $duplicate->user_id = null;
            $duplicate->notes = trim(($duplicate->notes ? $duplicate->notes."\n" : '')
                .__('Merged into donor :id on :date.', ['id' => $survivor->getKey(), 'date' => now()->toDateString()]));
            $duplicate->save();
            $duplicate->delete();

            $this->audit->record('donor.merged', 'Donor '.$duplicate->getKey().' merged into '.$survivor->getKey(), $survivor, $by, [
                'duplicate_id' => $duplicate->getKey(),
                'survivor_id' => $survivor->getKey(),
                'moved' => $moved,
            ]);

            return $moved;
        });
    }

    /**
     * Tags the survivor already has are dropped from the duplicate rather
     * than moved, because the pivot's primary key would refuse the copy.
     */
    private function moveTags(Donor $survivor, Donor $duplicate): int
    {
        $already = $survivor->tags()->pluck('tags.id')->all();

        DB::table('taggables')
            ->where('taggable_type', $duplicate->getMorphClass())
            ->where('taggable_id', $duplicate->getKey())
            ->whereIn('tag_id', $already)
            ->delete();

        return DB::table('taggables')
            ->where('taggable_type', $duplicate->getMorphClass())
            ->where('taggable_id', $duplicate->getKey())
            ->update(['taggable_id' => $survivor->getKey()]);
    }

    private function adoptDetails(Donor $survivor, Donor $duplicate): void
    {
        foreach (['email', 'phone', 'phone_raw', 'address', 'city', 'country', 'organisation_name', 'user_id'] as $field) {
            if (blank($survivor->{$field}) && filled($duplicate->{$field})) {
                $survivor->{$field} = $duplicate->{$field};
            }
        }

        $adoptsConsent = false;

        foreach (['consent_email', 'consent_sms'] as $consent) {
            if (! $survivor->{$consent} && $duplicate->{$consent}) {
                $survivor->{$consent} = true;
                $adoptsConsent = true;
            }
        }

        if ($adoptsConsent && blank($survivor->consent_text)) {
            $survivor->consent_text = $duplicate->consent_text;
            $survivor->consent_ip = $duplicate->consent_ip;
            $survivor->consent_at = $duplicate->consent_at;
        }

        if (filled($duplicate->notes)) {
            $survivor->notes = trim(($survivor->notes ? $survivor->notes."\n" : '').$duplicate->notes);
        }
    }
}

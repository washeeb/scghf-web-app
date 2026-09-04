<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The only thing that writes to `audit_logs`.
 *
 * It is the only writer because it is the only thing that knows how to extend
 * the hash chain: a row inserted any other way would carry no valid hash and
 * would break verification for every row after it. `AuditLog` is fully guarded
 * to make that impossible rather than merely discouraged.
 *
 * ── Three rules worth knowing ───────────────────────────────────────────────
 *
 * 1. AN UNDECLARED EVENT THROWS. Events are declared in config/system.php with
 *    a category and a severity. A new export screen added in a year has to be
 *    classified before it can log, rather than logging as `unknown` and being
 *    invisible in every report that groups by category.
 *
 * 2. VOLUME ESCALATES SEVERITY. An action touching more than the configured
 *    threshold is recorded as `critical` whatever it was declared as. One donor
 *    record viewed is somebody doing their job; two thousand exported is a
 *    question that needs asking the same day.
 *
 * 3. AUDITING NEVER BREAKS THE ACTION. If writing the entry fails, the failure
 *    is logged to the application log — which is off-database and therefore
 *    exactly where it should go — and the caller proceeds. An audit trail that
 *    can take down a donation form would be removed within a week, and then
 *    there would be no audit trail at all.
 */
class AuditLogger
{
    /**
     * Record an action.
     *
     * @param  array<string, mixed>  $context  scrubbed before storage
     */
    public function record(
        string $event,
        string $description,
        ?Model $subject = null,
        ?User $causer = null,
        array $context = [],
        ?int $recordCount = null,
    ): ?AuditLog {
        $definition = $this->definition($event);
        $causer ??= $this->currentUser();

        try {
            return $this->write($event, $definition, $description, $subject, $causer, $context, $recordCount);
        } catch (Throwable $e) {
            /*
             * The audit write failed. Say so somewhere that is not the database
             * — because if the database is the problem, the database is the
             * wrong place to complain about it — and let the caller carry on.
             */
            Log::critical('Failed to write an audit entry. The action itself was not stopped.', [
                'event' => $event,
                'description' => $description,
                'causer' => $causer?->getKey(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Record an action that took personal data out of the application.
     *
     * A separate method because an export is never routine: it leaves the
     * application's protections behind and lands in somebody's Downloads
     * folder, on a laptop, on a bus. The record count is mandatory here — it is
     * the number that distinguishes a lookup from an incident.
     *
     * @param  array<string, mixed>  $context
     */
    public function recordExport(
        string $event,
        string $what,
        int $recordCount,
        ?User $causer = null,
        array $context = [],
    ): ?AuditLog {
        return $this->record(
            event: $event,
            description: sprintf('Exported %s %s.', number_format($recordCount), $what),
            causer: $causer,
            context: $context,
            recordCount: $recordCount,
        );
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $context
     */
    private function write(
        string $event,
        array $definition,
        string $description,
        ?Model $subject,
        ?User $causer,
        array $context,
        ?int $recordCount,
    ): AuditLog {
        /*
         * The whole insert runs under a lock on the current head.
         *
         * Two concurrent writes both reading the same `previous_hash` would
         * produce a fork rather than a chain, and the verifier would report a
         * break that never happened. Serialising the tail is the cost of the
         * chain being meaningful.
         */
        return DB::transaction(function () use (
            $event, $definition, $description, $subject, $causer, $context, $recordCount
        ): AuditLog {
            $previous = AuditLog::query()->orderByDesc('id')->lockForUpdate()->first();

            $entry = new AuditLog;

            $entry->forceFill([
                'ulid' => (string) Str::ulid(),
                'event' => $event,
                'category' => $definition['category'],
                'severity' => $this->severity($definition, $recordCount),
                'description' => Str::limit($description, 500, ''),
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'causer_id' => $causer?->getKey(),
                // Snapshotted, because the foreign key nulls when a staff
                // member is deleted and a trail that forgets who did something
                // the moment they leave is not a trail.
                'causer_label' => $causer === null ? null : $this->label($causer),
                'impersonator_id' => $this->impersonatorId(),
                'context' => $this->scrub($context),
                'record_count' => $recordCount,
                'ip_address' => $this->ip(),
                'user_agent' => $this->userAgent(),
                'occurred_at' => now(),
                'previous_hash' => $previous?->hash,
                'created_at' => now(),
            ]);

            $entry->hash = $entry->computeHash();
            $entry->save();

            return $entry;
        });
    }

    /**
     * The declared category and severity for an event.
     *
     * @return array<string, mixed>
     */
    private function definition(string $event): array
    {
        /*
         * The whole map is read and indexed directly, NOT
         * `config("system.audit.events.{$event}")`.
         *
         * Event names contain dots — `donation.marked_needs_review` — and
         * `config()` treats a dot as a path separator, so that lookup would
         * descend into a `donation` key that does not exist and return null for
         * every event in the file. The failure is total and looks exactly like
         * a missing definition, which is a confusing way to lose an audit trail.
         */
        /** @var array<string, array<string, mixed>> $events */
        $events = config('system.audit.events', []);
        $definition = $events[$event] ?? null;

        if ($definition === null) {
            throw new RuntimeException(
                "No audit definition for [{$event}]. Every audited action declares a category "
                .'and a severity in config/system.php, so that the set of audited actions is a '
                .'list somebody can check against a policy — and so an action nobody classified '
                .'is visible by its absence rather than filed under "unknown".'
            );
        }

        return $definition;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function severity(array $definition, ?int $recordCount): string
    {
        $declared = (string) ($definition['severity'] ?? AuditLog::SEVERITY_INFO);
        $threshold = (int) config('system.audit.bulk_threshold', 100);

        // Volume escalates. The same event is a different fact at a different
        // scale, and the scale is the part somebody needs to see first.
        return ($recordCount !== null && $recordCount > $threshold)
            ? AuditLog::SEVERITY_CRITICAL
            : $declared;
    }

    /**
     * Remove anything that should never sit in an audit entry.
     *
     * The context is meant to hold WHICH filters an export used, not WHAT it
     * contained. An audit trail that quietly accumulated the data it was
     * auditing access to would be the largest unmanaged copy of that data in
     * the application.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function scrub(array $context): array
    {
        /** @var array<int, string> $forbidden */
        $forbidden = config('system.errors.never_capture', []);

        foreach ($context as $key => $value) {
            foreach ($forbidden as $needle) {
                if (str_contains(mb_strtolower((string) $key), $needle)) {
                    $context[$key] = '[redacted]';

                    continue 2;
                }
            }

            if (is_array($value)) {
                $context[$key] = $this->scrub($value);
            }
        }

        return $context;
    }

    private function label(User $user): string
    {
        return trim(($user->name ?? '').' <'.($user->email ?? '').'>');
    }

    private function currentUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The administrator behind an impersonated session, if there is one.
     *
     * Read from the session key Filament's impersonation uses. Absent in a
     * console context, which is correct — a command is not impersonating
     * anybody.
     */
    private function impersonatorId(): ?int
    {
        if (! app()->bound('session')) {
            return null;
        }

        $id = session('impersonator_id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function ip(): ?string
    {
        return app()->runningInConsole() ? null : request()->ip();
    }

    private function userAgent(): ?string
    {
        return app()->runningInConsole()
            ? 'console'
            : Str::limit((string) request()->userAgent(), 500, '');
    }
}

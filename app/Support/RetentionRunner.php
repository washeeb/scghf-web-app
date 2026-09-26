<?php

declare(strict_types=1);

namespace App\Support;

use App\Contracts\Retainable;
use App\Models\LegalHold;
use App\Models\RetentionLogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Applies the retention schedule in config('compliance.retention').
 *
 * Design stance: this is destructive code operating on records about vulnerable
 * people, so it is built to fail SAFE. Every guard below prefers keeping a
 * record too long over destroying one it should not have.
 *
 *   - a dry run is the default; destroying requires an explicit flag
 *   - an active legal hold stops everything, and the skip is logged
 *   - a record with no anchor date is never due
 *   - a grace period sits after the due date
 *   - a run that would exceed the batch ceiling aborts and reports
 *   - each record is its own transaction, so one failure cannot cascade
 *   - the audit log is written BEFORE the data is destroyed
 */
class RetentionRunner
{
    /** @var array<string, class-string<Model&Retainable>> */
    private array $subjects = [];

    private string $runId;

    public function __construct()
    {
        $this->runId = (string) Str::uuid();
    }

    /**
     * Register a model as subject to a retention class.
     *
     * Called from a service provider as each module lands, so the runner has no
     * knowledge of models that do not exist yet.
     *
     * @param  class-string<Model&Retainable>  $model
     */
    public function register(string $retentionClass, string $model): void
    {
        $this->subjects[$retentionClass][] = $model;
    }

    /**
     * Evaluate every registered class.
     *
     * @return array{run_id: string, dry_run: bool, due: int, acted: int, held: int, failed: int, detail: array<int, string>}
     */
    public function run(bool $execute = false, ?User $actor = null): array
    {
        $summary = [
            'run_id' => $this->runId,
            'dry_run' => ! $execute,
            'due' => 0, 'acted' => 0, 'held' => 0, 'failed' => 0,
            'detail' => [],
        ];

        foreach ($this->subjects as $retentionClass => $models) {
            $policy = $this->policy($retentionClass);

            // The ceiling guards against a wrong anchor date sweeping a table.
            // A class whose normal volume is thousands a month (the delivery
            // logs) names its own in config, or the guard fires every month.
            $ceiling = (int) ($policy['batch_ceiling'] ?? config('compliance.retention.max_records_per_run', 500));

            // 'retain' classes are never swept. Financial records have a
            // statutory MINIMUM, not a deletion date — destroying accounting
            // records on a timer is a bigger risk than keeping them.
            if (($policy['action'] ?? 'retain') === 'retain') {
                continue;
            }

            foreach ($models as $model) {
                $due = $this->dueFor($model, $retentionClass, $policy, $ceiling);
                $summary['due'] += $due->count();

                if ($due->count() > $ceiling) {
                    $summary['detail'][] = sprintf(
                        'ABORTED %s: %d records due exceeds the ceiling of %d. '
                        .'A sudden backlog is far more likely to be a wrong anchor date than a real one.',
                        $retentionClass, $due->count(), $ceiling,
                    );

                    Log::critical('Retention run aborted: batch ceiling exceeded', [
                        'run_id' => $this->runId,
                        'class' => $retentionClass,
                        'count' => $due->count(),
                    ]);

                    continue;
                }

                foreach ($due as $record) {
                    $result = $this->process($record, $retentionClass, $policy, $execute, $actor);
                    $summary[$result]++;
                }
            }
        }

        return $summary;
    }

    /**
     * Records past their retention date plus the grace period.
     *
     * Walked in slices, never loaded whole: the email and SMS logs are the
     * largest tables a retention run reads, and this ran monthly under a
     * worker memory limit. The walk stops one past the batch ceiling —
     * that is enough to know the run must abort, and the run needs nothing
     * beyond the ceiling in any case. The anchor date is a method on the
     * record, not a column, so the filter is in PHP; the slice keeps that
     * honest about memory.
     *
     * @param  class-string<Model&Retainable>  $model
     * @param  array<string, mixed>  $policy
     * @return Collection<int, Model&Retainable>
     */
    public function dueFor(string $model, string $retentionClass, array $policy, ?int $ceiling = null): Collection
    {
        $months = $policy['months'] ?? null;

        if ($months === null) {
            return collect();
        }

        $grace = (int) config('compliance.retention.grace_period_days', 30);
        $cutoff = now()->subMonths((int) $months)->subDays($grace);
        $ceiling ??= (int) config('compliance.retention.max_records_per_run', 500);

        $due = collect();

        $records = $model::query()
            ->when(
                method_exists($model, 'scopeRetentionCandidates'),
                fn (Builder $q) => $q->retentionCandidates(),
            )
            ->lazyById(200);

        foreach ($records as $record) {
            /** @var Model&Retainable $record */
            if ($record->retentionClass() !== $retentionClass) {
                continue;
            }

            $anchor = $record->retentionAnchorDate();

            // No anchor means the clock has not started. An open case has
            // no closure date and must never be swept up.
            if ($anchor === null || $anchor->gt($cutoff)) {
                continue;
            }

            $due->push($record);

            if ($due->count() > $ceiling) {
                break;
            }
        }

        return $due;
    }

    /**
     * @param  Model&Retainable  $record
     * @param  array<string, mixed>  $policy
     * @return 'acted'|'held'|'failed'
     */
    private function process(
        Model $record,
        string $retentionClass,
        array $policy,
        bool $execute,
        ?User $actor,
    ): string {
        $hold = LegalHold::covers($record, $retentionClass, $record->retentionScopeKey());

        if ($hold !== null) {
            if ($execute) {
                $this->log($record, $retentionClass, 'skipped_hold',
                    "Held by {$hold->reference}: {$hold->title}", $hold->getKey(), $actor);
            }

            return 'held';
        }

        if (! $execute) {
            return 'acted';
        }

        $action = $policy['action'];

        try {
            DB::transaction(function () use ($record, $retentionClass, $action, $actor): void {
                // The log is written FIRST. If the destruction fails the log is
                // rolled back with it; if the log failed after destruction we
                // would have destroyed data with no record of having done so,
                // which is the one outcome with no remedy.
                $this->log($record, $retentionClass, $action, null, null, $actor);

                if ($action === 'de_identify') {
                    $record->deIdentify();
                } else {
                    // forceDelete, not delete. A soft-deleted record still holds
                    // the personal data, so it would not satisfy Act 843 at all.
                    method_exists($record, 'forceDelete')
                        ? $record->forceDelete()
                        : $record->delete();
                }
            });

            return 'acted';
        } catch (\Throwable $e) {
            Log::error('Retention action failed', [
                'run_id' => $this->runId,
                'class' => $retentionClass,
                'subject' => $record::class.':'.$record->getKey(),
                'error' => $e->getMessage(),
            ]);

            $this->log($record, $retentionClass, 'skipped_error', $e->getMessage(), null, $actor);

            return 'failed';
        }
    }

    /** @param Model&Retainable $record */
    private function log(
        Model $record,
        string $retentionClass,
        string $action,
        ?string $detail = null,
        ?int $holdId = null,
        ?User $actor = null,
    ): void {
        RetentionLogEntry::create([
            'retention_class' => $retentionClass,
            'subject_type' => $record->getMorphClass(),
            'subject_id' => $record->getKey(),
            // Taken before destruction, and one-way. Lets a specific person be
            // matched against the log on request without the log holding their
            // details.
            'subject_digest' => $record->retentionDigest(),
            'action' => $action,
            'detail' => $detail,
            'legal_hold_id' => $holdId,
            'run_id' => $this->runId,
            'performed_by' => $actor?->getKey(),
            'created_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function policy(string $retentionClass): array
    {
        $policy = config("compliance.retention.classes.{$retentionClass}");

        if ($policy === null) {
            throw new RuntimeException(
                "No retention policy defined for [{$retentionClass}]. "
                .'Every class of personal data must state a purpose and a period.'
            );
        }

        return $policy;
    }

    /** @return array<string, class-string> */
    public function registered(): array
    {
        return $this->subjects;
    }

    public function runId(): string
    {
        return $this->runId;
    }

    /** When a record under this policy would fall due. */
    public function dueDateFor(Retainable $record): ?Carbon
    {
        $policy = $this->policy($record->retentionClass());
        $anchor = $record->retentionAnchorDate();

        if ($anchor === null || ($policy['months'] ?? null) === null) {
            return null;
        }

        return $anchor->copy()
            ->addMonths((int) $policy['months'])
            ->addDays((int) config('compliance.retention.grace_period_days', 30));
    }
}

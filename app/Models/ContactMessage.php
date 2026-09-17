<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property string $reference
 * @property string $status
 */
class ContactMessage extends Model
{
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = [
        'contact_department_id', 'name', 'email', 'phone', 'subject', 'message',
        'consent_given', 'consent_text', 'ip_address', 'user_agent', 'source_url',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'new',
        'consent_given' => false,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'consent_given' => 'boolean',
            'replied_at' => 'datetime',
            'sla_reminded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $message): void {
            // Quoted back in the auto-reply so the sender can refer to it, and
            // so support can find it without asking for their email address.
            $message->reference ??= 'SCGHF-C-'.Str::upper(Str::random(8));
            $message->email = mb_strtolower(trim($message->email));
        });
    }

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    /** @return BelongsTo<ContactDepartment, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(ContactDepartment::class, 'contact_department_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Whether this message is confidential.
     *
     * A safeguarding report must never appear in the general admin inbox
     * beside shop queries. The routing decision lives on the department, so a
     * new confidential department is a settings change rather than a code one.
     */
    public function isConfidential(): bool
    {
        return $this->department?->is_confidential ?? false;
    }

    /** Whether the SLA has been missed — drives the overdue flag in the inbox. */
    public function isOverdue(): bool
    {
        $sla = $this->department?->sla_hours;

        if ($sla === null || $this->replied_at !== null) {
            return false;
        }

        return $this->created_at->addHours($sla)->isPast();
    }

    public function markReplied(User $user): void
    {
        $this->forceFill([
            'status' => 'replied',
            'replied_at' => now(),
            'replied_by' => $user->getKey(),
        ])->save();
    }

    /**
     * The general inbox — confidential departments excluded.
     *
     * Every admin listing must use this scope rather than an unscoped query,
     * which is why the exclusion lives here and not in a controller.
     */
    #[Scope]
    protected function generalInbox(Builder $query): void
    {
        $query->whereDoesntHave('department', fn (Builder $q) => $q->where('is_confidential', true))
            ->orderByDesc('created_at');
    }

    #[Scope]
    protected function unresolved(Builder $query): void
    {
        $query->whereIn('status', ['new', 'assigned']);
    }

    /** Unresolved, past the department's target, and nobody told yet. */
    #[Scope]
    protected function needingSlaReminder(Builder $query): void
    {
        $query->unresolved()
            ->whereNull('replied_at')
            ->whereNull('sla_reminded_at')
            ->whereHas('department', fn (Builder $q) => $q->whereNotNull('sla_hours')
                ->whereRaw('contact_messages.created_at <= DATE_SUB(NOW(), INTERVAL contact_departments.sla_hours HOUR)'));
    }
}

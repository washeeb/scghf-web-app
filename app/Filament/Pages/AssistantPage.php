<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Ai\AiManager;
use App\Chat\Agent\ChatAgent;
use App\Models\AiInteraction;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * What the assistant did this month, what it cost, and what it got wrong.
 *
 * ── Three questions, one screen ─────────────────────────────────────────────
 *
 * Is it on? What are we spending? What has it said that a member of staff
 * thought was wrong? A charity running an automated assistant on a donation
 * website has to be able to answer all three without asking a developer, and
 * the third one is the one that matters: an assistant nobody reviews is an
 * assistant nobody can defend.
 *
 * ── The spend is an estimate and says so ────────────────────────────────────
 *
 * It is the sum of what each call was estimated to cost from the rates in
 * `config/ai.php`, not an invoice. Presenting it as the bill would be the
 * kind of number somebody reconciles against a statement and then distrusts
 * the whole screen for.
 */
class AssistantPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static string|UnitEnum|null $navigationGroup = 'Inbox';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.assistant';

    protected static ?string $slug = 'assistant';

    public static function getNavigationLabel(): string
    {
        return __('Assistant');
    }

    public function getTitle(): string
    {
        return __('The chat assistant');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('chat.view') ?? false;
    }

    /** Only once there is something to show: an empty screen is noise. */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess()
            && (app(ChatAgent::class)->enabled() || AiInteraction::query()->exists());
    }

    public static function getNavigationBadge(): ?string
    {
        $flagged = AiInteraction::query()->where('flagged', true)->count();

        return $flagged > 0 ? (string) $flagged : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /** @return array<string, mixed> */
    public function getViewData(): array
    {
        $manager = app(AiManager::class);
        $agent = app(ChatAgent::class);

        $month = AiInteraction::query()->where('created_at', '>=', now()->startOfMonth());

        return [
            'on' => $agent->enabled(),
            'driver' => $manager->driver()->name(),
            'model' => (string) config('ai.anthropic.model'),
            'usable' => $manager->driver()->usable(),
            'overBudget' => $manager->overBudget(),
            'ceilingMinor' => (int) config('ai.monthly_budget_minor', 0),
            'spentMinor' => $manager->spentThisMonthMinor(),
            'whenStaffOnline' => (bool) setting('agent.when_staff_online', false),
            'answered' => (clone $month)->where('outcome', AiInteraction::OUTCOME_ANSWERED)->count(),
            'handed' => (clone $month)->where('outcome', AiInteraction::OUTCOME_HANDOVER)->count(),
            'failed' => (clone $month)->where('outcome', AiInteraction::OUTCOME_FAILED)->count(),
            'medianMs' => (int) round((float) (clone $month)->where('outcome', AiInteraction::OUTCOME_ANSWERED)->avg('latency_ms')),
            'reasons' => $this->reasons(),
            'flagged' => AiInteraction::query()
                ->where('flagged', true)
                ->with(['conversation', 'flagger'])
                ->latest('flagged_at')
                ->limit(25)
                ->get(),
        ];
    }

    /**
     * Why people were needed, commonest first.
     *
     * The most useful thing on this page after the bill: a month where
     * "topic:donations" is the top reason is a month where the donations page
     * needs rewriting, not a month where the assistant is failing.
     *
     * @return Collection<int, array{reason: string, total: int}>
     */
    private function reasons(): Collection
    {
        return AiInteraction::query()
            ->where('created_at', '>=', now()->startOfMonth())
            ->where('outcome', AiInteraction::OUTCOME_HANDOVER)
            ->whereNotNull('reason')
            ->selectRaw('reason, COUNT(*) as total')
            ->groupBy('reason')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn (AiInteraction $row): array => ['reason' => (string) $row->reason, 'total' => (int) $row->getAttribute('total')]);
    }
}

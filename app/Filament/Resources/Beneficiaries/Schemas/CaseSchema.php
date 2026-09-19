<?php

declare(strict_types=1);

namespace App\Filament\Resources\Beneficiaries\Schemas;

use App\Beneficiaries\CaseAccess;
use App\Beneficiaries\FieldMap;
use App\Filament\Support\MoneyField;
use App\Models\Beneficiary;
use App\Models\Division;
use App\Models\FocusArea;
use App\Models\Project;
use App\Models\ShippingZone;
use App\Models\User;
use App\Support\Anonymiser;
use App\ValueObjects\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The case form and the case page, generated from FieldMap.
 *
 * Nothing here decides who sees what. `CaseAccess::visibleFields()` and
 * `editableFields()` decide; this class turns the answer into Filament
 * components, one per field, grouped into the map's sections. A field the
 * actor may see but not change is a read-only input on the form; a field
 * they may not see is not on the page at all — not hidden, absent, so it
 * is not in the Livewire state either.
 */
final class CaseSchema
{
    public static function form(Schema $schema, ?Beneficiary $case): Schema
    {
        $user = auth()->user();
        $access = app(CaseAccess::class);

        $visible = $user ? $access->visibleFields($user, $case) : [];
        $editable = $case === null
            ? ($user && $access->canCreate($user) ? FieldMap::editable([FieldMap::VIEW_SUMMARY, FieldMap::VIEW_WORKING, FieldMap::VIEW_SENSITIVE], CaseAccess::WORKER) : [])
            : ($user ? $access->editableFields($user, $case) : []);

        // On the form, only what can be typed: the creator is the worker and
        // types everything a worker may; the lifecycle fields are actions.
        $fields = $case === null ? $editable : array_intersect($visible, $editable);

        // The outcome is set by "Close case", not typed at intake.
        if ($case === null) {
            $fields = array_values(array_diff($fields, ['outcome']));
        }

        return $schema->components(self::sections($fields, fn (string $field): ?Component => self::input($field)));
    }

    public static function infolist(Schema $schema, Beneficiary $case): Schema
    {
        $user = auth()->user();
        $fields = $user ? app(CaseAccess::class)->visibleFields($user, $case) : [];

        return $schema->components(self::sections($fields, fn (string $field): Component => self::entry($field, $case)));
    }

    /**
     * @param  array<int, string>  $fields
     * @param  callable(string): ?Component  $make
     * @return array<int, Component>
     */
    private static function sections(array $fields, callable $make): array
    {
        $titles = [
            'summary' => __('The case'),
            'person' => __('The person'),
            'sensitive' => __('Sensitive'),
            'money' => __('Money'),
        ];

        $descriptions = [
            'sensitive' => __('Health, religion and identity. Shown to the worker on this case and the safeguarding lead; every view is recorded.'),
            'money' => __('What was given, and where it was paid.'),
        ];

        $out = [];

        foreach (FieldMap::SECTIONS as $section) {
            $components = [];

            foreach (FieldMap::inSection($section) as $field) {
                if (! in_array($field, $fields, true)) {
                    continue;
                }

                $component = $make($field);

                if ($component !== null) {
                    $components[] = $component;
                }
            }

            if ($components === []) {
                continue;
            }

            $out[] = Section::make($titles[$section])
                ->description($descriptions[$section] ?? null)
                ->columns(2)
                ->collapsed($section === 'sensitive')
                ->schema($components);
        }

        return $out;
    }

    private static function input(string $field): ?Component
    {
        $spec = FieldMap::FIELDS[$field];
        $label = __($spec['label']);

        return match ($spec['type']) {
            'text', 'masked' => $field === 'region'
                ? Select::make($field)->label($label)->options(self::regions())->searchable()
                : TextInput::make($field)->label($label)->maxLength(191)->required($field === 'full_name'),
            'email' => TextInput::make($field)->label($label)->email()->maxLength(191),
            'textarea' => Textarea::make($field)->label($label)->rows(4)->columnSpanFull(),
            'date' => DatePicker::make($field)->label($label)->maxDate(now()),
            'decimal' => TextInput::make($field)->label($label)->numeric(),
            'money' => MoneyField::make($field)->label($label),
            'gender' => Select::make($field)->label($label)->options(['female' => __('Female'), 'male' => __('Male'), 'other' => __('Other / not said')]),
            'outcome' => Select::make($field)->label($label)->options(self::outcomes()),
            'division' => Select::make($field)->label($label)->options(fn (): array => Division::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            'project' => Select::make($field)->label($label)->options(fn (): array => Project::query()->orderBy('title')->pluck('title', 'id')->all())->searchable(),
            'focus_area' => Select::make($field)->label($label)->options(fn (): array => FocusArea::query()->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            'user' => Select::make($field)->label($label)->options(fn (): array => User::query()->whereHas('roles', fn ($q) => $q->whereIn('name', ['Programme Officer', 'Safeguarding Lead']))->orderBy('name')->pluck('name', 'id')->all())->searchable(),
            'media' => null, // photographs and scans are attached as documents, with their consent
            default => null,
        };
    }

    private static function entry(string $field, Beneficiary $case): Component
    {
        $spec = FieldMap::FIELDS[$field];
        $label = __($spec['label']);

        return match ($spec['type']) {
            'status' => TextEntry::make($field)->label($label)->badge()->formatStateUsing(fn (?string $state): string => self::statusLabel((string) $state))->color(fn (?string $state): string => match ($state) {
                Beneficiary::STATUS_APPROVED => 'success',
                Beneficiary::STATUS_DECLINED, Beneficiary::STATUS_WITHDRAWN => 'danger',
                Beneficiary::STATUS_CLOSED => 'gray',
                Beneficiary::STATUS_UNDER_REVIEW => 'warning',
                default => 'info',
            }),
            'datetime' => TextEntry::make($field)->label($label)->dateTime('j M Y, H:i')->placeholder('—'),
            'date' => TextEntry::make($field)->label($label)->date('j F Y')->placeholder('—'),
            'money' => TextEntry::make($field)->label($label)->formatStateUsing(fn (mixed $state): string => $state instanceof Money ? $state->format() : '—')->placeholder('—'),
            'age_band' => TextEntry::make('age_band')->label($label)->state(fn (): string => app(Anonymiser::class)->ageBand($case->date_of_birth, $case->assisted_on) ?? '—'),
            'textarea' => TextEntry::make($field)->label($label)->prose()->placeholder('—')->columnSpanFull(),
            'masked' => TextEntry::make($field)->label($label)->state(fn (): string => self::mask((string) $case->{$field}))->helperText(__('Use "Reveal ID number" above to see it in full; the reveal is recorded.')),
            'division' => TextEntry::make('division.name')->label($label)->placeholder('—'),
            'project' => TextEntry::make('project.title')->label($label)->placeholder('—'),
            'focus_area' => TextEntry::make('focusArea.name')->label($label)->placeholder('—'),
            'user' => TextEntry::make('caseWorker.name')->label($label)->placeholder(__('Unassigned')),
            'media' => TextEntry::make($field)->label($label)->formatStateUsing(fn (): string => __('Attached'))->placeholder(__('None')),
            'outcome' => TextEntry::make($field)->label($label)->formatStateUsing(fn (?string $state): string => self::outcomes()[$state] ?? (string) $state)->placeholder('—'),
            'gender' => TextEntry::make($field)->label($label)->formatStateUsing(fn (?string $state): string => ucfirst((string) $state))->placeholder('—'),
            default => TextEntry::make($field)->label($label)->placeholder('—'),
        };
    }

    public static function mask(string $number): string
    {
        if ($number === '') {
            return '—';
        }

        $tail = substr($number, -4);

        return str_repeat('•', max(0, mb_strlen($number) - 4)).$tail;
    }

    /** @return array<string, string> */
    public static function outcomes(): array
    {
        return [
            'assisted' => __('Assisted'),
            'referred' => __('Referred elsewhere'),
            'partially_assisted' => __('Partly assisted'),
            'declined' => __('Declined'),
            'withdrawn' => __('Withdrawn'),
            'no_longer_needed' => __('No longer needed'),
        ];
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            Beneficiary::STATUS_DRAFT => __('Draft'),
            Beneficiary::STATUS_SUBMITTED => __('Submitted'),
            Beneficiary::STATUS_UNDER_REVIEW => __('Under review'),
            Beneficiary::STATUS_APPROVED => __('Approved'),
            Beneficiary::STATUS_DECLINED => __('Declined'),
            Beneficiary::STATUS_WITHDRAWN => __('Withdrawn'),
            Beneficiary::STATUS_CLOSED => __('Closed'),
            default => ucfirst($status),
        };
    }

    /** @return array<string, string> */
    public static function regions(): array
    {
        return array_combine(ShippingZone::REGIONS, ShippingZone::REGIONS);
    }
}

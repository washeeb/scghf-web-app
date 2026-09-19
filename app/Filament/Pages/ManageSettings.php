<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\SettingType;
use App\Models\Media;
use App\Models\Setting;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use UnitEnum;

/**
 * Everything the site says about itself, in one screen.
 *
 * ── Why this exists at all ──────────────────────────────────────────────────
 *
 * The settings table has carried the foundation's legal name, registration
 * number, phone numbers, bank details, donation limits and receipt wording
 * since Phase 3 — read by the layout, the footer, every email template and the
 * donation form — with no screen in front of it. Every one of those values was
 * editable only by somebody with database access, which is the exact opposite
 * of CLAUDE.md's CMS rule.
 *
 * ── One page with tabs, not one resource per group ──────────────────────────
 *
 * A settings table is not a list of records: nobody creates a setting, nobody
 * deletes one, and searching for one by name is not how anybody looks for the
 * office phone number. They look under "Contact". Tabs match how the values are
 * grouped in the seeder, which is how they are grouped in a person's head.
 *
 * ── The field follows the declared type ─────────────────────────────────────
 *
 * `SettingType` already said what each value is and which rule validates it,
 * and both were read by nothing. A phone number now gets a tel field and the
 * Ghanaian mobile pattern; a colour gets a picker; an amount gets pesewas with
 * the cedi conversion spelled out live underneath, because a foundation that
 * types "50" meaning fifty cedis and gets fifty pesewas has set its minimum
 * donation to half a cedi and will not find out until a donor does.
 *
 * ── An unfilled placeholder still validates ─────────────────────────────────
 *
 * `{{PHONE_PRIMARY}}` is not a valid Ghanaian mobile number, and the strict
 * reading would refuse to save the Contact tab until somebody filled in every
 * field on it — including the ones they came to the screen to avoid. So a value
 * still holding its seeded placeholder is exempt from its own rule. It is
 * already reported by `Settings::unfilled()` and by the preflight command,
 * which is where "this is not real yet" belongs.
 */
class ManageSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Website';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.manage-settings';

    /**
     * Nested by group: `$data['contact']['phone_primary']`.
     *
     * @var array<string, array<string, mixed>>|null
     */
    public ?array $data = [];

    /**
     * The order the tabs appear in, and their headings.
     *
     * Roughly the order somebody sets a site up in: who we are, how to reach
     * us, how money arrives, then the parts of the page. A group in the table
     * but not in this list still gets a tab, appended at the end under its own
     * name — so adding a settings group to the seeder cannot make it invisible.
     *
     * @var array<string, string>
     */
    private const GROUPS = [
        'general' => 'Organisation',
        'contact' => 'Contact',
        'social' => 'Social',
        'donations' => 'Donations',
        'banking' => 'Offline giving',
        'shop' => 'Shop',
        'events' => 'Events',
        'volunteering' => 'Volunteering',
        'communications' => 'Email & SMS',
        'analytics' => 'Analytics',
        'accounting' => 'Accounting',
        'currency' => 'Currency',
        'header' => 'Header',
        'site' => 'Site & footer',
        'seo' => 'Search engines',
        'compliance' => 'Consent wording',
    ];

    public static function getNavigationLabel(): string
    {
        return __('Site settings');
    }

    public function getTitle(): string
    {
        return __('Site settings');
    }

    /**
     * How much of this is still placeholder.
     *
     * The count exists because `Settings::unfilled()` has been able to answer
     * it since Phase 3 and only a console command ever asked. A foundation
     * about to launch should be told, on the screen where they would fix it.
     */
    public function getSubheading(): ?string
    {
        $unfilled = setting()->unfilled()->count();

        return $unfilled === 0
            ? __('Everything the site says about the foundation.')
            : trans_choice(
                '{1}One setting is still waiting for a real value.|[2,*]::count settings are still waiting for real values.',
                $unfilled,
                ['count' => $unfilled],
            );
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Setting::class) ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->fillForm();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make($this->getFormActions())->sticky()->key('form-actions'),
                ]),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->tabs($this->tabs())
                    // So a half-finished edit survives a refresh, and so a
                    // colleague can be sent a link to the tab in question.
                    ->persistTabInQueryString(),
            ])
            ->statePath('data');
    }

    /**
     * @return array<int, Action>
     */
    public function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label(__('Save changes'))
                ->submit('save'),
        ];
    }

    /**
     * Write every changed value back.
     *
     * Through the model rather than through a mass update, so the `updating`
     * hook records the previous value in `settings_history` — the only record
     * of what a receipt issued last March actually said — and the `saved` hook
     * busts the cache, so the change is on the site by the time the editor
     * looks at it.
     */
    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $changed = 0;

        foreach ($this->settings() as $setting) {
            // A locked setting renders disabled, and a disabled field is not
            // submitted at all — but the guard is here rather than only in the
            // form, because "not submitted" and "may not be changed" are not
            // the same claim.
            if ($setting->is_locked) {
                continue;
            }

            if (! array_key_exists($setting->key, $state[$setting->group] ?? [])) {
                continue;
            }

            $value = $this->valueFromForm($setting, $state[$setting->group][$setting->key]);

            /*
             * An encrypted setting whose field came back empty was not cleared,
             * it was simply not retyped — the field never shows what is stored.
             * Treating empty as "delete the secret" would let somebody wipe the
             * SMS credentials by saving an unrelated tab.
             */
            if ($setting->is_encrypted && blank($value)) {
                continue;
            }

            $before = $setting->value;
            $setting->setTypedValue($value);

            if ($setting->value !== $before) {
                $changed++;
            }

            $setting->save();
        }

        Notification::make()
            ->title($changed === 0 ? __('Nothing changed') : __('Saved'))
            ->body($changed === 0
                ? __('No setting was different from what was already stored.')
                : trans_choice(
                    '{1}One setting updated. The site is showing it now.|[2,*]::count settings updated. The site is showing them now.',
                    $changed,
                    ['count' => $changed],
                ))
            ->success()
            ->send();

        $this->fillForm();
    }

    // ── Building the form ───────────────────────────────────────────────────

    /** @return Collection<int, Setting> */
    private function settings(): Collection
    {
        return Setting::query()->orderBy('sort_order')->get();
    }

    private function fillForm(): void
    {
        $state = [];

        foreach ($this->settings() as $setting) {
            $state[$setting->group][$setting->key] = $this->valueForForm($setting);
        }

        $this->form->fill($state);
    }

    /**
     * One tab per group, declared order first and anything unknown after it.
     *
     * @return array<int, Tab>
     */
    private function tabs(): array
    {
        $order = array_flip(array_keys(self::GROUPS));

        return $this->settings()
            ->groupBy('group')
            ->sortBy(fn (Collection $settings, string $group): int => $order[$group] ?? PHP_INT_MAX)
            ->map(fn (Collection $settings, string $group): Tab => Tab::make(__(self::GROUPS[$group] ?? ucfirst($group)))
                ->schema($settings->map(fn (Setting $setting): Field => $this->field($setting))->all()))
            ->values()
            ->all();
    }

    private function field(Setting $setting): Field
    {
        $name = $setting->group.'.'.$setting->key;

        // A Select with no options is a field nobody can fill. Falling back to
        // free text keeps the setting editable if one is added to the seeder
        // without its list.
        $type = ($setting->type === SettingType::Select && blank($setting->options))
            ? SettingType::String
            : $setting->type;

        [$field, $typeHint] = match ($type) {
            SettingType::Boolean => [Toggle::make($name), null],

            SettingType::Text => [Textarea::make($name)->rows(3), null],

            SettingType::Html => [Textarea::make($name)->rows(6), null],

            SettingType::Json => [
                Textarea::make($name)->rows(3),
                __('A JSON list or object, e.g. ["Faith","Compassion"].'),
            ],

            SettingType::Colour => [ColorPicker::make($name), null],

            SettingType::Integer => [TextInput::make($name)->numeric(), null],

            /*
             * Pesewas, spelled out as you type.
             *
             * Every amount in this application is an integer of minor units,
             * and this is the one place a human enters one directly. A field
             * that quietly accepted "50" as fifty cedis would set the minimum
             * donation to five pesewas, and the live conversion under the field
             * is what makes that impossible to do by accident.
             */
            SettingType::Money => [
                TextInput::make($name)
                    ->numeric()
                    ->minValue(0)
                    ->prefix(__('pesewas'))
                    ->live(onBlur: true),
                null,
            ],

            /*
             * `type()` rather than `email()`, `url()` and `tel()`.
             *
             * Those three set the input type AND attach their own validation
             * rule, and a rule attached by Filament cannot be exempted for a
             * value still holding its seeded placeholder — which meant every
             * save of any tab was refused by the eight contact settings nobody
             * had filled in yet. The keyboard a phone shows is what these
             * helpers are wanted for; the checking is `rulesFor()`'s job, and
             * it is the same check either way.
             */
            SettingType::Email => [TextInput::make($name)->type('email'), null],

            SettingType::Url => [TextInput::make($name)->type('url'), null],

            SettingType::Phone => [
                TextInput::make($name)->type('tel'),
                __('A Ghanaian number, e.g. 024 123 4567 or +233 24 123 4567.'),
            ],

            SettingType::Select => [Select::make($name)->options($setting->options ?? []), null],

            SettingType::Media => [
                Select::make($name)->options(fn (): array => $this->libraryImages())->searchable(),
                __('From the media library. Upload it there first.'),
            ],

            default => [TextInput::make($name)->maxLength(1000), null],
        };

        /*
         * The setting's own description wins over the generic one for its type:
         * "Must match the registration certificate character-for-character" is
         * worth more to an editor than "Short text".
         */
        $helper = $setting->description ?? $typeHint;

        if ($type === SettingType::Money) {
            $helper = fn (Get $get): string => trim(
                ($setting->description ? $setting->description.' ' : '')
                .__('= :amount', ['amount' => 'GH₵ '.number_format(((int) $get($name)) / 100, 2)])
            );
        }

        return $field
            ->label($setting->label ?? $setting->key)
            ->helperText($helper)
            // Not a secret — it just never reaches the browser on a public
            // page, and an editor should know which is which before typing the
            // safeguarding address into a field the footer renders.
            ->hint($setting->is_public ? null : __('Not shown publicly'))
            ->hintColor('gray')
            ->disabled((bool) $setting->is_locked)
            ->rules($this->rulesFor($setting));
    }

    /**
     * The declared rule, made tolerant of a seeded placeholder.
     *
     * `Setting::validation` has been populated by the seeder from
     * `SettingType::validationRule()` since Phase 3 and read by nothing, so
     * every rule stored in that column was decoration. This is what makes it
     * real — and it is why a mistyped office email cannot be saved, which is
     * the setting every receipt in the system is sent from.
     *
     * @return array<int, string|Closure>
     */
    private function rulesFor(Setting $setting): array
    {
        $rule = $setting->validation ?? $setting->type->validationRule();

        /*
         * Wrapped in a closure that RETURNS the rule. Filament evaluates a bare
         * closure in a rules array itself, injecting its own utilities, and
         * would try to resolve `$attribute` as one of them; the outer closure
         * is what hands Laravel's validator an untouched rule.
         */
        return ['nullable', fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($rule, $setting): void {
            if (blank($value) || is_bool($value)) {
                return;
            }

            // Still holding its seeded {{TOKEN}}. Refusing the save here would
            // block every other field on the tab behind one nobody has got to.
            if (is_string($value) && preg_match('/^\{\{[A-Z_]+\}\}$/', trim($value)) === 1) {
                return;
            }

            $validator = Validator::make(
                [$setting->key => $value],
                [$setting->key => self::splitRule($rule)],
                [],
                [$setting->key => $setting->label ?? $setting->key],
            );

            if ($validator->fails()) {
                $fail($validator->errors()->first($setting->key));
            }
        }];
    }

    /**
     * A stored rule string, split the way Laravel needs it.
     *
     * `|` is Laravel's rule separator AND alternation inside a regex, and the
     * phone rule is `regex:/^(\+?233|0)[2345][0-9]{8}$/`. Splitting that on `|`
     * hands the validator `regex:/^(\+?233` — "No ending delimiter '/' found",
     * thrown from inside the validator, on any save of a tab holding a phone
     * number. A regex rule is therefore passed whole, which is what Laravel's
     * own documentation says to do with one.
     *
     * @return array<int, string>
     */
    private static function splitRule(string $rule): array
    {
        return str_contains($rule, 'regex:') ? [$rule] : explode('|', $rule);
    }

    /**
     * Images an editor may point a setting at.
     *
     * Unpublishable files are listed and labelled rather than hidden: an image
     * missing its alt text is a five-second fix in the library, and silently
     * omitting it leaves somebody hunting for a logo they know they uploaded.
     *
     * @return array<int|string, string>
     */
    private function libraryImages(): array
    {
        return Media::query()
            ->where('mime_type', 'like', 'image/%')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->mapWithKeys(fn (Media $media): array => [
                $media->getKey() => $media->name.($media->isPublishable() ? '' : ' — '.__('not publishable yet')),
            ])
            ->all();
    }

    // ── Moving values in and out of the form ────────────────────────────────

    private function valueForForm(Setting $setting): mixed
    {
        /*
         * A secret is never sent to the browser. The field stays empty, and an
         * empty field on save means "unchanged" rather than "cleared".
         */
        if ($setting->is_encrypted) {
            return null;
        }

        return match ($setting->type) {
            // The RAW column, not the decoded value: a malformed JSON setting
            // casts to null, and showing an empty box would hide the very thing
            // somebody came to this screen to repair.
            SettingType::Json => $setting->value,
            SettingType::Money => $setting->value === null ? null : (int) $setting->value,
            SettingType::Boolean => (bool) $setting->typedValue(),
            default => $setting->value,
        };
    }

    private function valueFromForm(Setting $setting, mixed $value): mixed
    {
        if ($setting->type === SettingType::Json) {
            if (blank($value)) {
                return null;
            }

            // Decoded so `setTypedValue()` re-encodes it canonically. Handed
            // the string instead, it would store a JSON-encoded JSON string.
            $decoded = json_decode((string) $value, true);

            return json_last_error() === JSON_ERROR_NONE ? $decoded : (string) $value;
        }

        if ($setting->type === SettingType::Boolean) {
            return (bool) $value;
        }

        return blank($value) ? null : $value;
    }
}

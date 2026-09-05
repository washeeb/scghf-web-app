<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\SettingType;
use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Database\Seeder;

/**
 * PHASE-1-BLUEPRINT.md §0's placeholder register, as seed data.
 *
 * Values the foundation has not supplied are seeded as `{{TOKEN}}` rather than
 * left null. That is deliberate: a `{{TOKEN}}` is greppable, reportable by
 * `Settings::unfilled()`, and unmistakable in the admin form — whereas an empty
 * field is indistinguishable from one someone meant to clear.
 *
 * `Settings::get()` treats an unfilled placeholder as absent, so a page renders
 * nothing rather than literally printing "{{PHONE_PRIMARY}}" at a donor.
 *
 * Idempotent, and **never overwrites a value already supplied** — re-running on
 * deploy adds new settings without undoing the client's work.
 */
class SettingsSeeder extends Seeder
{
    /**
     * [group, key, value, type, label, is_public, description, options]
     *
     * `options` is only meaningful for `SettingType::Select` — it is the list
     * the admin screen offers. A Select with no options is a field nobody can
     * fill, which is how `site.default_theme` shipped.
     *
     * @var array<int, array{0:string,1:string,2:?string,3:SettingType,4:string,5:bool,6?:?string,7?:array<string, string>}>
     */
    private const SETTINGS = [
        // ── Identity ─────────────────────────────────────────────────────────
        ['general', 'legal_name', "St. Cecilia's Greater Hope Foundations", SettingType::String, 'Registered legal name', true,
            'Must match the registration certificate character-for-character — it appears on every receipt.'],
        ['general', 'short_name', 'Greater Hope Foundations', SettingType::String, 'Display name', true],
        ['general', 'wordmark', 'GreaterHOPE', SettingType::String, 'Wordmark', true],
        ['general', 'motto', 'Faith. Compassion. Service. Hope.', SettingType::String, 'Motto', true],
        ['general', 'founded_on', '2025-10-25', SettingType::String, 'Date formed', true],
        ['general', 'registered_on', '2026-01-15', SettingType::String, 'Date registered', true],
        ['general', 'founder_name', 'Adam Kingsley Washeeb', SettingType::String, 'Founder', true],
        ['general', 'in_memory_of', 'Mrs Cecilia Anyatuik Adam', SettingType::String, 'In memory of', true],
        // Read by the `core-values` block, which renders nothing until this is
        // filled — an unfilled {{PLACEHOLDER}} counts as absent, so the block
        // is silently omitted rather than rendering an empty list.
        ['general', 'core_values', '{{CORE_VALUES}}', SettingType::Json, 'Core values', true,
            'A JSON list of the values from the foundation profile, e.g. ["Faith","Compassion"].'],
        ['general', 'registration_number', '{{REGISTRATION_NUMBER}}', SettingType::String, 'Registration number', true,
            'Registrar-General / Department of Social Welfare. Required in the footer and on receipts.'],
        ['general', 'registering_authority', '{{REGISTERING_AUTHORITY}}', SettingType::String, 'Registering authority', true],
        ['general', 'tin', '{{TIN}}', SettingType::String, 'Tax Identification Number', false,
            'Required on any receipt claiming tax deductibility.'],
        ['general', 'dpc_registration', '{{DPC_REGISTRATION}}', SettingType::String, 'Data Protection Commission registration', false],

        // ── Contact ──────────────────────────────────────────────────────────
        ['contact', 'address', '{{OFFICE_ADDRESS}}', SettingType::Text, 'Street address', true],
        ['contact', 'gps_address', '{{GPS_ADDRESS}}', SettingType::String, 'Ghana Post GPS', true,
            'e.g. GA-123-4567. More useful than a street address for most Ghanaian visitors.'],
        ['contact', 'city', '{{CITY}}', SettingType::String, 'City', true],
        ['contact', 'district', '{{DISTRICT}}', SettingType::String, 'District', true],
        ['contact', 'region', '{{REGION}}', SettingType::String, 'Region', true],
        ['contact', 'postal_address', '{{POSTAL_ADDRESS}}', SettingType::String, 'P.O. Box', true],
        ['contact', 'phone_primary', '{{PHONE_PRIMARY}}', SettingType::Phone, 'Main phone', true],
        ['contact', 'phone_secondary', '{{PHONE_SECONDARY}}', SettingType::Phone, 'Second phone', true],
        ['contact', 'whatsapp', '{{WHATSAPP_NUMBER}}', SettingType::Phone, 'WhatsApp number', true],
        ['contact', 'office_hours', '{{OFFICE_HOURS}}', SettingType::String, 'Office hours', true],
        ['contact', 'email_general', '{{EMAIL_GENERAL}}', SettingType::Email, 'General enquiries', true],
        ['contact', 'email_donations', '{{EMAIL_DONATIONS}}', SettingType::Email, 'Donations & finance', true],
        ['contact', 'email_volunteer', '{{EMAIL_VOLUNTEER}}', SettingType::Email, 'Volunteering', true],
        ['contact', 'email_shop', '{{EMAIL_SHOP}}', SettingType::Email, 'Shop & orders', true],
        ['contact', 'email_media', '{{EMAIL_MEDIA}}', SettingType::Email, 'Media & partnerships', true],
        ['contact', 'email_safeguarding', '{{EMAIL_SAFEGUARDING}}', SettingType::Email, 'Safeguarding', false,
            'Confidential. Never rendered publicly — safeguarding reports route here and nowhere else.'],

        // ── Social ───────────────────────────────────────────────────────────
        ['social', 'facebook', '{{FACEBOOK_URL}}', SettingType::Url, 'Facebook', true],
        ['social', 'instagram', '{{INSTAGRAM_URL}}', SettingType::Url, 'Instagram', true],
        ['social', 'x', '{{X_URL}}', SettingType::Url, 'X', true],
        ['social', 'linkedin', '{{LINKEDIN_URL}}', SettingType::Url, 'LinkedIn', true],
        ['social', 'youtube', '{{YOUTUBE_URL}}', SettingType::Url, 'YouTube', true],
        ['social', 'tiktok', '{{TIKTOK_URL}}', SettingType::Url, 'TikTok', true],

        // ── Donations ────────────────────────────────────────────────────────
        ['donations', 'currency_code', 'GHS', SettingType::String, 'Currency', true],
        ['donations', 'currency_symbol', 'GH₵', SettingType::String, 'Currency symbol', true],
        ['donations', 'min_amount', '500', SettingType::Money, 'Minimum donation', true,
            'Stored in pesewas. 500 = GH₵ 5.00'],
        ['donations', 'max_amount', '10000000', SettingType::Money, 'Maximum donation', true,
            'Stored in pesewas. 10000000 = GH₵ 100,000.00'],
        ['donations', 'presets', '[5000,10000,25000,50000,100000]', SettingType::Json, 'Preset amounts', true,
            'Pesewas. Shown as chips on the donation form.'],
        ['donations', 'allow_fee_cover', '1', SettingType::Boolean, 'Offer to cover the transaction fee', true],
        ['donations', 'fee_percent', '1.95', SettingType::String, 'Paystack fee %', false,
            'Verify against the signed merchant agreement before go-live.'],
        ['donations', 'fee_cap', '10000', SettingType::Money, 'Paystack fee cap', false,
            'Pesewas. 10000 = GH₵ 100.00'],
        ['donations', 'allow_anonymous', '1', SettingType::Boolean, 'Allow anonymous giving', true],
        ['donations', 'allow_tribute', '1', SettingType::Boolean, 'Allow tribute gifts', true,
            'In memory of / in honour of. The emotional centre of a memorial foundation.'],
        // Answered 2026-09-02: calendar year.
        ['donations', 'financial_year_start_month', '1', SettingType::Integer, 'Financial year starts (month)', false,
            'January. Receipt numbers restart at 1 each calendar year.'],
        ['donations', 'receipt_prefix', 'SCGHF-R', SettingType::String, 'Receipt reference prefix', false],
        ['donations', 'tax_statement_deductible', '{{TAX_STATEMENT_DEDUCTIBLE}}', SettingType::Text, 'Receipt wording — deductible', false,
            'The exact wording the Ghana Revenue Authority requires. Snapshotted onto each receipt at issue.'],
        ['donations', 'tax_statement_non_deductible', '{{TAX_STATEMENT_NON_DEDUCTIBLE}}', SettingType::Text, 'Receipt wording — non-deductible', false],

        // ── Offline giving ───────────────────────────────────────────────────
        ['banking', 'bank_name', '{{BANK_NAME}}', SettingType::String, 'Bank', true],
        ['banking', 'bank_branch', '{{BANK_BRANCH}}', SettingType::String, 'Branch', true],
        ['banking', 'account_name', '{{BANK_ACCOUNT_NAME}}', SettingType::String, 'Account name', true],
        ['banking', 'account_number', '{{BANK_ACCOUNT_NUMBER}}', SettingType::String, 'Account number', true],
        ['banking', 'swift', '{{BANK_SWIFT}}', SettingType::String, 'SWIFT / BIC', true],
        ['banking', 'momo_name', '{{MOMO_MERCHANT_NAME}}', SettingType::String, 'Mobile Money name', true],
        ['banking', 'momo_number', '{{MOMO_MERCHANT_NUMBER}}', SettingType::Phone, 'Mobile Money number', true],

        // ── SEO ──────────────────────────────────────────────────────────────
        ['seo', 'default_title', "St. Cecilia's Greater Hope Foundations", SettingType::String, 'Default page title', true],
        ['seo', 'title_suffix', ' | Greater Hope Foundations', SettingType::String, 'Title suffix', true],
        ['seo', 'default_description', 'A Ghanaian foundation bringing hope, healing, education and care to vulnerable individuals, families and communities.', SettingType::Text, 'Default meta description', true],
        ['seo', 'og_image', null, SettingType::Media, 'Default share image', true],
        ['seo', 'allow_indexing', '0', SettingType::Boolean, 'Allow search engine indexing', false,
            'Set by APP_ENV at deploy time. Production only.'],

        // ── Site behaviour ───────────────────────────────────────────────────
        ['site', 'maintenance_message', 'We will be back shortly.', SettingType::Text, 'Maintenance message', true],
        ['site', 'default_theme', 'system', SettingType::Select, 'Default theme', true,
            'What a first-time visitor sees before they choose. "Match their device" respects the '
            .'setting they already made in their phone.',
            ['light' => 'Light', 'dark' => 'Dark', 'system' => 'Match their device']],
        ['site', 'show_donor_wall', '1', SettingType::Boolean, 'Show the donor wall', true],
        ['site', 'newsletter_double_optin', '1', SettingType::Boolean, 'Require newsletter confirmation', false,
            'Single opt-in is the fastest way to destroy the sending domain reputation. Leave on.'],

        // ── The header ───────────────────────────────────────────────────────
        // Logo variants are separate files, not one file recoloured by CSS: a
        // logo that reads on white rarely reads on the dark palette, and a
        // filter that inverts it produces a colour the brand does not own.
        ['header', 'logo_light', null, SettingType::Media, 'Logo — for light backgrounds', true],
        ['header', 'logo_dark', null, SettingType::Media, 'Logo — for dark backgrounds', true,
            'A separate file. Inverting the light one with CSS produces a colour the brand does not own.'],
        ['header', 'is_sticky', '1', SettingType::Boolean, 'Keep the header visible when scrolling', true,
            'Keeps the Donate button reachable the whole way down a long page.'],
        ['header', 'show_top_bar', '0', SettingType::Boolean, 'Show the top bar', true,
            'A thin strip above the header carrying the phone number and social links.'],

        // ── The announcement bar ─────────────────────────────────────────────
        // Dated on purpose. An announcement with no end date is one somebody
        // has to remember to take down, and nobody ever does — which is how a
        // site ends up advertising last year's fundraiser in March.
        ['announcement', 'message', null, SettingType::Text, 'Announcement', true,
            'Shown across the top of every page. Leave empty for none.'],
        ['announcement', 'link_url', null, SettingType::Url, 'Announcement link', true],
        ['announcement', 'link_label', null, SettingType::String, 'Announcement link text', true],
        ['announcement', 'starts_at', null, SettingType::String, 'Show from', false,
            'YYYY-MM-DD. Leave empty to show immediately.'],
        ['announcement', 'ends_at', null, SettingType::String, 'Hide after', false,
            'YYYY-MM-DD. Leave empty and it stays up until somebody removes it — which is how a '
            .'site ends up advertising last year\'s fundraiser.'],

        // ── The footer ───────────────────────────────────────────────────────
        // Read by the footer since Phase 4 with no row behind them, so the
        // headings could not be changed without a deploy.
        ['site', 'footer_primary_heading', 'Our work', SettingType::String, 'Footer column 1 heading', true],
        ['site', 'footer_support_heading', 'Support us', SettingType::String, 'Footer column 2 heading', true],
        ['site', 'footer_newsletter_heading', 'Stay in touch', SettingType::String, 'Footer newsletter heading', true],
        ['site', 'show_back_to_top', '1', SettingType::Boolean, 'Show a back-to-top link', true],
    ];

    public function run(): void
    {
        $created = 0;
        $skipped = 0;
        $order = 0;

        foreach (self::SETTINGS as $row) {
            [$group, $key, $value, $type, $label, $isPublic] = $row;
            $description = $row[6] ?? null;
            $options = $row[7] ?? null;
            $order++;

            $setting = Setting::firstOrNew(['group' => $group, 'key' => $key]);

            // Metadata is always refreshed — a better label or a corrected
            // description should reach an existing install.
            $setting->fill([
                'type' => $type,
                'label' => $label,
                'description' => $description,
                'is_public' => $isPublic,
                'validation' => $type->validationRule(),
                'options' => $options,
                'sort_order' => $order,
            ]);

            // The VALUE is only ever written on first creation. Re-running this
            // seeder must never overwrite something the foundation has filled in.
            if (! $setting->exists) {
                $setting->value = $value;
                $created++;
            } else {
                $skipped++;
            }

            $setting->save();
        }

        app(Settings::class)->flush();

        $unfilled = app(Settings::class)->unfilled()->count();

        $this->command?->info(sprintf(
            'Settings: %d created, %d already present, %d still awaiting a real value.',
            $created,
            $skipped,
            $unfilled,
        ));
    }
}

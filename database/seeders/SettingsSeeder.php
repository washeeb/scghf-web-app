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

        // ── Consent wording ──────────────────────────────────────────────────
        // The exact sentence somebody ticks is snapshotted onto their record at
        // the moment they tick it, because consent to a notice that has since
        // been rewritten is not evidence of anything. It lives here so it can
        // be corrected by the foundation's own lawyer without a deploy — and so
        // the form and the stored evidence read from the same row.
        ['compliance', 'contact_consent_text',
            'I agree that my details may be stored so that you can reply to me.',
            SettingType::Text, 'Contact form consent wording', true,
            'Shown beside the tick box on the contact form, and stored with each enquiry.'],
        ['compliance', 'donation_consent_text',
            'I agree that my details may be stored in order to process this gift and issue a receipt.',
            SettingType::Text, 'Donation form consent wording', true,
            'Shown beside the tick box on the donation form, and stored with each gift as evidence.'],
        ['compliance', 'newsletter_consent_text',
            'I would like to receive email updates, and I can unsubscribe at any time.',
            SettingType::Text, 'Newsletter consent wording', true,
            'Stored with each subscriber as the evidence of what they agreed to.'],

        // ── Giving, Phase 8 ──────────────────────────────────────────────────
        ['donations', 'abandoned_followup', '0', SettingType::Boolean, 'Follow up an abandoned donation', false,
            'Email a donor who reached the payment page and never finished, once, with a link to pick it up. '
            .'Off by default: a reminder to somebody who decided not to give can read as pressure. '
            .'Only donors who agreed to email are written to.'],
        ['donations', 'allow_mobile_money_direct', '1', SettingType::Boolean, 'Offer direct Mobile Money payment', false,
            'A prompt sent straight to the donor\'s phone, without leaving the site. '
            .'Switch off if the gateway account does not have the charge API enabled.'],
        ['donations', 'allow_public_message', '1', SettingType::Boolean, 'Let donors leave a public message', true,
            'Shown beside their name on the donor wall of the appeal they gave to.'],
        ['donations', 'checkout_mode', 'redirect', SettingType::Select, 'Card checkout', false,
            'Where the donor types their card. "Paystack’s page" sends them to Paystack and back; '
            .'"a window over our page" keeps them here with Paystack open on top. Both are Paystack’s '
            .'own form — card details never touch this site. The window needs JavaScript; without it '
            .'the donor gets a button to Paystack’s page instead.',
            ['redirect' => 'Paystack’s page', 'popup' => 'A window over our page']],

        // ── Currency (Wave 2): the approximate figure beside a cedi amount ────
        ['currency', 'display_default', '', SettingType::Select, 'Second currency shown by default', true,
            'An approximate figure beside every public cedi amount, for donors who think in another currency. '
            .'Visitors can choose another in the footer. Gifts are always taken in cedis.',
            ['' => 'None — cedis only', 'USD' => 'US dollars', 'GBP' => 'Pounds sterling', 'EUR' => 'Euros']],
        ['currency', 'rate_source', 'api', SettingType::Select, 'Where the rates come from', false,
            'The feed is fetched every morning at 05:30 and needs no key. Choose manual to type the Bank of Ghana rate yourself.',
            ['api' => 'Daily feed (automatic)', 'manual' => 'The rates typed below']],
        ['currency', 'rate_usd', null, SettingType::String, 'Cedis per 1 US dollar', false, 'Used when the source is manual, or when the feed has never answered.'],
        ['currency', 'rate_gbp', null, SettingType::String, 'Cedis per 1 pound', false],
        ['currency', 'rate_eur', null, SettingType::String, 'Cedis per 1 euro', false],

        // ── Accounting (Wave 2): the chart of accounts the journal export writes to ──
        ['accounting', 'package', 'generic', SettingType::Select, 'Accounting package', false,
            'Sets the column headings of the monthly journal export so it imports without mapping.',
            ['generic' => 'Generic (any spreadsheet)', 'quickbooks' => 'QuickBooks', 'xero' => 'Xero', 'zoho' => 'Zoho Books']],
        ['accounting', 'clearing_code', '1150', SettingType::String, 'Paystack clearing — code', false,
            'Where a gateway gift or order sits until Paystack settles it to the bank. Its balance after import is what Paystack owes.'],
        ['accounting', 'clearing_name', 'Paystack clearing', SettingType::String, 'Paystack clearing — name', false],
        ['accounting', 'bank_code', '1100', SettingType::String, 'Bank account — code', false,
            'Offline gifts by transfer or cheque, and payouts paid from the bank.'],
        ['accounting', 'bank_name', 'Bank account', SettingType::String, 'Bank account — name', false],
        ['accounting', 'cash_code', '1000', SettingType::String, 'Cash — code', false,
            'Cash gifts recorded offline, and payouts paid in cash.'],
        ['accounting', 'cash_name', 'Cash', SettingType::String, 'Cash — name', false],
        ['accounting', 'momo_code', '1120', SettingType::String, 'Mobile Money float — code', false,
            'Offline MoMo gifts, and payouts paid by MoMo.'],
        ['accounting', 'momo_name', 'Mobile Money float', SettingType::String, 'Mobile Money float — name', false],
        ['accounting', 'donations_code', '4000', SettingType::String, 'Donations — code', false,
            'Every gift, gross, with the appeal in the fund column.'],
        ['accounting', 'donations_name', 'Donations', SettingType::String, 'Donations — name', false],
        ['accounting', 'shop_sales_code', '4100', SettingType::String, 'Shop sales — code', false,
            'Orders, less shipping.'],
        ['accounting', 'shop_sales_name', 'Shop sales', SettingType::String, 'Shop sales — name', false],
        ['accounting', 'shipping_code', '4110', SettingType::String, 'Shipping recovered — code', false,
            'The shipping charged on orders.'],
        ['accounting', 'shipping_name', 'Shipping recovered', SettingType::String, 'Shipping recovered — name', false],
        ['accounting', 'fees_code', '6100', SettingType::String, 'Payment processing fees — code', false,
            'Paystack’s fee on each gateway gift and order.'],
        ['accounting', 'fees_name', 'Payment processing fees', SettingType::String, 'Payment processing fees — name', false],
        ['accounting', 'programme_code', '5000', SettingType::String, 'Programme expenses — code', false,
            'Payouts in a category with no account of its own below.'],
        ['accounting', 'programme_name', 'Programme expenses', SettingType::String, 'Programme expenses — name', false],
        ['accounting', 'programme_school_fees_code', '5010', SettingType::String, 'Programme — school fees — code', false],
        ['accounting', 'programme_school_fees_name', 'Programme — school fees', SettingType::String, 'Programme — school fees — name', false],
        ['accounting', 'programme_medical_code', '5020', SettingType::String, 'Programme — medical — code', false],
        ['accounting', 'programme_medical_name', 'Programme — medical', SettingType::String, 'Programme — medical — name', false],
        ['accounting', 'programme_food_code', '5030', SettingType::String, 'Programme — food — code', false],
        ['accounting', 'programme_food_name', 'Programme — food', SettingType::String, 'Programme — food — name', false],
        ['accounting', 'programme_rent_code', '5040', SettingType::String, 'Programme — rent — code', false],
        ['accounting', 'programme_rent_name', 'Programme — rent', SettingType::String, 'Programme — rent — name', false],
        ['accounting', 'programme_stipend_code', '5050', SettingType::String, 'Programme — stipends — code', false],
        ['accounting', 'programme_stipend_name', 'Programme — stipends', SettingType::String, 'Programme — stipends — name', false],
        ['accounting', 'programme_supplier_code', '5060', SettingType::String, 'Programme — suppliers — code', false],
        ['accounting', 'programme_supplier_name', 'Programme — suppliers', SettingType::String, 'Programme — suppliers — name', false],
        ['accounting', 'programme_transport_code', '5070', SettingType::String, 'Programme — transport — code', false],
        ['accounting', 'programme_transport_name', 'Programme — transport', SettingType::String, 'Programme — transport — name', false],
        ['accounting', 'programme_equipment_code', '5080', SettingType::String, 'Programme — equipment — code', false],
        ['accounting', 'programme_equipment_name', 'Programme — equipment', SettingType::String, 'Programme — equipment — name', false],

        // ── The shop ─────────────────────────────────────────────────────────
        // A threshold rather than a hardcoded number, because "low" depends on
        // how fast a thing sells. Ten tote bags is plenty; ten of a bracelet
        // that shifts thirty a week is a stockout on Thursday.
        ['shop', 'low_stock_threshold', '5', SettingType::Integer, 'Warn when stock falls to', false,
            'The dashboard flags any item with this many or fewer left to sell.'],
        ['shop', 'offer_gift_at_checkout', '1', SettingType::Boolean, 'Offer a gift at checkout', true,
            'Chips at the last step: round the basket up, or add GH₵ 5, 10 or 20. The gift goes to the '
            .'General Fund and is receipted separately from the goods.'],
        ['shop', 'abandoned_checkout_reminder', '0', SettingType::Boolean, 'Remind an abandoned checkout', false,
            'Email a customer who reached the payment page and did not finish, once, an hour or more later, '
            .'with a link back to their basket. Off by default: a reminder to somebody who decided not to '
            .'buy reads as pressure. Only customers who agreed to email are written to.'],
        ['shop', 'intro', 'Every purchase funds our work.', SettingType::String, 'Shop introduction', true,
            'The line under the shop heading, and its description in search results.'],
        ['shop', 'proceeds_statement',
            'The whole of what the shop makes, after the cost of the goods and delivery, goes to the work of the foundation.',
            SettingType::Text, 'Where the money goes', true,
            'Shown at the top of the shop. Say plainly what a purchase funds — it is the reason to buy here rather than anywhere else.'],
        ['compliance', 'event_consent_text',
            'I agree that my details may be held in order to run this event.',
            SettingType::Text, 'Event registration wording', true,
            'Shown beside the tick box when somebody registers for an event, and stored with the registration as evidence.'],
        ['compliance', 'volunteer_declaration_text',
            'I have read the safeguarding policy, I have disclosed any conviction, caution or investigation that could be relevant to working with children or vulnerable adults, and the information I have given is true.',
            SettingType::Text, 'Volunteer declaration', true,
            'What an applicant agrees to. Stored verbatim with each application — it is what they are later held to.'],
        ['compliance', 'shop_consent_text',
            'I understand that my details are held in order to fulfil this order.',
            SettingType::Text, 'Checkout agreement wording', true,
            'Shown beside the tick box at checkout.'],

        // ── Events ───────────────────────────────────────────────────────────
        ['events', 'intro', 'Come and be part of the work.', SettingType::String, 'Events page introduction', true,
            'The line under the events heading, and its description in search results.'],

        // ── Volunteering ─────────────────────────────────────────────────────
        ['volunteering', 'intro', 'Give your time.', SettingType::String, 'Volunteer page introduction', true],
        ['volunteering', 'safeguarding_statement',
            'We work with children and vulnerable adults. Every volunteer completes safeguarding checks before starting, and we would rather explain that up front than surprise you with it later.',
            SettingType::Text, 'Safeguarding statement', true,
            'Shown at the top of the volunteer page.'],

        // ── Communications, Phase 10 ─────────────────────────────────────────
        ['communications', 'sms_driver', '', SettingType::Select, 'SMS provider', false,
            'Which gateway sends texts. Empty = the SMS_DRIVER in .env. The keys for each provider live '
            .'in .env; choose one whose keys are set, or "log", which records every text and sends none.',
            ['' => 'As configured on the server', 'log' => 'Log only (send nothing)', 'mnotify' => 'mNotify', 'arkesel' => 'Arkesel', 'hubtel' => 'Hubtel', 'twilio' => 'Twilio (fallback)']],
        ['communications', 'alert_email', '', SettingType::Email, 'Alerts go to', false,
            'Low SMS credit, the weekly summary, a new large gift. Empty = the general contact email.'],
        ['communications', 'new_donation_alert_minor', '0', SettingType::Money, 'Tell me about a gift of at least', false,
            'Pesewas. 0 = never. 50000 = GH₵ 500.00: an email to the alerts address for every completed gift of that size or more.'],
        ['communications', 'weekly_summary', '1', SettingType::Boolean, 'Weekly summary email', false,
            'Monday 07:00 to the alerts address: last week’s giving, shop, subscribers, messages and anything needing attention.'],

        // ── SEO ──────────────────────────────────────────────────────────────
        ['seo', 'default_title', "St. Cecilia's Greater Hope Foundations", SettingType::String, 'Default page title', true],
        ['seo', 'title_suffix', ' | Greater Hope Foundations', SettingType::String, 'Title suffix', true],
        ['seo', 'default_description', 'A Ghanaian foundation bringing hope, healing, education and care to vulnerable individuals, families and communities.', SettingType::Text, 'Default meta description', true],
        ['seo', 'og_image', null, SettingType::Media, 'Default share image', true],
        ['seo', 'robots_extra', '', SettingType::Text, 'Extra robots.txt lines', false,
            'One directive per line, added to robots.txt when indexing is on — "Disallow: /old-section/", "Crawl-delay: 5". The admin, account and search paths are already excluded.'],
        ['seo', 'allow_indexing', '0', SettingType::Boolean, 'Allow search engine indexing', false,
            'Set by APP_ENV at deploy time. Production only.'],

        // ── Site behaviour ───────────────────────────────────────────────────
        ['site', 'maintenance_message', 'We will be back shortly.', SettingType::Text, 'Maintenance message', true],
        ['site', 'default_theme', 'system', SettingType::Select, 'Default theme', true,
            'What a first-time visitor sees before they choose. "Match their device" respects the '
            .'setting they already made in their phone.',
            ['light' => 'Light', 'dark' => 'Dark', 'vibrant' => 'Vibrant', 'system' => 'Match their device']],
        ['site', 'vibrant_theme_label', 'Vibrant', SettingType::String, 'Name of the third theme', true,
            'What the theme control calls the colourful palette. Its colours are under Appearance → Theme colours.'],
        ['site', 'nav_open_on_hover', '1', SettingType::Boolean, 'Open menus on hover', true,
            'With a mouse, a top-level menu opens when the pointer rests on it and closes when it leaves. '
            .'Touch and keyboard always open on tap or Enter, whatever this says.'],
        ['site', 'footer_policy_groups', '[{"label":"Legal","slugs":["privacy-policy","terms","cookie-policy","shipping-and-delivery"]},{"label":"Giving","slugs":["donation-policy","refund-policy"]},{"label":"Conduct","slugs":["safeguarding","accessibility","whistleblowing","anti-fraud"]}]', SettingType::Json, 'Footer policy groups', true,
            'How the footer groups the policy links: a list of {label, slugs}. A page not listed goes under "Policies".'],
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
        ['header', 'logo_icon', null, SettingType::Media, 'Logo — square icon', true,
            'The mark on its own, square. Used for the app icon on a phone\'s home screen and wherever a wide logo would not fit.'],
        ['header', 'is_sticky', '1', SettingType::Boolean, 'Keep the header visible when scrolling', true,
            'Keeps the Donate button reachable the whole way down a long page.'],
        ['header', 'show_top_bar', '1', SettingType::Boolean, 'Show the top bar', true,
            'A thin strip above the header carrying the address, phone number and email. It only appears once at least one of those is filled in under Contact.'],
        // The header's own words. Everything a visitor reads in the header that
        // is not a menu item — the account control, the icon labels a screen
        // reader hears, the theme names — is a setting, so none of it needs a
        // deploy to change.
        ['header', 'account_label', 'Account', SettingType::String, 'Account control', true,
            'What the signed-in control in the header says. The menu under it lists the account pages and Sign out.'],
        ['header', 'sign_in_label', 'Sign in', SettingType::String, 'Sign-in link', true],
        ['header', 'sign_out_label', 'Sign out', SettingType::String, 'Sign-out item', true],
        ['header', 'signed_in_as_label', 'Signed in as :name', SettingType::String, 'Signed-in line', true,
            'The first line of the account menu. :name is replaced by the person\'s name.'],
        ['header', 'search_label', 'Search', SettingType::String, 'Search icon label', true,
            'Read out by screen readers for the magnifier icon.'],
        ['header', 'menu_label', 'Menu', SettingType::String, 'Phone menu button label', true],
        ['header', 'theme_label', 'Colour theme', SettingType::String, 'Theme icon label', true,
            'Read out by screen readers for the theme icon, and shown when the pointer rests on it.'],
        ['header', 'theme_light_label', 'Light', SettingType::String, 'Theme name — light', true],
        ['header', 'theme_dark_label', 'Dark', SettingType::String, 'Theme name — dark', true],
        ['header', 'theme_system_label', 'Match my device', SettingType::String, 'Theme name — follow the device', true],
        ['header', 'account_menu', '[{"route":"account.dashboard","label":"Overview"},{"route":"account.impact","label":"Your impact"},{"route":"account.receipts","label":"Receipts"},{"route":"account.giving","label":"Regular giving"},{"route":"account.profile","label":"Profile"},{"route":"account.security","label":"Security"}]', SettingType::Json, 'Account menu items', true,
            'The pages under the account control, in order: a list of {route, label}. Routes: account.dashboard, account.impact, account.receipts, account.giving, account.profile, account.security, account.privacy. Sign out is always last.'],

        // ── The footer ───────────────────────────────────────────────────────
        // Read by the footer since Phase 4 with no row behind them, so the
        // headings could not be changed without a deploy.
        ['site', 'footer_primary_heading', 'Our work', SettingType::String, 'Footer column 1 heading', true],
        ['site', 'footer_support_heading', 'Support us', SettingType::String, 'Footer column 2 heading', true],
        ['site', 'footer_newsletter_heading', 'Stay in touch', SettingType::String, 'Footer newsletter heading', true],
        ['site', 'footer_join_label', 'Join', SettingType::String, 'Footer newsletter button', true],
        ['site', 'footer_email_placeholder', 'you@example.com', SettingType::String, 'Footer newsletter placeholder', true],
        ['site', 'footer_social_heading', '', SettingType::String, 'Footer social links heading', true,
            'A small heading above the social links, if you want one. Empty shows none.'],
        ['site', 'footer_gps_label', 'GPS', SettingType::String, 'Label before the Ghana Post address', true],
        ['site', 'footer_registration_label', 'Registration', SettingType::String, 'Label before the registration number', true],
        ['site', 'footer_tin_label', 'TIN', SettingType::String, 'Label before the TIN', true],
        ['site', 'footer_policies_label', 'Policies', SettingType::String, 'Footer policy group — the rest', true,
            'The heading for any policy link that is not in one of the groups below.'],
        ['site', 'footer_cookie_label', 'Cookie preferences', SettingType::String, 'Cookie preferences link', true],
        ['site', 'show_back_to_top', '1', SettingType::Boolean, 'Show a back-to-top link', true],
        ['site', 'footer_back_to_top_label', 'Back to top', SettingType::String, 'Back-to-top link', true],
        ['site', 'footer_install_label', 'Add to your phone', SettingType::String, 'Add-to-phone link', true,
            'Shown only when the visitor\'s browser offers to install the site.'],
        ['site', 'footer_currency_label', 'Amounts also in', SettingType::String, 'Currency picker label', true],
        ['site', 'footer_currency_apply_label', 'Apply', SettingType::String, 'Currency picker button', true],
        ['site', 'footer_currency_note', 'Approximate; gifts are taken in cedis.', SettingType::String, 'Currency picker note', true],
        ['site', 'footer_copyright_prefix', '©', SettingType::String, 'Before the year in the copyright line', true,
            'The © sign by default. Some foundations prefer "Copyright ©" or nothing.'],

        // ── Analytics (Phase 13) ─────────────────────────────────────────────
        // None by default. Whichever is chosen loads only after the visitor
        // allows the Analytics category in the cookie notice; until then the
        // script is inert text. The database dashboard needs none of this.
        ['analytics', 'provider', 'none', SettingType::Select, 'Analytics provider', false,
            'Plausible and Umami are privacy-first and need no cookie; GA4 runs in consent mode. All three load only after consent. See docs/PHASE-13-SEO-AND-CONTENT.md for which to pick.',
            ['none' => 'None (the built-in dashboard only)', 'plausible' => 'Plausible', 'umami' => 'Umami', 'ga4' => 'Google Analytics 4']],
        ['analytics', 'site_id', '', SettingType::String, 'Site / measurement ID', false,
            'Plausible: the domain (greaterhopefoundations.org). Umami: the website ID from the dashboard. GA4: the measurement ID (G-XXXXXXX).'],
        ['analytics', 'script_url', '', SettingType::Url, 'Script URL', false,
            'Plausible: https://plausible.io/js/script.js (or your self-hosted one). Umami cloud: https://cloud.umami.is/script.js. GA4: leave empty.'],

        // ── Cookie consent (Phase 12) ────────────────────────────────────────
        // The site sets essential cookies only today. The banner exists so
        // that is SAID, so a visitor can see and change what they allow, and
        // so any analytics or embed added later is gated before it loads.
        ['site', 'cookie_banner_enabled', '1', SettingType::Boolean, 'Show the cookie notice', true,
            'A small notice on the first visit with a link to the cookie policy and a preferences panel. Required by Act 843 and GDPR the moment any non-essential cookie exists.'],
        ['site', 'cookie_banner_text', 'We use cookies that are needed for the site to work — signing in, giving, your theme choice. Nothing else unless you say so.',
            SettingType::Text, 'Cookie notice text', true],

        // ── The newsletter popup (Phase 11) ──────────────────────────────────
        // Off until somebody turns it on. When on: exit-intent on a laptop,
        // after a delay on a phone, at most once per `frequency_days`, never
        // to somebody who has subscribed, never on a page where money or a
        // password is being entered.
        ['site', 'newsletter_popup_enabled', '0', SettingType::Boolean, 'Show the newsletter popup', true,
            'An invitation to subscribe that appears once as a visitor goes to leave. Off by default; it is a judgement about the foundation’s tone as much as a setting.'],
        ['site', 'newsletter_popup_heading', 'Before you go', SettingType::String, 'Popup heading', true],
        ['site', 'newsletter_popup_body', 'Once a month, one email: what your support did, and what comes next. No more than that, and you can stop any time.',
            SettingType::Text, 'Popup text', true],
        ['site', 'newsletter_popup_frequency_days', '30', SettingType::Integer, 'Days before the popup may show again', true,
            'Counted from when it was closed. It never shows twice in one visit.'],
        ['site', 'newsletter_popup_delay_seconds', '25', SettingType::Integer, 'Seconds on a phone before it may show', true,
            'A phone has no cursor to leave the page with, so the popup waits this long and for the visitor to have scrolled half the page. On a laptop it waits for the cursor to head for the tabs.'],
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

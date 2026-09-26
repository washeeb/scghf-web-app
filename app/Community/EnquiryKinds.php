<?php

declare(strict_types=1);

namespace App\Community;

use InvalidArgumentException;

/**
 * The structured enquiries a page can carry: partner with us, corporate
 * giving, an in-kind offer, fundraising for us.
 *
 * ── One inbox, not four ─────────────────────────────────────────────────────
 *
 * Each of these lands in `contact_messages`, routed to a department, with the
 * structured answers written into the message body under headings. A
 * separate table per enquiry type would be four screens staff have to
 * remember to check; the contact inbox is the one they already do, with the
 * SLA reminders and assignment that already exist there.
 *
 * ── The fields are the questions a staff member would ask on the phone ──────
 *
 * "What are you offering, how much of it, what condition, where is it" is the
 * in-kind conversation. Asking it on the form saves the call and means the
 * message that arrives is actionable rather than "I have some things".
 *
 * ── Kinds are a closed list ─────────────────────────────────────────────────
 *
 * The block picks one from this list and the route parameter is validated
 * against it. There is no free-text kind, because a kind decides which
 * department reads the message.
 */
final class EnquiryKinds
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            'partner' => [
                'label' => __('Partner with us'),
                'department' => 'media',
                'subject' => __('Partnership enquiry'),
                'intro' => __('For churches, schools, clinics, NGOs and institutions that want to work alongside us.'),
                'fields' => [
                    'organisation' => ['label' => __('Organisation'), 'type' => 'text', 'required' => true],
                    'organisation_type' => ['label' => __('What kind of organisation'), 'type' => 'select', 'options' => [
                        'church' => __('Church or ministry'), 'school' => __('School or college'), 'health' => __('Clinic or hospital'),
                        'ngo' => __('NGO or community group'), 'company' => __('Company'), 'government' => __('Government or traditional authority'), 'other' => __('Other'),
                    ]],
                    'proposal' => ['label' => __('What do you have in mind?'), 'type' => 'textarea', 'required' => true,
                        'hint' => __('A joint project, a referral arrangement, use of a facility — whatever it is, say it plainly.')],
                ],
            ],

            'corporate' => [
                'label' => __('Corporate giving'),
                'department' => 'donations',
                'subject' => __('Corporate giving enquiry'),
                'intro' => __('For companies that want to give, sponsor, match staff giving or run a payroll scheme.'),
                'fields' => [
                    'organisation' => ['label' => __('Company'), 'type' => 'text', 'required' => true],
                    'role' => ['label' => __('Your role'), 'type' => 'text'],
                    'interest' => ['label' => __('What interests you'), 'type' => 'select', 'required' => true, 'options' => [
                        'sponsorship' => __('Sponsoring a project or an event'), 'matched' => __('Matching what our staff give'),
                        'payroll' => __('Payroll giving'), 'donation' => __('A one-off corporate gift'), 'csr' => __('A longer CSR partnership'), 'other' => __('Something else'),
                    ]],
                    'proposal' => ['label' => __('Tell us more'), 'type' => 'textarea', 'required' => true],
                ],
            ],

            'in-kind' => [
                'label' => __('Donate goods'),
                'department' => 'donations',
                'subject' => __('In-kind offer'),
                'intro' => __('Food, clothing, books, medical supplies, equipment. Tell us what it is and we will tell you whether we can use it and how to get it to us.'),
                'fields' => [
                    'goods' => ['label' => __('What are you offering?'), 'type' => 'textarea', 'required' => true,
                        'hint' => __('Be specific: "40 exercise books and 20 school bags", not "school things".')],
                    'quantity' => ['label' => __('How much, roughly'), 'type' => 'text'],
                    'condition' => ['label' => __('Condition'), 'type' => 'select', 'options' => [
                        'new' => __('New'), 'good' => __('Used, good condition'), 'fair' => __('Used, needs some work'),
                    ]],
                    'location' => ['label' => __('Where is it now?'), 'type' => 'text', 'required' => true, 'hint' => __('Town and region.')],
                    'transport' => ['label' => __('Getting it to us'), 'type' => 'select', 'options' => [
                        'deliver' => __('I can deliver it'), 'collect' => __('It would need collecting'),
                    ]],
                ],
            ],

            'fundraise' => [
                'label' => __('Fundraise for us'),
                'department' => 'donations',
                'subject' => __('Community fundraising'),
                'intro' => __('A sponsored walk, a harvest collection, a birthday appeal, a sale at work. Tell us what you are planning and we will help with materials, a reference for the money, and a thank-you when it lands.'),
                'fields' => [
                    'plan' => ['label' => __('What are you planning?'), 'type' => 'textarea', 'required' => true],
                    'target' => ['label' => __('How much do you hope to raise?'), 'type' => 'text', 'hint' => __('A rough figure in cedis is fine.')],
                    'when' => ['label' => __('When'), 'type' => 'text'],
                    'cause' => ['label' => __('For a particular appeal?'), 'type' => 'text', 'hint' => __('Optional. Leave empty to fund our work generally.')],
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function get(string $kind): array
    {
        $kinds = self::all();

        if (! array_key_exists($kind, $kinds)) {
            throw new InvalidArgumentException("Unknown enquiry kind [{$kind}].");
        }

        return $kinds[$kind];
    }

    public static function has(string $kind): bool
    {
        return array_key_exists($kind, self::all());
    }

    /** @return array<string, string> kind => label, for the block's picker */
    public static function options(): array
    {
        return array_map(fn (array $kind): string => (string) $kind['label'], self::all());
    }
}

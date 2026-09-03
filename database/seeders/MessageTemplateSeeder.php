<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use Illuminate\Database\Seeder;

/**
 * The messages the application sends, with default wording.
 *
 * ── What is seeded and what is not ──────────────────────────────────────────
 *
 * Structure is seeded on every deploy: the key, the category, the declared
 * variables, whether the template is locked. WORDING is seeded only on first
 * creation, and never overwritten — once the Foundation has rewritten a
 * covering letter in its own voice, a deploy must not put ours back.
 *
 * ── What is deliberately NOT here ───────────────────────────────────────────
 *
 * The GRA acknowledgement paragraphs. They are composed by
 * App\Support\Acknowledgement from config/compliance.php and arrive as the
 * single `{{acknowledgement}}` variable, so that:
 *
 *   - nobody can reword a statement made under s.97 of Act 896 by editing an
 *     email in a browser, and
 *   - the s.97 approval paragraph cannot appear before the Foundation actually
 *     holds its Notice of Approval, because the builder refuses to include it.
 *
 * ── Note on the SMS wording ─────────────────────────────────────────────────
 *
 * Every SMS body below says "GHS", not "GH₵", and that is not an oversight.
 * CLAUDE.md mandates "GH₵ 1,234.56" for display, and ₵ is not in the GSM-7
 * alphabet — one cedi sign drops the segment size from 160 characters to 70 and
 * roughly triples the cost of every message sent from that template. Correct
 * for the website, expensive for SMS. SmsTemplate refuses the save if it pushes
 * a template over its segment budget, so this is enforced rather than trusted.
 */
class MessageTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->emailTemplates() as $definition) {
            $this->seedEmail($definition);
        }

        foreach ($this->smsTemplates() as $definition) {
            $this->seedSms($definition);
        }
    }

    /** @param array<string, mixed> $definition */
    private function seedEmail(array $definition): void
    {
        $template = EmailTemplate::withTrashed()->firstOrNew(['key' => $definition['key']]);

        // Structure, refreshed on every deploy.
        $template->forceFill([
            'key' => $definition['key'],
            'name' => $definition['name'],
            'description' => $definition['description'],
            'category' => $definition['category'],
            'available_variables' => $definition['variables'],
            'required_variables' => $definition['required'] ?? [],
            'is_locked' => $definition['locked'] ?? false,
            'deleted_at' => null,
        ]);

        // Wording, first time only.
        if (! $template->exists) {
            $template->forceFill([
                'subject' => $definition['subject'],
                'preheader' => $definition['preheader'] ?? null,
                'body_html' => $definition['html'],
                'body_text' => $definition['text'],
                'is_active' => true,
            ]);
        }

        $template->save();
    }

    /** @param array<string, mixed> $definition */
    private function seedSms(array $definition): void
    {
        $template = SmsTemplate::withTrashed()->firstOrNew(['key' => $definition['key']]);

        $template->forceFill([
            'key' => $definition['key'],
            'name' => $definition['name'],
            'description' => $definition['description'],
            'category' => $definition['category'],
            'available_variables' => $definition['variables'],
            'required_variables' => $definition['required'] ?? [],
            'is_locked' => $definition['locked'] ?? false,
            'max_segments' => $definition['max_segments'] ?? 2,
            'deleted_at' => null,
        ]);

        if (! $template->exists) {
            $template->forceFill(['body' => $definition['body'], 'is_active' => true]);
        }

        $template->save();
    }

    /** @return array<int, array<string, mixed>> */
    private function emailTemplates(): array
    {
        return [
            [
                'key' => 'donation.receipt',
                'name' => 'Donation acknowledgement',
                'description' => 'Sent when a donation completes. The acknowledgement wording '
                    .'itself comes from the compliance policy and cannot be edited here.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => [
                    'donor_name', 'amount', 'amount_in_words', 'reference', 'receipt_number',
                    'cause_name', 'donation_date', 'acknowledgement', 'receipt_url',
                ],
                'required' => ['donor_name', 'amount', 'reference', 'acknowledgement'],
                'subject' => 'Your donation to {{site_name}} — {{reference}}',
                'preheader' => 'Thank you. Your acknowledgement is attached.',
                'html' => <<<'HTML'
                    <p>Dear {{donor_name}},</p>
                    <p>Thank you for your gift of <strong>{{amount}}</strong> towards
                    {{cause_name}}, received on {{donation_date}}.</p>
                    <p>Your reference is <strong>{{reference}}</strong>. Please quote it in any
                    correspondence about this gift.</p>
                    {{acknowledgement}}
                    <p>With gratitude,<br>{{organisation_legal_name}}</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{donor_name}},

                    Thank you for your gift of {{amount}} towards {{cause_name}}, received on
                    {{donation_date}}.

                    Your reference is {{reference}}.

                    {{acknowledgement}}

                    With gratitude,
                    {{organisation_legal_name}}
                    TEXT,
            ],
            [
                'key' => 'donation.failed',
                'name' => 'Donation did not complete',
                'description' => 'Sent when a payment fails, so the donor knows their gift did '
                    .'not go through and their money was not taken.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['donor_name', 'amount', 'reference', 'retry_url'],
                'required' => ['donor_name'],
                'subject' => 'Your donation to {{site_name}} did not complete',
                'html' => <<<'HTML'
                    <p>Dear {{donor_name}},</p>
                    <p>Your gift of {{amount}} did not complete, and <strong>no money has been
                    taken</strong>.</p>
                    <p>If you would like to try again, you can do so here: {{retry_url}}</p>
                    <p>If you think this is a mistake, please contact us on {{contact_phone}} or
                    at {{contact_email}} quoting {{reference}}.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{donor_name}},

                    Your gift of {{amount}} did not complete, and no money has been taken.

                    To try again: {{retry_url}}

                    If you think this is a mistake, contact us on {{contact_phone}} or at
                    {{contact_email}}, quoting {{reference}}.
                    TEXT,
            ],
            [
                'key' => 'order.confirmation',
                'name' => 'Shop order confirmation',
                'description' => 'Sent when a shop order is paid. A purchase is not a gift, so '
                    .'this must never carry donation acknowledgement wording.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => [
                    'customer_name', 'order_reference', 'order_total', 'order_items',
                    'delivery_address', 'invoice_number',
                ],
                'required' => ['customer_name', 'order_reference', 'order_total'],
                'subject' => 'Your order {{order_reference}}',
                'html' => <<<'HTML'
                    <p>Dear {{customer_name}},</p>
                    <p>Thank you for your order. We have received your payment of
                    <strong>{{order_total}}</strong>.</p>
                    <p>Order reference: <strong>{{order_reference}}</strong></p>
                    {{order_items}}
                    <p>Delivery to: {{delivery_address}}</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{customer_name}},

                    Thank you for your order. We have received your payment of {{order_total}}.

                    Order reference: {{order_reference}}

                    {{order_items}}

                    Delivery to: {{delivery_address}}
                    TEXT,
            ],
            [
                'key' => 'order.shipped',
                'name' => 'Order dispatched',
                'description' => 'Sent when an order is handed to the courier.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['customer_name', 'order_reference', 'courier', 'tracking_reference'],
                'required' => ['customer_name', 'order_reference'],
                'subject' => 'Your order {{order_reference}} is on its way',
                'html' => <<<'HTML'
                    <p>Dear {{customer_name}},</p>
                    <p>Your order {{order_reference}} has been dispatched with {{courier}}.</p>
                    <p>Tracking reference: {{tracking_reference}}</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{customer_name}},

                    Your order {{order_reference}} has been dispatched with {{courier}}.

                    Tracking reference: {{tracking_reference}}
                    TEXT,
            ],
            [
                'key' => 'newsletter.confirm',
                'name' => 'Newsletter — confirm your subscription',
                'description' => 'The double opt-in email. Without it, anybody can subscribe '
                    .'somebody else, and the resulting complaints stop receipts being delivered.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['name', 'confirm_url', 'newsletter_name'],
                'required' => ['confirm_url'],
                'subject' => 'Please confirm your subscription to {{site_name}}',
                'html' => <<<'HTML'
                    <p>Hello {{name}},</p>
                    <p>Please confirm that you would like to receive {{newsletter_name}} from
                    {{site_name}}:</p>
                    <p><a href="{{confirm_url}}">Confirm my subscription</a></p>
                    <p>If you did not ask for this, ignore this message and nothing further will
                    be sent.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Hello {{name}},

                    Please confirm that you would like to receive {{newsletter_name}} from
                    {{site_name}}:

                    {{confirm_url}}

                    If you did not ask for this, ignore this message and nothing further will be
                    sent.
                    TEXT,
            ],
            [
                'key' => 'newsletter.campaign',
                'name' => 'Newsletter campaign wrapper',
                'description' => 'The shell every campaign is rendered into. The campaign supplies '
                    .'{{content}}; this template supplies the framing that is the same every time.',
                'category' => EmailTemplate::CATEGORY_MARKETING,
                'locked' => true,
                'variables' => ['subject', 'content', 'content_text', 'subscriber_name', 'unsubscribe_url'],
                'required' => ['subject', 'content', 'unsubscribe_url'],
                'subject' => '{{subject}}',
                'html' => '{{content}}',
                'text' => "{{content_text}}\n",
            ],
            [
                'key' => 'volunteer.application_received',
                'name' => 'Volunteer application received',
                'description' => 'Acknowledges an application and sets the expectation that '
                    .'safeguarding checks come before any placement.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['name', 'opportunity_title', 'reference'],
                'required' => ['name'],
                'subject' => 'We have received your volunteer application',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Thank you for applying to volunteer with us{{opportunity_title}}.</p>
                    <p>Because we work with children and vulnerable adults, every volunteer
                    completes safeguarding checks before starting. We will be in touch about
                    those.</p>
                    <p>Your reference is {{reference}}.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Thank you for applying to volunteer with us.

                    Because we work with children and vulnerable adults, every volunteer
                    completes safeguarding checks before starting. We will be in touch about
                    those.

                    Your reference is {{reference}}.
                    TEXT,
            ],
            [
                'key' => 'event.registration_confirmed',
                'name' => 'Event registration confirmed',
                'description' => 'Sent when somebody registers for an event.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['name', 'event_title', 'event_date', 'venue', 'reference'],
                'required' => ['name', 'event_title', 'event_date'],
                'subject' => 'You are registered for {{event_title}}',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>You are registered for <strong>{{event_title}}</strong> on
                    {{event_date}}{{venue}}.</p>
                    <p>Your reference is {{reference}}. If you can no longer come, please let us
                    know so we can offer your place to somebody else.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    You are registered for {{event_title}} on {{event_date}}.

                    Your reference is {{reference}}. If you can no longer come, please let us
                    know so we can offer your place to somebody else.
                    TEXT,
            ],
            [
                'key' => 'contact.acknowledgement',
                'name' => 'Contact enquiry acknowledgement',
                'description' => 'Auto-reply confirming an enquiry was received, with its '
                    .'reference.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['name', 'reference', 'department', 'sla_hours'],
                'required' => ['reference'],
                'subject' => 'We have your message — {{reference}}',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Thank you for contacting {{site_name}}. Your reference is
                    <strong>{{reference}}</strong>.</p>
                    <p>Someone will reply as soon as they can.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Thank you for contacting {{site_name}}. Your reference is {{reference}}.

                    Someone will reply as soon as they can.
                    TEXT,
            ],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function smsTemplates(): array
    {
        return [
            [
                'key' => 'donation.received',
                'name' => 'Donation received',
                'description' => 'Immediate confirmation that a gift went through. Often the only '
                    .'confirmation a mobile-money donor sees.',
                'category' => SmsTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['amount', 'reference'],
                'required' => ['amount', 'reference'],
                'max_segments' => 1,
                // "GHS", not "GH₵" — see the note on this class.
                'body' => 'Thank you. We have received your gift of GHS {{amount}}. '
                    .'Ref {{reference}}. {{site_name}}',
            ],
            [
                'key' => 'order.shipped',
                'name' => 'Order dispatched',
                'description' => 'Tells a customer their order is on its way.',
                'category' => SmsTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['order_reference', 'courier'],
                'required' => ['order_reference'],
                'max_segments' => 1,
                'body' => 'Your order {{order_reference}} has been dispatched with {{courier}}. '
                    .'{{site_name}}',
            ],
            [
                'key' => 'event.reminder',
                'name' => 'Event reminder',
                'description' => 'Sent the day before an event. Carries an expiry, so a backlog '
                    .'cannot deliver it after the event has happened.',
                'category' => SmsTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['event_title', 'event_time', 'venue'],
                'required' => ['event_title', 'event_time'],
                'max_segments' => 1,
                'body' => 'Reminder: {{event_title}} is tomorrow at {{event_time}}, {{venue}}. '
                    .'{{site_name}}',
            ],
            [
                'key' => 'volunteer.approved',
                'name' => 'Volunteer approved',
                'description' => 'Sent once safeguarding checks are complete and the application '
                    .'has been approved.',
                'category' => SmsTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['name'],
                'required' => ['name'],
                'max_segments' => 1,
                'body' => 'Hello {{name}}, your volunteer application with {{site_name}} has been '
                    .'approved. We will be in touch with next steps.',
            ],
        ];
    }
}

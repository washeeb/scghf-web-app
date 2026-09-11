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
                    <p>Your receipt {{receipt_number}} can be downloaded here: {{receipt_url}}</p>
                    <p>With gratitude,<br>{{organisation_legal_name}}</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{donor_name}},

                    Thank you for your gift of {{amount}} towards {{cause_name}}, received on
                    {{donation_date}}.

                    Your reference is {{reference}}.

                    {{acknowledgement}}

                    Your receipt {{receipt_number}} can be downloaded here: {{receipt_url}}

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
                'key' => 'donation.abandoned',
                'name' => 'Donation not completed — follow-up',
                'description' => 'Sent, only if the follow-up is switched on in settings, to a '
                    .'donor who reached the payment page and never finished. One message, once, '
                    .'and only to somebody who consented to email.',
                'category' => EmailTemplate::CATEGORY_MARKETING,
                'variables' => ['donor_name', 'amount', 'cause_name', 'retry_url'],
                'required' => ['donor_name', 'retry_url'],
                'subject' => 'Your gift to {{site_name}} was not completed',
                'html' => <<<'HTML'
                    <p>Dear {{donor_name}},</p>
                    <p>You started a gift of {{amount}} towards {{cause_name}} and the payment
                    did not complete. No money has been taken.</p>
                    <p>If something went wrong, or you simply ran out of time, you can pick it up
                    where you left off: {{retry_url}}</p>
                    <p>If you decided not to give, that is entirely fine — we will not write
                    again about this.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{donor_name}},

                    You started a gift of {{amount}} towards {{cause_name}} and the payment did
                    not complete. No money has been taken.

                    If something went wrong, or you simply ran out of time, you can pick it up
                    where you left off: {{retry_url}}

                    If you decided not to give, that is entirely fine — we will not write again
                    about this.
                    TEXT,
            ],
            [
                'key' => 'donation.tribute',
                'name' => 'A gift was made in tribute',
                'description' => 'Sent to the person the donor named when giving in honour or in '
                    .'memory of somebody. Says that a gift was made and for whom — never how much.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['donor_name', 'tribute_kind', 'tribute_name', 'tribute_message', 'cause_name'],
                'required' => ['tribute_kind', 'tribute_name'],
                'subject' => 'A gift to {{site_name}} {{tribute_kind}} {{tribute_name}}',
                'html' => <<<'HTML'
                    <p>Hello,</p>
                    <p>{{donor_name}} has made a gift to {{site_name}} {{tribute_kind}}
                    <strong>{{tribute_name}}</strong>, towards {{cause_name}}, and asked us to let
                    you know.</p>
                    <p>{{tribute_message}}</p>
                    <p>With warm regards,<br>{{site_name}}</p>
                    HTML,
                'text' => <<<'TEXT'
                    Hello,

                    {{donor_name}} has made a gift to {{site_name}} {{tribute_kind}} {{tribute_name}},
                    towards {{cause_name}}, and asked us to let you know.

                    {{tribute_message}}

                    With warm regards,
                    {{site_name}}
                    TEXT,
            ],
            [
                'key' => 'recurring.established',
                'name' => 'Regular gift set up',
                'description' => 'Sent once the first payment of a regular gift is confirmed. '
                    .'Carries the signed link a donor without an account uses to manage it.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['donor_name', 'amount', 'interval', 'cause_name', 'next_date', 'manage_url'],
                'required' => ['donor_name', 'amount', 'manage_url'],
                'subject' => 'Your regular gift to {{site_name}} is set up',
                'html' => <<<'HTML'
                    <p>Dear {{donor_name}},</p>
                    <p>Thank you. Your gift of <strong>{{amount}} {{interval}}</strong> towards
                    {{cause_name}} is set up. The next one is on {{next_date}}.</p>
                    <p>You can pause it, change the amount or stop it at any time here:
                    {{manage_url}}</p>
                    <p>A regular gift is what lets us plan. We are grateful.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{donor_name}},

                    Thank you. Your gift of {{amount}} {{interval}} towards {{cause_name}} is set
                    up. The next one is on {{next_date}}.

                    You can pause it, change the amount or stop it at any time here:
                    {{manage_url}}

                    A regular gift is what lets us plan. We are grateful.
                    TEXT,
            ],
            [
                'key' => 'recurring.failed',
                'name' => 'Regular gift — payment did not go through',
                'description' => 'Sent when a scheduled charge fails and will be retried. Assumes '
                    .'goodwill: a donor whose card expired is a donor, not a debtor.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['donor_name', 'amount', 'interval', 'cause_name', 'reason', 'next_date', 'attempts', 'manage_url'],
                'required' => ['donor_name', 'amount', 'manage_url'],
                'subject' => 'This {{interval}} gift did not go through',
                'html' => <<<'HTML'
                    <p>Dear {{donor_name}},</p>
                    <p>Your regular gift of {{amount}} towards {{cause_name}} did not go through
                    this time ({{reason}}). Nothing has been taken.</p>
                    <p>We will try again on {{next_date}}. If your card or Mobile Money wallet has
                    changed, the simplest thing is to set up the gift again and stop this one:
                    {{manage_url}}</p>
                    <p>If you would rather pause for a while, you can do that from the same link.
                    Thank you for giving regularly.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{donor_name}},

                    Your regular gift of {{amount}} towards {{cause_name}} did not go through this
                    time ({{reason}}). Nothing has been taken.

                    We will try again on {{next_date}}. If your card or Mobile Money wallet has
                    changed, the simplest thing is to set up the gift again and stop this one:
                    {{manage_url}}

                    If you would rather pause for a while, you can do that from the same link.
                    Thank you for giving regularly.
                    TEXT,
            ],
            [
                'key' => 'recurring.paused',
                'name' => 'Regular gift paused after repeated failures',
                'description' => 'Sent when a regular gift is paused because several charges in '
                    .'a row failed. We stop trying; the donor restarts when ready.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['donor_name', 'amount', 'interval', 'cause_name', 'reason', 'attempts', 'manage_url'],
                'required' => ['donor_name', 'amount', 'manage_url'],
                'subject' => 'We have paused your regular gift',
                'html' => <<<'HTML'
                    <p>Dear {{donor_name}},</p>
                    <p>Your regular gift of {{amount}} {{interval}} towards {{cause_name}} has not
                    gone through {{attempts}} times running, so we have paused it rather than keep
                    trying. Nothing has been taken.</p>
                    <p>Whenever you are ready, you can start it again — with a new card or wallet
                    if yours has changed — here: {{manage_url}}</p>
                    <p>Thank you for everything you have given. There is no need to reply.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{donor_name}},

                    Your regular gift of {{amount}} {{interval}} towards {{cause_name}} has not
                    gone through {{attempts}} times running, so we have paused it rather than keep
                    trying. Nothing has been taken.

                    Whenever you are ready, you can start it again — with a new card or wallet if
                    yours has changed — here: {{manage_url}}

                    Thank you for everything you have given. There is no need to reply.
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
                    'delivery_address', 'invoice_number', 'order_url',
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
                    <p>You can see this order at any time here: {{order_url}}</p>
                    <p>This is a purchase, not a donation, and no charitable receipt is issued
                    for it. Invoice number: {{invoice_number}}</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{customer_name}},

                    Thank you for your order. We have received your payment of {{order_total}}.

                    Order reference: {{order_reference}}

                    {{order_items}}

                    Delivery to: {{delivery_address}}

                    You can see this order at any time here: {{order_url}}

                    This is a purchase, not a donation, and no charitable receipt is issued
                    for it. Invoice number: {{invoice_number}}
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
                'key' => 'volunteer.approved',
                'name' => 'Volunteer application approved',
                'description' => 'Sent when an application is approved — which the software only '
                    .'allows once every safeguarding check is recorded.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['name', 'role', 'reference'],
                'required' => ['name'],
                'subject' => 'Welcome — your volunteer application has been approved',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Your application to volunteer with us as {{role}} has been approved. Thank
                    you — we are glad to have you.</p>
                    <p>Somebody from the team will be in touch with the next steps and your first
                    date. Your reference is {{reference}}.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Your application to volunteer with us as {{role}} has been approved. Thank
                    you — we are glad to have you.

                    Somebody from the team will be in touch with the next steps and your first
                    date. Your reference is {{reference}}.
                    TEXT,
            ],
            [
                'key' => 'volunteer.declined',
                'name' => 'Volunteer application declined',
                'description' => 'Sent when an application is declined. The wording is written '
                    .'by the person declining, each time — it is never generated from the reason '
                    .'recorded on the file.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['name', 'message', 'reference'],
                'required' => ['name', 'message'],
                'subject' => 'About your volunteer application',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Thank you for applying to volunteer with us.</p>
                    <p>{{message}}</p>
                    <p>Your reference is {{reference}}.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Thank you for applying to volunteer with us.

                    {{message}}

                    Your reference is {{reference}}.
                    TEXT,
            ],
            [
                'key' => 'event.registration_confirmed',
                'name' => 'Event registration confirmed',
                'description' => 'Sent when somebody registers for an event. Says whether the '
                    .'place is confirmed or waitlisted, and carries the join link for an online event.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['name', 'event_title', 'event_date', 'venue', 'reference', 'status', 'online_url'],
                'required' => ['name', 'event_title', 'event_date'],
                'subject' => 'You are registered for {{event_title}}',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>You are registered for <strong>{{event_title}}</strong> on
                    {{event_date}}{{venue}}.</p>
                    <p>{{status}}</p>
                    <p>{{online_url}}</p>
                    <p>Your reference is {{reference}}. If you can no longer come, please let us
                    know so we can offer your place to somebody else.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    You are registered for {{event_title}} on {{event_date}}{{venue}}.

                    {{status}}

                    {{online_url}}

                    Your reference is {{reference}}. If you can no longer come, please let us
                    know so we can offer your place to somebody else.
                    TEXT,
            ],
            [
                'key' => 'event.cancelled',
                'name' => 'Event cancelled',
                'description' => 'Sent to everybody registered when an event is cancelled. '
                    .'Always says why: "cancelled" on its own is not an explanation.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['name', 'event_title', 'event_date', 'reason', 'reference'],
                'required' => ['name', 'event_title', 'reason'],
                'subject' => '{{event_title}} has been cancelled',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>We are sorry to say that <strong>{{event_title}}</strong>, which you registered
                    for on {{event_date}}, has been cancelled.</p>
                    <p>{{reason}}</p>
                    <p>You do not need to do anything. If it is rearranged we will let you know.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    We are sorry to say that {{event_title}}, which you registered for on
                    {{event_date}}, has been cancelled.

                    {{reason}}

                    You do not need to do anything. If it is rearranged we will let you know.
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

            /*
             * The reply a member of staff actually writes.
             *
             * Separate from the acknowledgement above, which is automatic. This
             * one carries `{{reply}}` — whatever was typed in the inbox — and
             * quotes the original message underneath, because a sender reading
             * it three weeks later has no idea what "as discussed" refers to.
             *
             * Not locked: the greeting and the sign-off are the foundation's to
             * write. `{{reply}}` is required, so an editor cannot produce a
             * template that sends an empty answer.
             */
            [
                'key' => 'contact.reply',
                'name' => 'Reply to a contact enquiry',
                'description' => 'Sent from the contact inbox when somebody answers an enquiry. '
                    .'{{reply}} is what they typed.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['name', 'reference', 'reply', 'original_message', 'replied_by'],
                'required' => ['reply'],
                'subject' => 'Re: your message to {{site_name}} — {{reference}}',
                'preheader' => 'A reply to your enquiry.',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    {{reply}}
                    <p>If you need anything else, reply to this email and quote
                    <strong>{{reference}}</strong>.</p>
                    <p>{{replied_by}}<br>{{site_name}}</p>
                    <hr>
                    <p><em>Your original message:</em></p>
                    <blockquote>{{original_message}}</blockquote>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    {{reply}}

                    If you need anything else, reply to this email and quote {{reference}}.

                    {{replied_by}}
                    {{site_name}}

                    ---
                    Your original message:

                    {{original_message}}
                    TEXT,
            ],

            /*
             * An update on an appeal somebody gave to.
             *
             * ── Why this is worth sending at all ────────────────────────────
             *
             * The commonest reason a donor does not give a second time is that
             * they never heard what the first gift did. This is the message
             * that answers it, and it goes only to people who gave to THIS
             * appeal — a foundation that mails its whole list about one project
             * teaches the list to ignore it.
             *
             * Categorised as MARKETING rather than transactional, deliberately.
             * It is news, not a receipt, so it honours the marketing consent a
             * donor gave or withheld and carries an unsubscribe link. A
             * foundation that slips campaign mail through the transactional
             * channel is one whose receipts stop arriving three months later.
             */
            [
                'key' => 'cause.update',
                'name' => 'An update on an appeal',
                'description' => 'Sent to the donors of one appeal when an update is published. '
                    .'Marketing, so it respects consent and carries an unsubscribe link.',
                'category' => EmailTemplate::CATEGORY_MARKETING,
                'variables' => ['name', 'cause', 'title', 'body', 'cause_url'],
                'required' => ['cause', 'title'],
                'subject' => '{{cause}}: {{title}}',
                'preheader' => 'An update on something you gave to.',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>You gave to <strong>{{cause}}</strong>, so we wanted you to hear this
                    first.</p>
                    <h2>{{title}}</h2>
                    {{body}}
                    <p><a href="{{cause_url}}">See the appeal</a></p>
                    <p>Thank you — none of it happens without you.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    You gave to {{cause}}, so we wanted you to hear this first.

                    {{title}}

                    {{body}}

                    See the appeal: {{cause_url}}

                    Thank you — none of it happens without you.
                    TEXT,
            ],

            // ── Public accounts ─────────────────────────────────────────────
            //
            // All four are transactional and all four are LOCKED. Not because
            // the wording is sacred, but because each one carries a link that
            // does something: confirms an address, sets a password, or points
            // at the security page. An editor who removes {{verify_url}} while
            // rewording the welcome produces an email that cannot do the only
            // thing it exists to do — and `required` below makes that a
            // validation error at save time rather than a support ticket a
            // week later.
            [
                'key' => 'account.verify_email',
                'name' => 'Confirm your email address',
                'description' => 'Sent when somebody creates an account, and again if they ask '
                    .'for another link. The link expires — the wording must say so.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['name', 'verify_url', 'expires_in'],
                'required' => ['name', 'verify_url'],
                'subject' => 'Confirm your email address for {{site_name}}',
                'preheader' => 'One link, and your account is ready.',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Please confirm this is your email address so we can finish setting up
                    your account:</p>
                    <p><a href="{{verify_url}}">Confirm my email address</a></p>
                    <p>This link expires {{expires_in}}. If it has already expired, sign in and
                    ask for another one.</p>
                    <p>If you did not create an account with {{site_name}}, you can ignore this
                    message — nothing will happen and no account will be usable.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Please confirm this is your email address so we can finish setting up your
                    account:

                    {{verify_url}}

                    This link expires {{expires_in}}. If it has already expired, sign in and
                    ask for another one.

                    If you did not create an account with {{site_name}}, you can ignore this
                    message — nothing will happen and no account will be usable.
                    TEXT,
            ],
            [
                'key' => 'account.password_reset',
                'name' => 'Reset your password',
                'description' => 'Sent when somebody asks to reset a forgotten password. Never '
                    .'sent unprompted, which is what the closing paragraph tells the reader.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['name', 'reset_url', 'expires_in'],
                'required' => ['name', 'reset_url'],
                'subject' => 'Reset your {{site_name}} password',
                'preheader' => 'Only if you asked for it.',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Someone asked to reset the password on your {{site_name}} account. If
                    that was you:</p>
                    <p><a href="{{reset_url}}">Set a new password</a></p>
                    <p>This link expires {{expires_in}} and can only be used once.</p>
                    <p><strong>If it was not you, do nothing.</strong> Your password has not
                    changed, and nobody can change it without this link. If you keep receiving
                    these, please tell us at {{contact_email}}.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Someone asked to reset the password on your {{site_name}} account. If that
                    was you, set a new one here:

                    {{reset_url}}

                    This link expires {{expires_in}} and can only be used once.

                    If it was not you, do nothing. Your password has not changed, and nobody
                    can change it without this link. If you keep receiving these, please tell
                    us at {{contact_email}}.
                    TEXT,
            ],
            [
                'key' => 'account.password_changed',
                'name' => 'Your password was changed',
                'description' => 'Sent after a password change, whether the person used a '
                    .'reset link or changed it while signed in. Nobody asks for this email, '
                    .'and it is the only thing that makes a silent account takeover visible '
                    .'to the person it happened to.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['name', 'changed_at'],
                'required' => ['name'],
                'subject' => 'Your {{site_name}} password was changed',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>The password on your {{site_name}} account was changed on
                    {{changed_at}}.</p>
                    <p><strong>If that was not you, contact us immediately</strong> on
                    {{contact_phone}} or at {{contact_email}}. Whoever changed it can sign in
                    to your account until we stop them.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    The password on your {{site_name}} account was changed on {{changed_at}}.

                    If that was not you, contact us immediately on {{contact_phone}} or at
                    {{contact_email}}. Whoever changed it can sign in to your account until we
                    stop them.
                    TEXT,
            ],
            [
                'key' => 'account.new_device',
                'name' => 'Sign-in from a new device',
                'description' => 'Sent when an account is signed into from a device it has not '
                    .'been used on before — never on the first sign-in of a new account, when '
                    .'every device is new and the alert would mean nothing.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['name', 'signed_in_at', 'device', 'ip_address', 'security_url'],
                'required' => ['name'],
                'subject' => 'New sign-in to your {{site_name}} account',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Your account was signed into on {{signed_in_at}} from a device we have
                    not seen before: {{device}}, at {{ip_address}}.</p>
                    <p>If that was you there is nothing to do, and you will not get this
                    message again from the same device.</p>
                    <p><strong>If it was not you</strong>, change your password now:
                    {{security_url}}</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Your account was signed into on {{signed_in_at}} from a device we have not
                    seen before: {{device}}, at {{ip_address}}.

                    If that was you there is nothing to do, and you will not get this message
                    again from the same device.

                    If it was not you, change your password now:

                    {{security_url}}
                    TEXT,
            ],

            // ── Changing the address, and the second factor ─────────────────
            //
            // The two ALERTS here are the ones nobody asks for, and they are
            // the reason the pair exists: a change of address and a second
            // factor switched off are exactly what an attacker does once they
            // are inside, and both are silent everywhere else in the system.
            [
                'key' => 'account.email_change_confirm',
                'name' => 'Confirm a new email address',
                'description' => 'Sent to the NEW address when somebody asks to move their account '
                    .'to it. Opening the link is what actually performs the change — until then '
                    .'the account keeps its old address.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['name', 'confirm_url', 'new_email', 'expires_in'],
                'required' => ['name', 'confirm_url'],
                'subject' => 'Confirm your new email address for {{site_name}}',
                'preheader' => 'The change does not happen until you open this.',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Somebody asked to move a {{site_name}} account to this address. If that was
                    you, confirm it here:</p>
                    <p><a href="{{confirm_url}}">Confirm {{new_email}}</a></p>
                    <p>This link expires {{expires_in}}. Until it is opened the account keeps its
                    old address and nothing has changed.</p>
                    <p>If you were not expecting this, ignore it — nothing will happen, and the
                    person who holds the account has been told about the request as well.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Somebody asked to move a {{site_name}} account to this address. If that was
                    you, confirm it here:

                    {{confirm_url}}

                    This link expires {{expires_in}}. Until it is opened the account keeps its old
                    address and nothing has changed.

                    If you were not expecting this, ignore it — nothing will happen, and the person
                    who holds the account has been told about the request as well.
                    TEXT,
            ],
            [
                'key' => 'account.email_change_alert',
                'name' => 'Somebody asked to change your email address',
                'description' => 'Sent to the OLD address the moment a change is requested. This is '
                    .'the security control, not a courtesy: it is the one moment the account '
                    .'holder can stop a takeover, and it goes to the inbox they still control.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['name', 'new_email', 'cancel_url', 'expires_in'],
                'required' => ['name', 'cancel_url'],
                'subject' => 'Did you ask to change your {{site_name}} email address?',
                'preheader' => 'If not, stop it here.',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Somebody asked to move your {{site_name}} account to
                    <strong>{{new_email}}</strong>. Your address has not changed yet.</p>
                    <p>If that was you, open the link we sent to the new address and it will take
                    effect. There is nothing to do here.</p>
                    <p><strong>If it was not you, stop it now:</strong></p>
                    <p><a href="{{cancel_url}}">Cancel this change</a></p>
                    <p>Then change your password, because somebody who can request this is somebody
                    who is already signed in to your account. If you need help, contact us on
                    {{contact_phone}} or at {{contact_email}}.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Somebody asked to move your {{site_name}} account to {{new_email}}. Your address
                    has not changed yet.

                    If that was you, open the link we sent to the new address and it will take
                    effect. There is nothing to do here.

                    If it was NOT you, stop it now:

                    {{cancel_url}}

                    Then change your password, because somebody who can request this is somebody who
                    is already signed in to your account. If you need help, contact us on
                    {{contact_phone}} or at {{contact_email}}.
                    TEXT,
            ],
            [
                'key' => 'account.two_factor_enabled',
                'name' => 'Two-factor authentication was turned on',
                'description' => 'Sent when somebody adds a second step to their sign-in.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['name', 'changed_at', 'security_url'],
                'required' => ['name'],
                'subject' => 'Two-factor authentication is on for your {{site_name}} account',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>Signing in to your {{site_name}} account now needs a code from your
                    authenticator app as well as your password. This was set up on
                    {{changed_at}}.</p>
                    <p>Keep your recovery codes somewhere safe and away from your phone. They are
                    the only way back in if you lose it.</p>
                    <p>If this was not you, contact us immediately on {{contact_phone}} or at
                    {{contact_email}}.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    Signing in to your {{site_name}} account now needs a code from your
                    authenticator app as well as your password. This was set up on {{changed_at}}.

                    Keep your recovery codes somewhere safe and away from your phone. They are the
                    only way back in if you lose it.

                    If this was not you, contact us immediately on {{contact_phone}} or at
                    {{contact_email}}.
                    TEXT,
            ],
            [
                'key' => 'account.two_factor_disabled',
                'name' => 'Two-factor authentication was turned off',
                'description' => 'Sent when the second step is removed. Nobody asks for this email, '
                    .'and switching the factor off is precisely what an attacker does once they '
                    .'are inside — so it is the only thing that makes it visible.',
                'category' => EmailTemplate::CATEGORY_TRANSACTIONAL,
                'locked' => true,
                'variables' => ['name', 'changed_at', 'security_url'],
                'required' => ['name'],
                'subject' => 'Two-factor authentication was turned OFF for your {{site_name}} account',
                'html' => <<<'HTML'
                    <p>Dear {{name}},</p>
                    <p>The second step has been removed from your {{site_name}} account on
                    {{changed_at}}. Signing in now needs only your password.</p>
                    <p><strong>If that was not you, somebody else is in your account.</strong>
                    Change your password now — {{security_url}} — and contact us on
                    {{contact_phone}} or at {{contact_email}}.</p>
                    HTML,
                'text' => <<<'TEXT'
                    Dear {{name}},

                    The second step has been removed from your {{site_name}} account on
                    {{changed_at}}. Signing in now needs only your password.

                    If that was not you, somebody else is in your account. Change your password now:

                    {{security_url}}

                    Then contact us on {{contact_phone}} or at {{contact_email}}.
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
                'key' => 'recurring.failed',
                'name' => 'Regular gift did not go through',
                'description' => 'One line, when a scheduled charge fails. The donor whose '
                    .'mobile-money charge failed reads a text before an email.',
                'category' => SmsTemplate::CATEGORY_TRANSACTIONAL,
                'variables' => ['amount', 'next_date'],
                'required' => ['amount'],
                'max_segments' => 1,
                'body' => 'Your regular gift of GHS {{amount}} to {{site_name}} did not go '
                    .'through. Nothing was taken; we will try again on {{next_date}}.',
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

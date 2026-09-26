<?php

declare(strict_types=1);

use App\Communications\Exceptions\UnresolvedVariable;
use App\Communications\PhoneNumber;
use App\Communications\SmsSegmenter;
use App\Communications\TemplateRenderer;
use App\Models\EmailTemplate;
use App\Models\SmsTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Templates, segmentation and phone numbers
|--------------------------------------------------------------------------
|
| Three failures are being guarded against here, and all three are quiet ones.
|
|   1. A message that goes out saying "Dear ," because a variable was missing.
|      It cannot be recalled; a failed job can be fixed in five minutes.
|
|   2. An SMS template containing the cedi sign, which is not in the GSM-7
|      alphabet and silently triples the cost of every message sent from it.
|      CLAUDE.md mandates "GH₵" for display, so this trap is one the project
|      sets for itself.
|
|   3. A phone number stored as somebody typed it, so the same person appears
|      as five rows and the suppression list misses all five.
|
*/

// ── Segmentation ────────────────────────────────────────────────────────────

it('counts a plain GSM-7 message as one segment', function () {
    $measured = app(SmsSegmenter::class)->measure(str_repeat('a', 160));

    expect($measured['encoding'])->toBe(SmsSegmenter::ENCODING_GSM7)
        ->and($measured['segments'])->toBe(1)
        ->and($measured['units'])->toBe(160);
});

it('charges every segment the concatenation header once a message is multipart', function () {
    // 161 characters is 2 segments of 153, not 160 + 1. Getting this wrong
    // under-counts the bill on every long message.
    $measured = app(SmsSegmenter::class)->measure(str_repeat('a', 161));

    expect($measured['segments'])->toBe(2)
        ->and($measured['per_segment'])->toBe(153);
});

it('treats the cedi sign as forcing the expensive encoding', function () {
    $segmenter = app(SmsSegmenter::class);

    $withGhs = $segmenter->measure('Thank you for your gift of GHS 50.00.');
    $withCedi = $segmenter->measure('Thank you for your gift of GH₵ 50.00.');

    expect($withGhs['encoding'])->toBe(SmsSegmenter::ENCODING_GSM7)
        ->and($withCedi['encoding'])->toBe(SmsSegmenter::ENCODING_UCS2)
        ->and($withCedi['forced_ucs2_by'])->toContain('₵');
});

it('names the character that costs the money, not just the encoding', function () {
    // "This message is UCS-2" is not actionable. "₵ costs you a segment" is.
    $explanation = app(SmsSegmenter::class)->explain('Gift of GH₵ 50.00 received.');

    expect($explanation)->toContain('₵')
        ->and($explanation)->toContain('70');
});

it('counts GSM-7 extension characters as two septets', function () {
    // ^ { } \ [ ~ ] | € are encodable but cost an escape character each.
    $measured = app(SmsSegmenter::class)->measure(str_repeat('a', 158).'{}');

    expect($measured['encoding'])->toBe(SmsSegmenter::ENCODING_GSM7)
        ->and($measured['units'])->toBe(162)
        ->and($measured['segments'])->toBe(2);
});

it('counts an emoji as two UCS-2 units', function () {
    $measured = app(SmsSegmenter::class)->measure('🙏');

    expect($measured['characters'])->toBe(1)
        ->and($measured['units'])->toBe(2);
});

// ── Phone numbers ───────────────────────────────────────────────────────────

it('normalises every way a Ghanaian number gets typed to one value', function (string $input) {
    expect(PhoneNumber::normalise($input))->toBe('+233241234567');
})->with([
    '0241234567',
    '024 123 4567',
    '+233241234567',
    '+233 24 123 4567',
    '00233241234567',
    '233241234567',
]);

it('refuses a nine-digit pre-2010 number rather than guessing', function () {
    // The 2010 migration inserted a digit into the middle. A guessed number
    // sends somebody else's donation receipt to a stranger.
    expect(fn () => PhoneNumber::normalise('24123456'))
        ->toThrow(InvalidArgumentException::class);
});

it('attributes a number to its network for cost reporting', function () {
    expect(PhoneNumber::network('0241234567'))->toBe('mtn')
        ->and(PhoneNumber::network('0201234567'))->toBe('telecel')
        ->and(PhoneNumber::network('0271234567'))->toBe('at');
});

it('shows a number back in the national format Ghanaians read', function () {
    expect(PhoneNumber::forDisplay('+233241234567'))->toBe('024 123 4567');
});

// ── Rendering ───────────────────────────────────────────────────────────────

it('refuses to render when a required variable is missing', function () {
    $template = EmailTemplate::factory()->create([
        'subject' => 'Receipt {{reference}}',
        'body_html' => '<p>Thank you for {{amount}}.</p>',
        'required_variables' => ['reference', 'amount'],
    ]);

    expect(fn () => $template->render(['reference' => 'SCGHF-D-1']))
        ->toThrow(UnresolvedVariable::class);
});

it('treats an empty value as missing, because the reader cannot tell them apart', function () {
    $template = EmailTemplate::factory()->create([
        'body_html' => '<p>Dear {{name}},</p>',
        'required_variables' => ['name'],
    ]);

    expect(fn () => $template->render(['name' => '']))
        ->toThrow(UnresolvedVariable::class);
});

it('collapses an optional placeholder rather than showing a raw token', function () {
    $template = EmailTemplate::factory()->create([
        'subject' => 'Hello',
        'body_html' => '<p>Hello{{middle_name}}, welcome.</p>',
        'required_variables' => [],
    ]);

    $rendered = $template->render([]);

    expect($rendered['html'])->not->toContain('{{')
        ->and($rendered['html'])->toContain('Hello, welcome.');
});

it('escapes values in HTML but not in plain text', function () {
    $template = EmailTemplate::factory()->create([
        'body_html' => '<p>{{name}}</p>',
        'body_text' => '{{name}}',
        'required_variables' => [],
    ]);

    $rendered = $template->render(['name' => 'Mensah & Sons']);

    expect($rendered['html'])->toContain('Mensah &amp; Sons')
        ->and($rendered['text'])->toContain('Mensah & Sons');
});

it('strips newlines from a subject, because a header is not a place for them', function () {
    // A newline in Subject: lets whatever follows become a header of its own —
    // a Bcc:, for instance. The value here comes from a donor-supplied name.
    $template = EmailTemplate::factory()->create([
        'subject' => 'Gift from {{name}}',
        'required_variables' => [],
    ]);

    $rendered = $template->render(['name' => "Ama\nBcc: someone@example.com"]);

    expect($rendered['subject'])->not->toContain("\n");
});

it('flags a placeholder nobody declared', function () {
    $template = EmailTemplate::factory()->create([
        'body_html' => '<p>Dear {{donor_nme}},</p>',
        'available_variables' => ['donor_name'],
    ]);

    expect($template->undeclaredVariables())->toContain('donor_nme');
});

it('makes the site name available to every template without declaring it', function () {
    expect(app(TemplateRenderer::class)->globals())->toHaveKey('current_year');
});

// ── Template gates ──────────────────────────────────────────────────────────

it('refuses to deactivate a locked template', function () {
    // The failure this prevents is silent: somebody tidies the list, receipts
    // stop going out, and nothing anywhere reports an error.
    $template = EmailTemplate::factory()->locked()->create();

    expect(fn () => $template->update(['is_active' => false]))
        ->toThrow(RuntimeException::class, 'cannot be deactivated');
});

it('refuses to delete a locked template', function () {
    $template = EmailTemplate::factory()->locked()->create();

    expect(fn () => $template->delete())->toThrow(RuntimeException::class);
});

it('still allows a locked template to be reworded', function () {
    $template = EmailTemplate::factory()->locked()->create();

    $template->update(['subject' => 'A warmer subject line for {{name}}']);

    expect($template->fresh()->subject)->toContain('A warmer subject line');
});

it('explains that a deactivated template is why nothing was sent', function () {
    $template = EmailTemplate::factory()->create(['is_active' => false]);

    expect(fn () => EmailTemplate::forKey($template->key))
        ->toThrow(RuntimeException::class, 'deactivated');
});

// ── SMS template gates ──────────────────────────────────────────────────────

it('measures an SMS template on every save', function () {
    $template = SmsTemplate::factory()->create(['body' => 'Short message.']);

    expect($template->encoding)->toBe(SmsSegmenter::ENCODING_GSM7)
        ->and($template->estimated_segments)->toBe(1)
        ->and($template->character_count)->toBe(14);
});

it('refuses an SMS template that blows its segment budget', function () {
    // Refused rather than warned about: a warning is dismissed once, the cost
    // is paid on every message for as long as the template lives.
    expect(fn () => SmsTemplate::factory()->create([
        'body' => str_repeat('a', 400),
        'max_segments' => 2,
    ]))->toThrow(RuntimeException::class, 'segments');
});

it('catches a cedi sign pushing a template over its budget', function () {
    // 140 GSM-7 characters is one segment. The same text with one ₵ in it is
    // UCS-2, which fits 70 per segment — so it becomes three.
    expect(fn () => SmsTemplate::factory()->create([
        'body' => str_repeat('a', 139).'₵',
        'max_segments' => 2,
    ]))->toThrow(RuntimeException::class);
});

it('refuses a sender ID longer than the networks accept', function () {
    // Over-length sender IDs are rejected silently by Ghanaian networks.
    expect(fn () => SmsTemplate::factory()->create(['sender_id' => 'TwelveChars!']))
        ->toThrow(RuntimeException::class, 'sender ID');
});

it('re-measures the rendered body, not the template', function () {
    // {{name}} is eight characters; a real name might be twenty-five. That is
    // the difference between one segment and two, on every message.
    $template = SmsTemplate::factory()->create([
        'body' => str_repeat('a', 150).'{{name}}',
        'max_segments' => 3,
        'required_variables' => [],
    ]);

    $rendered = $template->measureRendered(['name' => str_repeat('b', 30)]);

    expect($template->estimated_segments)->toBe(2)
        ->and($rendered['segments'])->toBe(2)
        ->and($rendered['units'])->toBe(180);
});

it('costs a message in integer pesewas', function () {
    config()->set('communications.sms.cost_per_segment_minor', 4);

    $template = SmsTemplate::factory()->create(['body' => 'One segment.']);

    expect($template->estimatedCostMinor(100))->toBe(400);
});

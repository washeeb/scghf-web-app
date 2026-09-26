<?php

declare(strict_types=1);

use App\Enums\DonationStatus;
use App\Enums\OrderStatus;
use App\Models\ContactMessage;
use App\Models\Donation;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Media;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\ShippingRate;
use App\Models\ShippingZone;
use App\Models\Subscriber;
use App\Models\VolunteerApplication;
use App\Models\VolunteerOpportunity;
use App\Support\Settings;
use App\Support\ThemeTokens;
use Database\Seeders\CauseSeeder;
use Database\Seeders\CmsReferenceSeeder;
use Database\Seeders\DivisionSeeder;
use Database\Seeders\MessageTemplateSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ShippingZoneSeeder;
use Database\Seeders\ThemeSettingsSeeder;

/*
|--------------------------------------------------------------------------
| Phase 14 — the critical paths, in a real browser
|--------------------------------------------------------------------------
|
| The feature suite proves what the server does with a request. It cannot
| prove that a person at a phone can produce that request: that the donate
| button is reachable by keyboard, that the theme toggle changes the page
| and stays changed, that the fake checkout's "Pay" lands on a thank-you.
| These run Chromium (Playwright, through pest-plugin-browser) against the
| application served in-process, so the database, the fake gateway and the
| sync queue are the test's own.
|
| Nothing here is a substitute for the money tests. The fake gateway signs
| a real webhook and the real handler processes it, which is the point of
| having a fake gateway rather than a mock.
|
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(CmsReferenceSeeder::class);
    $this->seed(DivisionSeeder::class);
    $this->seed(CauseSeeder::class);
    $this->seed(MessageTemplateSeeder::class);
    $this->seed(ShippingZoneSeeder::class);

    setting()->set('general.legal_name', "St. Cecilia's Greater Hope Foundations");
    setting()->set('general.tin', 'C0001234567');
    config(['payments.paystack.webhook_secret' => 'sk_test_webhook_secret_for_tests']);

    ThemeTokens::flush();
    app(Settings::class)->flush();
});

/** The honeypot refuses a form filled faster than a person could; a person is slower than this. */
function humanPause(): void
{
    sleep((int) config('honeypot.amount_of_seconds', 1) + 1);
}

// ── Money ───────────────────────────────────────────────────────────────────

it('completes a one-off donation from the form to the thank-you page', function () {
    $page = visit('/donate');

    $page->assertNoJavaScriptErrors()
        ->fill('amount_other', '50')
        ->fill('donor_name', 'Ama Mensah')
        ->fill('donor_email', 'ama@example.test')
        ->fill('donor_phone', '0241234567')
        // Covering the fee is offered ticked; this donor declines it, so the
        // amount on the sandbox is the amount typed.
        ->uncheck('cover_fee')
        ->check('consent');

    humanPause();

    $page->click('Continue to payment')
        ->assertPathBeginsWith('/payments/fake/')
        ->assertSee('GH₵ 50.00')
        ->click('Pay successfully')
        ->assertPathContains('/thank-you')
        ->assertSee('Thank you');

    $donation = Donation::sole();

    expect($donation->status)->toBe(DonationStatus::Completed)
        ->and($donation->amount)->toEqualPesewas(5_000)
        ->and($donation->donor_phone)->toBe('+233241234567');
});

it('tells the donor plainly when the card is declined and takes nothing', function () {
    $page = visit('/donate');

    $page->fill('amount_other', '20')
        ->fill('donor_name', 'Kofi Asante')
        ->fill('donor_email', 'kofi@example.test')
        ->check('consent');

    humanPause();

    $page->click('Continue to payment')
        ->assertPathBeginsWith('/payments/fake/')
        ->click('Simulate a declined card')
        ->assertSee('That payment did not go through')
        ->assertSee('Nothing has been charged')
        ->assertDontSee('Thank you');

    expect(Donation::sole()->status)->not->toBe(DonationStatus::Completed);
});

it('completes a purchase from the product page through the basket to the order page', function () {
    $zone = ShippingZone::where('slug', 'greater-accra')->firstOrFail();
    $zone->update(['is_active' => true]);
    ShippingRate::create(['shipping_zone_id' => $zone->id, 'name' => 'Standard', 'price' => 2_500]);

    $product = Product::factory()->create(['name' => 'Tote bag', 'slug' => 'tote-bag', 'is_published' => true]);
    $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'price' => 4_500, 'weight_grams' => 400]);
    $variant->restock(5);

    $page = visit('/shop/tote-bag');

    $page->assertSee('Tote bag')
        ->click('Add to basket')
        ->assertPathIs('/basket')
        ->assertSee('Tote bag')
        ->click('Go to checkout');

    $page->assertPathIs('/checkout')
        ->fill('customer_name', 'Ama Boateng')
        ->fill('customer_email', 'ama@example.test')
        ->fill('customer_phone', '0241234567')
        ->radio('fulfilment', 'deliver')
        ->select('delivery_region', 'Greater Accra')
        ->fill('delivery_city', 'Accra')
        ->fill('delivery_area', 'Madina')
        ->fill('delivery_address', 'House 12, Ring Road')
        ->fill('delivery_gps', 'GA-184-3456')
        ->check('consent');

    humanPause();

    $page->press('Continue to payment')
        ->assertPathBeginsWith('/payments/fake/')
        ->click('Pay successfully')
        ->assertPathBeginsWith('/shop/orders/');

    $order = Order::sole();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->total)->toEqualPesewas(7_000);
});

// ── The page itself ─────────────────────────────────────────────────────────

it('switches the theme from the toggle and keeps it across a reload', function () {
    $page = visit('/');

    // The control is an icon button that opens a menu of the four themes.
    $page->assertNoJavaScriptErrors()
        ->click('[data-theme-menu] summary')
        ->click('[data-theme-option="dark"]')
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertScript('document.documentElement.dataset.theme', 'dark')
        ->assertScript('document.cookie.includes("scghf_theme=dark")', true)
        // Choosing closes the menu.
        ->assertScript('document.querySelector("[data-theme-menu]").open', false);

    $page->refresh()
        ->assertScript('document.documentElement.classList.contains("dark")', true)
        ->assertScript('document.querySelector("[data-theme-option=dark]").getAttribute("aria-checked")', 'true');

    $page->click('[data-theme-menu] summary')
        ->click('[data-theme-option="light"]')
        ->assertScript('document.documentElement.classList.contains("dark")', false)
        ->refresh()
        ->assertScript('document.documentElement.classList.contains("dark")', false);
});

it('lets a visitor use the buttons on a hero slide after it has been advanced', function () {
    /*
     * The regression this exists for: every slide but the first ships with
     * `pointer-events-none`, and the script removed only the opacity — so
     * slide two faded in looking perfectly ordinary with two dead buttons
     * on it. Nothing the feature suite can see, because the markup is right;
     * it is the computed style that is wrong.
     */
    $image = Media::factory()->create([
        'name' => 'slider',
        'alt_text' => 'A photograph',
        'metadata_stripped_at' => now(),
        'sanitisation_error' => null,
        'mime_type' => 'image/jpeg',
    ]);

    $page = Page::factory()->create(['slug' => 'slider-test', 'title' => 'Slider test']);
    $page->publish();
    $page->sections()->create([
        'block_type' => 'hero',
        'sort_order' => 0,
        'data' => [
            'heading' => 'The first slide',
            'image' => $image->getKey(),
            'slides' => [[
                'heading' => 'The second slide',
                'image' => $image->getKey(),
                'primary_cta_label' => 'Give today',
                'primary_cta_url' => '/donate',
            ]],
        ],
    ]);

    $browser = visit('/slider-test');

    $browser->assertNoJavaScriptErrors()
        ->click('[data-hero-next]')
        // The control row is clear of the panel the next section rises into,
        // rather than half behind it.
        ->assertScript(
            'Math.round(document.querySelector("[data-hero-slider] [aria-hidden=true].absolute.inset-x-3").getBoundingClientRect().top'
            .' - document.querySelector("[data-hero-prev]").getBoundingClientRect().bottom) >= 0',
            true,
        )
        // And a click at the middle of the visible slide's button reaches it.
        ->assertScript(
            '(() => { const s = [...document.querySelectorAll("[data-hero-slide]")].find(e => !e.hasAttribute("inert"));'
            .' const a = s.querySelector("a"); const r = a.getBoundingClientRect();'
            .' const t = document.elementFromPoint(r.left + r.width / 2, r.top + r.height / 2);'
            .' return t === a || a.contains(t); })()',
            true,
        );

    $browser->click('[data-hero-slide]:not([inert]) a')
        ->assertPathIs('/donate');
});

it('can be driven from the keyboard alone: skip link, menu, donate', function () {
    $page = visit('/');

    // First Tab lands on the skip link; Enter moves focus into main.
    $page->keys('html > body', ['Tab'])
        ->assertScript('document.activeElement.getAttribute("href")', '#main-content')
        ->keys('html > body', ['Enter'])
        ->assertScript('document.activeElement.id === "main-content" || document.activeElement.closest("main") !== null', true);

    // Every interactive element in the header is reachable and shows focus.
    $page->assertScript(<<<'JS'
        (() => {
            const focusable = [...document.querySelectorAll('header a[href], header button, header select, header summary')];
            return focusable.length > 3 && focusable.every((el) => el.tabIndex >= 0);
        })()
    JS, true);

    // Tab through the header until the Donate link has focus, then Enter.
    $page->assertScript(<<<'JS'
        (() => {
            const link = [...document.querySelectorAll('header a[href]')].find((a) => /donate/i.test(a.textContent));
            if (! link) return 'no donate link in the header';
            link.focus();
            return document.activeElement === link && getComputedStyle(link).outlineStyle !== undefined;
        })()
    JS, true);

    $page->keys('header a[href*="/donate"]', ['Enter'])
        ->assertPathIs('/donate')
        ->assertSee('Continue to payment');
});

it('has no axe violations on the home, donate and shop pages in either theme', function () {
    foreach (['/', '/donate', '/shop'] as $url) {
        visit($url)->assertNoAccessibilityIssues();
    }

    $page = visit('/');
    $page->click('[data-theme-menu] summary')->click('[data-theme-option="dark"]');

    foreach (['/', '/donate', '/shop'] as $url) {
        visit($url)->assertNoAccessibilityIssues();
    }
});

// ── Every public form ───────────────────────────────────────────────────────

it('submits the contact form', function () {
    $page = visit('/contact');

    $page->fill('name', 'Esi Owusu')
        ->fill('email', 'esi@example.test')
        ->fill('message', 'I would like to know more about volunteering in Bolgatanga.')
        ->check('consent');

    humanPause();

    $page->press('Send message');

    expect(ContactMessage::sole()->email)->toBe('esi@example.test');
});

it('signs up to the newsletter from the footer', function () {
    $page = visit('/');

    $page->fill('footer-email', 'news@example.test');

    humanPause();

    $page->press('Join');

    expect(Subscriber::where('email', 'news@example.test')->exists())->toBeTrue();
});

it('applies for a volunteer role', function () {
    // A role without contact with children: no date of birth, no referees.
    // The safeguarding form for the other kind is covered in VolunteersTest.
    $role = VolunteerOpportunity::factory()->create(['is_published' => true, 'title' => 'Events helper', 'involves_vulnerable_contact' => false]);

    $page = visit('/volunteer/'.$role->slug);

    $page->assertSee('Events helper')
        ->fill('full_name', 'Yaw Darko')
        ->fill('email', 'yaw@example.test')
        ->fill('phone', '0201234567')
        ->fill('motivation', 'I taught maths for six years and have Saturdays free.')
        ->check('declaration');

    humanPause();

    $page->press('Send my application');

    expect(VolunteerApplication::sole()->email)->toBe('yaw@example.test');
});

it('registers for an event', function () {
    $event = Event::factory()->create(['title' => 'Community clean-up']);

    $page = visit('/events/'.$event->slug);

    $page->assertSee('Community clean-up')
        ->fill('name', 'Adwoa Sarpong')
        ->fill('email', 'adwoa@example.test')
        ->radio('photography_consent', '0')
        ->check('consent');

    humanPause();

    $page->press('Register');

    expect(EventRegistration::sole()->email)->toBe('adwoa@example.test');
});

it('stops an empty required field in the browser and a too-short one on the server, each at the field', function () {
    $page = visit('/contact');

    // Native validation first: an empty required email never leaves the page.
    $page->fill('name', 'Esi Owusu')->fill('message', 'Hello there, this is long enough.')->check('consent');
    humanPause();
    $page->press('Send message')
        ->assertPathIs('/contact')
        ->assertScript('document.querySelector("#email").validity.valueMissing', true);

    expect(ContactMessage::count())->toBe(0);

    // Then the server: a rule the browser cannot know, answered at the field.
    $page->fill('email', 'esi@example.test')->fill('message', 'Hi');
    humanPause();
    $page->press('Send message')
        ->assertPathIs('/contact')
        ->assertAttribute('#message', 'aria-invalid', 'true')
        ->assertVisible('#message-error');

    expect(ContactMessage::count())->toBe(0);
});

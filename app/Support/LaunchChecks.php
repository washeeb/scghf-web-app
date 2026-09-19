<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DonationStatus;
use App\Enums\UserType;
use App\Models\Cause;
use App\Models\Donation;
use App\Models\Page;
use App\Models\PaymentTransaction;
use App\Models\PaymentWebhookEvent;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Project;
use App\Models\SmsLog;
use App\Models\TeamMember;
use App\Models\User;
use App\Models\WhatsappTemplate;
use App\Providers\CommunicationServiceProvider;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The go-live checklist, as far as the application can answer it itself.
 *
 * `SiteHealth` says whether the installation works. This says whether it
 * is ready to be the foundation's public face: the legal pages published,
 * three real projects, a shop with stock, a live cedi taken and refunded,
 * the mail domain authenticated, every staff account behind a second
 * factor, no demo account left behind. Each row is a question a person
 * would otherwise tick from memory on launch day, and memory is what
 * launch checklists exist to replace.
 *
 * DNS and TLS are looked up live (`dns_get_record`, a TLS handshake) and
 * report "could not check" rather than fail when the network is not
 * there — a laptop offline is not a launch blocker. The lookups can be
 * replaced for tests through `resolveDnsUsing()` / `certificateUsing()`.
 */
final class LaunchChecks
{
    /** @var array<string, string> slug => label */
    public const LEGAL_PAGES = [
        'privacy-policy' => 'Privacy policy',
        'terms' => 'Terms of use',
        'donation-policy' => 'Donation policy',
        'refund-policy' => 'Refund policy',
        'shipping-and-delivery' => 'Shipping and delivery',
        'cookie-policy' => 'Cookie policy',
        'safeguarding' => 'Safeguarding',
        'accessibility' => 'Accessibility',
        'whistleblowing' => 'Whistleblowing',
        'anti-fraud' => 'Anti-fraud',
    ];

    private static ?Closure $dns = null;

    private static ?Closure $certificate = null;

    /** @param  Closure(string, int): (array<int, array<string, mixed>>|false)  $resolver */
    public static function resolveDnsUsing(?Closure $resolver): void
    {
        self::$dns = $resolver;
    }

    /** @param  Closure(string): (array<string, mixed>|null)  $reader */
    public static function certificateUsing(?Closure $reader): void
    {
        self::$certificate = $reader;
    }

    /** @return Collection<int, HealthCheck> */
    public function checks(): Collection
    {
        return collect([
            $this->wrap('legal_pages', 'Legal pages published', fn () => $this->legalPages()),
            $this->wrap('contact_details', 'Contact details filled in', fn () => $this->contactDetails()),
            $this->wrap('team', 'Team and board on the site', fn () => $this->team()),
            $this->wrap('programmes', 'Projects and appeals live', fn () => $this->programmes()),
            $this->wrap('shop', 'Shop stocked', fn () => $this->shop()),
            $this->wrap('live_gift', 'A live gift taken and refunded', fn () => $this->liveGift()),
            $this->wrap('webhook_seen', 'Live webhook received', fn () => $this->webhookSeen()),
            $this->wrap('mail_dns', 'Mail domain: SPF, DKIM, DMARC', fn () => $this->mailDns()),
            $this->wrap('sms_live', 'A live SMS delivered', fn () => $this->smsLive()),
            $this->wrap('tls', 'Certificate and its renewal', fn () => $this->tls()),
            $this->wrap('canonical_host', 'One canonical host', fn () => $this->canonicalHost()),
            $this->wrap('analytics', 'Analytics chosen', fn () => $this->analytics()),
            $this->wrap('storage_engine', 'Every table on InnoDB', fn () => $this->storageEngine()),
            $this->wrap('timestamp_defaults', 'No silent ON UPDATE timestamps', fn () => $this->timestampDefaults()),
            $this->wrap('flags', 'Feature flags honest', fn () => $this->flags()),
            $this->wrap('whatsapp', 'WhatsApp ready if on', fn () => $this->whatsapp()),
            $this->wrap('staff_2fa', 'Two-factor on every staff account', fn () => $this->staffTwoFactor()),
            $this->wrap('demo_accounts', 'No demo accounts', fn () => $this->demoAccounts()),
            $this->wrap('demo_data', 'No demo data', fn () => $this->demoData()),
        ]);
    }

    /** @return Collection<int, HealthCheck> */
    public function problems(): Collection
    {
        return $this->checks()->filter(fn (HealthCheck $c): bool => $c->isProblem())->values();
    }

    // ── Content ──────────────────────────────────────────────────────────────

    private function legalPages(): HealthCheck
    {
        $live = Page::query()
            ->whereIn('slug', array_keys(self::LEGAL_PAGES))
            ->whereNull('parent_id')
            ->get()
            ->filter(fn (Page $p): bool => $p->isLive())
            ->pluck('slug')
            ->all();

        $missing = array_diff_key(self::LEGAL_PAGES, array_flip($live));

        if ($missing !== []) {
            return HealthCheck::critical('legal_pages', 'Legal pages published',
                count($live).' of '.count(self::LEGAL_PAGES).' published',
                'Not yet published: '.implode(', ', $missing).'. The trustees review each draft (Website → Pages), then set it to Published. The privacy policy and the cookie policy are legal requirements before a single donor is asked for an email address.');
        }

        return HealthCheck::ok('legal_pages', 'Legal pages published', 'All '.count(self::LEGAL_PAGES));
    }

    private function contactDetails(): HealthCheck
    {
        $needed = ['contact.email_general', 'contact.phone_primary', 'contact.address', 'general.legal_name', 'general.tin'];
        $missing = array_values(array_filter($needed, fn (string $key): bool => ! setting()->has($key)));

        if ($missing !== []) {
            return HealthCheck::critical('contact_details', 'Contact details filled in', count($missing).' missing',
                'Still a placeholder or empty: '.implode(', ', $missing).'. Website → Site settings. The address and TIN print on every receipt.');
        }

        return HealthCheck::ok('contact_details', 'Contact details filled in', 'Address, phone, email, legal name, TIN');
    }

    private function team(): HealthCheck
    {
        $members = TeamMember::query()->where('is_published', true)->get();
        $trustees = $members->where('is_trustee', true)->count();
        $withPhoto = $members->whereNotNull('photo_id')->count();

        if ($members->count() < 2 || $trustees === 0) {
            return HealthCheck::warning('team', 'Team and board on the site',
                $members->count().' published, '.$trustees.' trustees',
                'Donors give to people. Content → Team: the director and at least the trustees, with photographs (with their consent recorded).');
        }

        if ($withPhoto < $members->count()) {
            return HealthCheck::warning('team', 'Team and board on the site',
                $members->count().' published, '.($members->count() - $withPhoto).' without a photo',
                'Add a photograph to each team member (Content → Team), or the card shows initials.');
        }

        return HealthCheck::ok('team', 'Team and board on the site', $members->count().' published, '.$trustees.' trustees, all with photos');
    }

    private function programmes(): HealthCheck
    {
        $projects = Project::query()->where('is_published', true)->count();
        $causes = Cause::query()->where('is_published', true)->where('is_general_fund', false)->count();
        $general = Cause::query()->where('is_general_fund', true)->where('is_published', true)->exists();

        if (! $general) {
            return HealthCheck::critical('programmes', 'Projects and appeals live', 'General Fund unpublished',
                'Every gift without a chosen appeal goes to the General Fund; it must be published (Programmes → Appeals).');
        }

        if ($projects < 3 || $causes < 3) {
            return HealthCheck::warning('programmes', 'Projects and appeals live', "{$projects} projects, {$causes} appeals",
                'The launch brief asks for at least three real projects and three real appeals published, so the site has something to give to on day one.');
        }

        return HealthCheck::ok('programmes', 'Projects and appeals live', "{$projects} projects, {$causes} appeals, General Fund");
    }

    private function shop(): HealthCheck
    {
        if (! (bool) config('features.shop', true)) {
            return HealthCheck::ok('shop', 'Shop stocked', 'Shop is switched off');
        }

        $products = Product::query()->where('is_published', true)->count();
        $inStock = ProductVariant::query()->where('is_active', true)->where('stock_on_hand', '>', 0)
            ->whereHas('product', fn ($q) => $q->where('is_published', true))->count();

        if ($products === 0 || $inStock === 0) {
            return HealthCheck::warning('shop', 'Shop stocked', "{$products} products, {$inStock} variants in stock",
                'Either stock the shop (Shop → Products → Adjust stock) or switch it off (FEATURE_SHOP=false) so the menu does not lead to an empty page.');
        }

        return HealthCheck::ok('shop', 'Shop stocked', "{$products} products, {$inStock} variants in stock");
    }

    // ── Money ────────────────────────────────────────────────────────────────

    private function liveGift(): HealthCheck
    {
        if (config('payments.driver') !== 'paystack' || ! str_starts_with((string) config('payments.paystack.secret_key'), 'sk_live_')) {
            return HealthCheck::warning('live_gift', 'A live gift taken and refunded', 'Not on live keys',
                'This check means something only with PAYMENT_DRIVER=paystack and a live key. Until then it cannot pass.');
        }

        $refunded = Donation::query()
            ->where('status', DonationStatus::Refunded)
            ->whereHas('transaction', fn ($q) => $q->where('gateway', PaymentTransaction::GATEWAY_PAYSTACK))
            ->exists();

        $completed = Donation::query()
            ->where('status', DonationStatus::Completed)
            ->whereHas('transaction', fn ($q) => $q->where('gateway', PaymentTransaction::GATEWAY_PAYSTACK))
            ->exists();

        if (! $completed && ! $refunded) {
            return HealthCheck::critical('live_gift', 'A live gift taken and refunded', 'None yet',
                'PHASE-8-PAYMENTS-TEST-PLAN.md §3: one GH₵ 1.00 gift from a trustee\'s own card, watched through the webhook, receipted, then refunded through the two-person flow. Record it in §5 of that document.');
        }

        if (! $refunded) {
            return HealthCheck::warning('live_gift', 'A live gift taken and refunded', 'Taken, not yet refunded',
                'The refund is the second half of the test: it proves the two-person flow and the refund.processed webhook on the live account.');
        }

        return HealthCheck::ok('live_gift', 'A live gift taken and refunded', 'Done');
    }

    private function webhookSeen(): HealthCheck
    {
        $last = PaymentWebhookEvent::query()->where('signature_valid', true)->max('received_at');

        if ($last === null) {
            return HealthCheck::critical('webhook_seen', 'Live webhook received', 'Never',
                'No signed webhook has ever arrived. Register the webhook URL in the Paystack dashboard for the mode you are in, then make the test gift; Finance → Webhook events shows it within seconds.');
        }

        return HealthCheck::ok('webhook_seen', 'Live webhook received', 'Last '.Carbon::parse($last)->diffForHumans());
    }

    // ── Mail, SMS, domain ────────────────────────────────────────────────────

    private function mailDns(): HealthCheck
    {
        $from = (string) config('mail.from.address');
        $domain = str_contains($from, '@') ? substr($from, strpos($from, '@') + 1) : '';

        if ($domain === '' || str_contains($domain, '{{')) {
            return HealthCheck::critical('mail_dns', 'Mail domain: SPF, DKIM, DMARC', 'No sending address',
                'MAIL_FROM_ADDRESS is not set to an address on the sending subdomain (PHASE-10-EMAIL-DELIVERABILITY.md).');
        }

        $spf = $this->txt($domain);
        $dmarc = $this->txt('_dmarc.'.$domain);
        $dkim = $this->txt('resend._domainkey.'.$domain);

        if ($spf === null || $dmarc === null || $dkim === null) {
            return HealthCheck::warning('mail_dns', 'Mail domain: SPF, DKIM, DMARC', 'Could not look up '.$domain,
                'DNS was not reachable from here. Run this on the server, or check the three records by hand: SPF (TXT on '.$domain.'), DKIM (resend._domainkey.'.$domain.'), DMARC (_dmarc.'.$domain.').');
        }

        $missing = [];
        if (! collect($spf)->contains(fn (string $r): bool => str_starts_with($r, 'v=spf1'))) {
            $missing[] = 'SPF';
        }
        if ($dkim === []) {
            $missing[] = 'DKIM';
        }
        if (! collect($dmarc)->contains(fn (string $r): bool => str_starts_with($r, 'v=DMARC1'))) {
            $missing[] = 'DMARC';
        }

        if ($missing !== []) {
            return HealthCheck::critical('mail_dns', 'Mail domain: SPF, DKIM, DMARC', 'Missing: '.implode(', ', $missing),
                'Receipts will land in spam or be refused. The records to add are in the Resend dashboard for '.$domain.' and in PHASE-10-EMAIL-DELIVERABILITY.md.');
        }

        return HealthCheck::ok('mail_dns', 'Mail domain: SPF, DKIM, DMARC', 'All three on '.$domain);
    }

    private function smsLive(): HealthCheck
    {
        if (! (bool) config('communications.channels.sms', true) || CommunicationServiceProvider::smsDriver() === 'log') {
            return HealthCheck::warning('sms_live', 'A live SMS delivered', 'SMS driver is "log"',
                'Choose the provider (Settings → Email & SMS) and put its key in .env; then send yourself a test from an SMS template.');
        }

        $delivered = SmsLog::query()->whereIn('status', [SmsLog::STATUS_DELIVERED, SmsLog::STATUS_SENT])->where('driver', '!=', 'log')->exists();

        if (! $delivered) {
            return HealthCheck::critical('sms_live', 'A live SMS delivered', 'None yet',
                'Send a test SMS from Communications → SMS templates → Send me a test, and confirm it arrived on a phone on each network the foundation\'s donors use. The sender ID must be registered with the provider first (PHASE-10-SMS-SENDER-ID.md).');
        }

        return HealthCheck::ok('sms_live', 'A live SMS delivered', 'Yes');
    }

    private function tls(): HealthCheck
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host === '' || in_array($host, ['localhost', '127.0.0.1'], true) || ! str_starts_with((string) config('app.url'), 'https://')) {
            return HealthCheck::warning('tls', 'Certificate and its renewal', 'APP_URL is not an https:// address',
                'Set APP_URL to the live https address; the certificate is checked against it.');
        }

        $cert = $this->certificate($host);

        if ($cert === null) {
            return HealthCheck::warning('tls', 'Certificate and its renewal', 'Could not connect to '.$host,
                'Run this on the server, or check the certificate in the browser: valid, for this host, and cPanel → SSL/TLS Status shows AutoSSL renewing it.');
        }

        $days = (int) floor(((int) ($cert['validTo_time_t'] ?? 0) - time()) / 86400);

        if ($days < 14) {
            return HealthCheck::critical('tls', 'Certificate and its renewal', "Expires in {$days} days",
                'AutoSSL should renew a month before expiry; it has not. cPanel → SSL/TLS Status → Run AutoSSL, and check the domain\'s DNS points at this server.');
        }

        return HealthCheck::ok('tls', 'Certificate and its renewal', "Valid, {$days} days left, issued by ".(string) ($cert['issuer']['O'] ?? 'unknown'));
    }

    private function canonicalHost(): HealthCheck
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if ($host === '') {
            return HealthCheck::critical('canonical_host', 'One canonical host', 'APP_URL empty', 'Set APP_URL.');
        }

        $isWww = str_starts_with($host, 'www.');
        $other = $isWww ? substr($host, 4) : 'www.'.$host;

        return HealthCheck::ok('canonical_host', 'One canonical host', $host.' (redirect '.$other.' → '.$host.' in .htaccess)');
    }

    private function analytics(): HealthCheck
    {
        $provider = (string) setting('analytics.provider', 'none');

        if ($provider === 'none') {
            return HealthCheck::warning('analytics', 'Analytics chosen', 'None — the built-in dashboard only',
                'Fine if that is the decision (PHASE-13-SEO-AND-CONTENT.md §5). If a provider was chosen, set it under Settings → Analytics.');
        }

        return HealthCheck::ok('analytics', 'Analytics chosen', ucfirst($provider));
    }

    /**
     * A flag that is on must have the feature behind it. These are the flags
     * with nothing behind them yet; each is a Phase 18 roadmap item.
     */
    private const UNBUILT_FLAGS = [
        'p2p_fundraising' => 'peer-to-peer fundraising pages (Phase 18)',
        'multilingual' => 'a second language (Phase 18)',
    ];

    /**
     * WhatsApp (Wave 2) is built and off by default; ON means the business
     * is verified with Meta, the credentials are in .env, the driver is
     * `cloud`, and at least one template is approved. Otherwise the box on
     * the donate form promises a receipt that cannot be sent.
     */
    private function whatsapp(): HealthCheck
    {
        if (! app(Features::class)->enabled('whatsapp')) {
            return HealthCheck::ok('whatsapp', 'WhatsApp ready if on', 'Off — nothing promised');
        }

        $missing = [];

        if ((string) config('communications.whatsapp.driver', 'log') !== 'cloud') {
            $missing[] = 'WHATSAPP_DRIVER=cloud';
        }

        foreach (['access_token' => 'WHATSAPP_ACCESS_TOKEN', 'phone_number_id' => 'WHATSAPP_PHONE_NUMBER_ID', 'verify_token' => 'WHATSAPP_WEBHOOK_VERIFY_TOKEN'] as $key => $env) {
            if (blank(config('communications.whatsapp.'.$key))) {
                $missing[] = $env;
            }
        }

        if (blank(config('communications.webhooks.providers.meta.secret'))) {
            $missing[] = 'WHATSAPP_APP_SECRET';
        }

        $approved = WhatsappTemplate::query()->where('is_approved', true)->where('is_active', true)->whereNotNull('meta_name')->count();

        if ($approved === 0) {
            $missing[] = 'an approved template (Communications → WhatsApp templates)';
        }

        if ($missing !== []) {
            return HealthCheck::critical('whatsapp', 'WhatsApp ready if on', 'On, not ready',
                'FEATURE_WHATSAPP is on and the donate form offers WhatsApp receipts, but: '.implode('; ', $missing).'. Set them, or switch the flag off until Meta has approved the business and the templates.');
        }

        return HealthCheck::ok('whatsapp', 'WhatsApp ready if on', $approved.' approved template(s), cloud driver');
    }

    private function flags(): HealthCheck
    {
        $on = array_filter(array_keys(self::UNBUILT_FLAGS), fn (string $flag): bool => app(Features::class)->enabled($flag));

        if ($on !== []) {
            return HealthCheck::critical('flags', 'Feature flags honest', implode(', ', $on).' on',
                'These flags are on with nothing built behind them: '.implode('; ', array_map(fn (string $f): string => "{$f} — ".self::UNBUILT_FLAGS[$f], $on)).'. Set them false (.env or System → Feature flags). A promise the site cannot keep is worse than an absence.');
        }

        return HealthCheck::ok('flags', 'Feature flags honest', 'Nothing on that is not built');
    }

    /**
     * Every table must be InnoDB. The connection config pins it, but a table
     * created by hand in phpMyAdmin, or on a server whose default is MyISAM
     * before the pin existed, would have no transactions and no foreign
     * keys — and a payments ledger on MyISAM is a ledger that can lose a row
     * mid-write without an error. Found on the first staging deploy, where
     * InMotion's MariaDB defaulted to MyISAM.
     */
    private function storageEngine(): HealthCheck
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return HealthCheck::ok('storage_engine', 'Every table on InnoDB', "Not applicable ({$driver})");
        }

        $rows = DB::select('select table_name as name, engine as engine from information_schema.tables where table_schema = database() and table_type = ? and engine <> ?', ['BASE TABLE', 'InnoDB']);
        $wrong = array_map(fn (object $r): string => "{$r->name} ({$r->engine})", $rows);

        if ($wrong !== []) {
            return HealthCheck::critical('storage_engine', 'Every table on InnoDB', count($wrong).' not InnoDB',
                'Tables without transactions or foreign keys: '.implode(', ', $wrong).'. Convert each with ALTER TABLE … ENGINE=InnoDB, then check default_storage_engine on the server — config/database.php pins InnoDB for tables the application creates, but not for tables made by hand.');
        }

        return HealthCheck::ok('storage_engine', 'Every table on InnoDB', 'All InnoDB');
    }

    /**
     * No column may carry ON UPDATE CURRENT_TIMESTAMP — the migrations never
     * ask for it. MariaDB and MySQL 5.7 add it silently to the first NOT NULL
     * timestamp of every table unless explicit_defaults_for_timestamp is on,
     * which the connection now sets per session; a table created before that
     * (or by hand) would have a first_seen_at that changes on every update.
     */
    private function timestampDefaults(): HealthCheck
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return HealthCheck::ok('timestamp_defaults', 'No silent ON UPDATE timestamps', "Not applicable ({$driver})");
        }

        $rows = DB::select('select table_name as name, column_name as col from information_schema.columns where table_schema = database() and lower(extra) like ?', ['%on update%']);
        $wrong = array_map(fn (object $r): string => "{$r->name}.{$r->col}", $rows);

        if ($wrong !== []) {
            return HealthCheck::critical('timestamp_defaults', 'No silent ON UPDATE timestamps', count($wrong).' column(s)',
                'These columns change themselves on every update, which no migration asked for: '.implode(', ', $wrong).'. The table was created under the legacy TIMESTAMP rules (explicit_defaults_for_timestamp off). Recreate it from the migrations, or ALTER TABLE … MODIFY the column without ON UPDATE.');
        }

        return HealthCheck::ok('timestamp_defaults', 'No silent ON UPDATE timestamps', 'None');
    }

    // ── Accounts ─────────────────────────────────────────────────────────────

    private function staffTwoFactor(): HealthCheck
    {
        $without = User::query()
            ->where('type', UserType::Staff)
            ->where('is_active', true)
            ->whereNull('two_factor_confirmed_at')
            ->pluck('email');

        if ($without->isNotEmpty()) {
            return HealthCheck::critical('staff_2fa', 'Two-factor on every staff account', $without->count().' without',
                'The panel forces enrolment at the next sign-in, so these accounts have never signed in: '.$without->implode(', ').'. Have each person sign in once before launch, or suspend the account.');
        }

        return HealthCheck::ok('staff_2fa', 'Two-factor on every staff account', 'All active staff enrolled');
    }

    private function demoAccounts(): HealthCheck
    {
        $demo = User::query()->where('email', 'like', '%@example.test')->orWhere('email', 'like', 'demo.%')->count();

        if ($demo > 0) {
            return HealthCheck::critical('demo_accounts', 'No demo accounts', "{$demo} found",
                'Accounts from the demo seeder (password "password") exist in this database. Delete them (System → Staff accounts) before this database is live.');
        }

        return HealthCheck::ok('demo_accounts', 'No demo accounts', 'None');
    }

    private function demoData(): HealthCheck
    {
        $signals = [
            'donations' => Donation::query()->where('donor_email', 'like', '%@example.test')->count(),
            'orders' => DB::table('orders')->where('customer_email', 'like', 'demo.buyer%')->count(),
            'subscribers' => DB::table('subscribers')->where('email', 'like', 'demo.reader%')->count(),
            // The demo cases are keyed to the demo worker; a real database has no such account.
            'beneficiary cases' => DB::table('beneficiaries')->whereIn('case_worker_id', DB::table('users')->where('email', 'like', 'demo.%@example.test')->select('id'))->count(),
        ];

        $found = array_filter($signals);

        if ($found !== []) {
            return HealthCheck::critical('demo_data', 'No demo data', implode(', ', array_map(fn ($k, $v) => "{$v} {$k}", array_keys($found), $found)),
                'This database has been seeded with DemoDataSeeder. Production starts from an empty database: migrate and run DatabaseSeeder only, never the demo seeder (it refuses in production, but a database copied from staging carries the rows).');
        }

        return HealthCheck::ok('demo_data', 'No demo data', 'None');
    }

    // ── Plumbing ─────────────────────────────────────────────────────────────

    /** @return array<int, string>|null  TXT strings, [] for none, null when DNS could not be asked */
    private function txt(string $name): ?array
    {
        try {
            $records = self::$dns !== null ? (self::$dns)($name, DNS_TXT) : @dns_get_record($name, DNS_TXT);
        } catch (Throwable) {
            return null;
        }

        if ($records === false) {
            return null;
        }

        return array_values(array_map(fn (array $r): string => (string) ($r['txt'] ?? ''), $records));
    }

    /** @return array<string, mixed>|null */
    private function certificate(string $host): ?array
    {
        if (self::$certificate !== null) {
            return (self::$certificate)($host);
        }

        try {
            $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true]]);
            $socket = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $context);

            if ($socket === false) {
                return null;
            }

            $params = stream_context_get_params($socket);
            fclose($socket);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;

            return $cert !== null ? (openssl_x509_parse($cert) ?: null) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function wrap(string $key, string $label, Closure $check): HealthCheck
    {
        try {
            return $check();
        } catch (Throwable $e) {
            return HealthCheck::critical($key, $label, 'Could not check', 'The check itself failed: '.$e->getMessage());
        }
    }
}

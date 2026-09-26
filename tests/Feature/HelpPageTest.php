<?php

declare(strict_types=1);

use App\Filament\Pages\HelpPage;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Database\Seeders\ThemeSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 16 — the manual inside the admin
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    $this->seed(SettingsSeeder::class);
    $this->seed(ThemeSettingsSeeder::class);
    $this->seed(RoleAndPermissionSeeder::class);
});

function staffReader(): User
{
    $user = User::factory()->staff()->withTwoFactor()->create();
    $user->givePermissionTo('admin.access');

    return $user->fresh();
}

it('shows the contents to any signed-in member of staff', function () {
    $this->actingAs(staffReader());

    $this->get(HelpPage::getUrl())
        ->assertOk()
        ->assertSee('The admin manual')
        ->assertSee('Signing in and setting up your authenticator')
        ->assertSee('Quick reference');
});

it('renders a chapter with its screenshots served from behind the sign-in', function () {
    $this->actingAs(staffReader());

    $response = $this->get(HelpPage::getUrl(['chapter' => '05-donations']))->assertOk()
        ->assertSee('Recording a cash, cheque or bank-transfer gift')
        ->assertSee('two people, always');

    $image = route('manual.image', ['file' => '30-donations.png']);
    expect((string) $response->getContent())->toContain($image);

    $this->get($image)->assertOk()->assertHeader('Content-Type', 'image/png');
});

it('links chapters to each other inside the panel, not to GitHub', function () {
    $this->actingAs(staffReader());

    $html = (string) $this->get(HelpPage::getUrl())->getContent();

    expect($html)->toContain(HelpPage::getUrl(['chapter' => '01-signing-in']))
        ->and($html)->not->toContain('01-signing-in.md');
});

it('refuses an unknown chapter name and shows the contents instead', function () {
    $this->actingAs(staffReader());

    $this->get(HelpPage::getUrl(['chapter' => '99-nope']))->assertOk()->assertSee('The admin manual');
    $this->get(HelpPage::getUrl(['chapter' => '..']))->assertOk()->assertSee('The admin manual');
});

it('keeps the screenshots away from visitors and donors', function () {
    $this->get(route('manual.image', ['file' => '30-donations.png']))->assertRedirect();

    $this->actingAs(User::factory()->donor()->create()->fresh());
    $this->get(route('manual.image', ['file' => '30-donations.png']))->assertForbidden();
    $this->get(route('manual.image', ['file' => '../../.env']))->assertNotFound();
});

it('has a chapter for every file in the manual and a picture for every image it mentions', function () {
    $dir = HelpPage::directory();
    $missing = [];

    foreach (glob($dir.'/*.md') as $file) {
        preg_match_all('/\]\(images\/([a-z0-9._-]+)\)/i', (string) file_get_contents($file), $m);
        foreach ($m[1] as $image) {
            if (! is_file($dir.'/images/'.$image)) {
                $missing[] = basename($file).' → '.$image;
            }
        }
    }

    expect($missing)->toBe([])
        ->and(count((new HelpPage)->chapters()))->toBe(14);
});

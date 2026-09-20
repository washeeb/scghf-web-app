<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\BasePolicy;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoDataSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Exceptions\UrlGenerationException;

/*
|--------------------------------------------------------------------------
| The admin panel with a full database behind it
|--------------------------------------------------------------------------
|
| AdminAccessMatrixTest walks every screen with EMPTY tables, which proves
| who may open what and nothing about what the screens do with rows.
| Strict Eloquent only refuses a lazy load once a model came from a
| collection of MORE THAN ONE, so a table that reads a relation in a
| column callback passes with one record and returns 500 with two.
|
| Staging showed exactly that on its first day: Projects, Appeals and News
| each 500 on their list page (a description() reading a relation), and a
| contact-message form queried a column that does not exist. None of it
| could fail with an empty table. So: seed the demo foundation — the same
| dataset testers use — and open every list page and every custom page as
| a Super Admin.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DemoDataSeeder::class);

    BasePolicy::forgetKnownPermissions();
});

it('renders every admin list page and custom page over the demo dataset', function () {
    $admin = User::factory()->staff()->withTwoFactor()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin->fresh());

    $failures = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $pages = $resource::getPages();
        $key = array_key_exists('index', $pages) ? 'index' : array_key_first($pages);

        try {
            $url = $resource::getUrl($key);
        } catch (UrlGenerationException) {
            continue;
        }

        $status = $this->get($url)->status();

        if ($status !== 200) {
            $failures[] = "{$resource} ({$url}): {$status}";
        }
    }

    foreach (Filament::getPanel('admin')->getPages() as $page) {
        if (is_a($page, Dashboard::class, true)) {
            continue;
        }

        $status = $this->get($page::getUrl())->status();

        if ($status !== 200) {
            $failures[] = "{$page}: {$status}";
        }
    }

    expect($failures)->toBe([]);
});

it('renders the first record of every resource that has a view or edit page', function () {
    $admin = User::factory()->staff()->withTwoFactor()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin->fresh());

    $failures = [];
    $opened = 0;

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        $pages = $resource::getPages();

        // The first record with a route key that can sit in a URL: the
        // homepage's slug is '' by design and would make the URL collapse.
        $keyName = $resource::getRecordRouteKeyName() ?? (new ($resource::getModel()))->getRouteKeyName();
        $record = $resource::getModel()::query()->where($keyName, '!=', '')->whereNotNull($keyName)->first();

        if ($record === null) {
            continue;
        }

        foreach (['view', 'edit'] as $key) {
            if (! array_key_exists($key, $pages)) {
                continue;
            }

            try {
                // The key's value, not the model: given a model Filament asks it for
                // getRouteKey(), which for a Page is the public path, not the ULID
                // the resource routes by.
                $url = $resource::getUrl($key, ['record' => $record->getAttribute($keyName)]);
            } catch (UrlGenerationException) {
                continue;
            }

            $status = $this->get($url)->status();
            $opened++;

            // 403 is a policy saying no to this record (a case the Super Admin
            // may only read, a settled payout); that is a decision, not a fault.
            if (! in_array($status, [200, 403], true)) {
                $failures[] = "{$resource} {$key} ({$url}): {$status}";
            }
        }
    }

    expect($failures)->toBe([])
        ->and($opened)->toBeGreaterThan(20);
});

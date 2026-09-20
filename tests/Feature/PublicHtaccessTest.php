<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| public/.htaccess
|--------------------------------------------------------------------------
|
| Apache reads this file on the server and nothing in the test suite does,
| so a rule that breaks the site there passes every test here. These pin the
| lines that have bitten: the probe-path block that forbade /storage — the
| media library's public path — from the first commit, unnoticed until the
| first person looked at staging with pictures on it.
*/

it('does not forbid the public media path', function () {
    $htaccess = (string) file_get_contents(public_path('.htaccess'));

    // The link Laravel's storage:link creates, and every <img> on the site.
    expect(preg_match('/RewriteRule\s+\^\([^)]*\bstorage\b[^)]*\)/', $htaccess))->toBe(0)
        ->and(preg_match('/RewriteRule\s+\^storage/', $htaccess))->toBe(0);
});

it('still blocks the paths that were meant to be blocked', function () {
    $htaccess = (string) file_get_contents(public_path('.htaccess'));

    expect($htaccess)->toContain('RewriteRule ^(vendor|node_modules|bootstrap)(/|$) - [F,L]')
        ->toContain('wp-login\.php');
});

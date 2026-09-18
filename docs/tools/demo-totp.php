<?php

use App\Models\User;

/*
 * Run through tinker by docs/tools/screenshots.mjs:
 *
 *     php artisan tinker docs/tools/demo-totp.php
 *
 * Gives the demo Super Admin (DemoDataSeeder) a known TOTP secret so a
 * script can complete the real two-factor sign-in. Demo data only: the
 * account does not exist on production and the seeder refuses to run there.
 */

$user = User::where('email', 'demo.superadmin@example.test')->firstOrFail();
$user->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
$user->forceFill(['two_factor_recovery_codes' => ['aaaaa-bbbbb']])->save();
echo "ok\n";

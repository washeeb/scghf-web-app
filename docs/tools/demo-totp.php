<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

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
// Hashed, as Filament stores them: it Hash::check()s a submitted code, and a
// plain string there is a 500 ("does not use the Bcrypt algorithm"), not a
// failed login. The code itself is aaaaa-bbbbb.
$user->saveAppAuthenticationRecoveryCodes([Hash::make('aaaaa-bbbbb')]);
echo "ok\n";

# Contributing

## Before anything

Read `CLAUDE.md`. It is the standing context — the decisions already made
and the rules that are not negotiable (money is integer pesewas; payment
truth is the webhook; no content in Blade; flagged means built). Then
`docs/ARCHITECTURE.md` for the map.

## Setup

`README.md` → *Local setup*. You need PHP 8.4, Composer 2, Node 20+, MySQL 8
with a `scghf_dev` and a `scghf_test` database. `php artisan db:seed
--class=DemoDataSeeder` gives you a site with something on it.

## The loop

1. Branch from `develop`: `feat/<what>`, `fix/<what>`, `docs/<what>`.
2. Write the test first when it is money, auth or a webhook; always write
   the test that fails without your change.
3. `vendor/bin/pint` and `vendor/bin/phpstan analyse` before you push. A
   new PHPStan error is fixed, not baselined.
4. Commit in the format in `.gitmessage` (`type(scope): subject`, why in
   the body). `git config commit.template .gitmessage` once.
5. Open a PR against `develop` with the template filled in. CI must be
   green (lint/analyse/test, coverage, browser tests).
6. Merging `develop` deploys staging; merging `develop → main` deploys
   production (`docs/DEPLOYMENT.md`).

## Rules that trip people

- **A new `.env` key** goes in `.env.example` with a comment, and is read
  by something. `python docs/tools/env_reference.py` cross-checks.
- **A new public content model** goes on the `SiteCacheObserver` list in
  `AppServiceProvider`, or its edits will not reach the cached site.
- **A new personal or transactional route** goes in
  `config/performance.php` `page_cache.except`.
- **A new permission** is seeded with the screen it protects; a feature
  flag is off until the feature exists.
- **A migration** is expand-only (add; never drop or rename in the same
  release) — the live release runs against the new schema for a few seconds.
- **Nothing cached is an object.** Arrays and scalars.
- **Money**: `Money::ofMinor()`, `toEqualPesewas()` in tests, `currency`
  on every gateway call.
- **Content**: if you are typing a phone number, an address or a paragraph
  into Blade, stop and put it in the CMS with a seeded default.
- **Tests**: helpers are global across files — prefix them.

## Reporting

Bugs: the issue templates. Security: `SECURITY.md`, never an issue.

## Documentation

`CHANGELOG.md` for every user-visible change, `README.md` when setup or
commands change, `docs/manual/` when a screen staff use changes.
`docs/DATABASE.md` and `docs/ENVIRONMENT.md` are generated
(`docs/tools/`); regenerate them, do not edit them.

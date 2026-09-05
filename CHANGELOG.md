# Changelog

All notable changes to this project are recorded here.
Format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versions are phase-based until launch, then [SemVer](https://semver.org/).

---

## [Unreleased]

### Phase 5 — Block rendering and preview — 2026-09-05

Module 2 of 5. The page builder from this morning can now be seen: twenty block
views, a public route, and a preview for drafts.

#### Added

**Twenty block views**
- One for every key in `BlockRegistry`, with a test that fails if the registry
  ever gains a block the site cannot draw — a block an editor can place and
  nothing renders is a CMS that looks broken with no error anywhere
- A shared `x-blocks.section` wrapper owns the framing, so no block builds its
  own background or spacing classes and a change to the scale moves the whole
  site at once
- Every block heading is an `h2`. The page title is the `h1`, and a block
  emitting a second one breaks the outline a screen reader navigates by — the
  easiest accessibility mistake to make in a CMS with twenty block types

**Decisions inside the blocks worth knowing**
- **The hero takes a separate mobile crop.** A 1600px hero scaled down by the
  browser is the largest single cost of a first paint on 3G, which is most of
  what the LCP budget is spent on. `<picture>` chooses before anything downloads
- **The video block does not embed a player.** An embedded iframe pulls roughly
  a megabyte of JavaScript and sets third-party cookies before anybody presses
  play — a real cost imposed on every visitor for a video most will not watch,
  and a consent question the site would then have to answer. The poster is a
  link
- **The testimonials block renders a list, not a carousel.** A carousel needs
  JavaScript to show anything past the first slide, hides its content from
  search engines, and auto-advances past people who read slowly
- **The FAQ block is `<details>`**, like every other disclosure here — readable
  with no JavaScript, and by a search engine
- **Impact figures go through `publishedTotal()`**, never `total()`. That is the
  method carrying the disclosure control, so a metric counting people below the
  minimum group size is withheld rather than published — the foundation's
  categories include health, orphan status and widowhood, and "3 widows
  supported in Bongo" identifies them
- Each figure carries the date it was measured. An unsourced statistic on a
  fundraising site is a trust risk
- **The donation block is a link, not a form.** The real form is Phase 6, and a
  donation form that looks real and does nothing is worse than a link — somebody
  will type their card details into it

**`BlockDataResolver`**
- Every query a block needs, in one file, each limited and eager-loaded. A
  `@foreach (Cause::live()->get())` in a template is untestable, invisible to
  anybody auditing what a page costs, and the usual route to an N+1 that only
  appears once the site has real content
- An editor's `limit` is clamped. A block asking for ten thousand causes is a
  page that times out and an editor with no idea why
- A failure returns empty rather than throwing: a block whose data will not load
  should be an absent block, not a 500 on a donation page

**The public route, and preview**
- `/{path}` resolves by the materialised `path` column — one lookup, no walk
  down the tree — and is **registered last on purpose**, since `.*` would
  otherwise swallow `/login` and everything else. There is a test for that
- A draft is a **404, not a 403**. A 403 confirms something is there, which is
  what an unannounced appeal must not do
- Preview is **signed AND staff-only**. A signed URL is still a string somebody
  can paste into a chat, so the signature proves the link came from the panel
  and the policy check proves the person opening it is entitled to
- The preview banner says plainly whether the page is published, so nobody
  wonders why a campaign nobody could see got no response
- A page with no blocks renders its title rather than a blank response, which
  would look like a server fault to a visitor and a deleted page to its author

#### Fixed

- **A malformed JSON setting took down every page that read it.** `SettingType`
  cast JSON with `JSON_THROW_ON_ERROR`, and `cast()` runs on every setting when
  the repository loads — once per request, on every page. One unparseable value
  anywhere in the table was a site-wide 500, including the donation page, since
  `donations.presets` is JSON. Found the honest way: an unfilled
  `{{PLACEHOLDER}}` is not valid JSON, and `Settings::get()`'s placeholder check
  never got the chance to run because the cast threw first. It now returns null,
  which `get()` turns into the caller's default — while the raw value stays in
  the column for `Settings::unfilled()` to report
- The layout honoured the site-wide noindex switch but not a page's own
  `no_index`. A thank-you page could not be kept out of search results
- The preview route bound `{page}` by `path`, which contains slashes. Now
  `{page:ulid}`

### Phase 5 — The page builder — 2026-09-05

The first of five modules in the CMS phase. Phase 3 had already built the
schema — `pages`, `page_sections`, `page_revisions` and a curated
`BlockRegistry` of twenty block types — so this is the screen the foundation
runs the website from, on top of it.

#### Added

**The builder**
- `PageResource`: title, address, summary, status and scheduling, parent page,
  sitemap and search inclusion, and the SEO fields on their own tab. Three tabs
  in the order somebody works — content first, search last, because search is
  the part nobody opens until launch
- Blocks as a drag-orderable, collapsible, duplicable list mapped to
  `page_sections` rows. Rows rather than a JSON column, because a row has an id
  and an id is what lets a revision restore put the right content back in the
  right place
- **The block fields are generated, not written.** `BlockFieldFactory` turns a
  `BlockDefinition`'s field list into Filament components — the same list that
  already generates the block's validation rules. A block added to the registry
  appears in the panel with no admin work, and the form and the validator cannot
  disagree
- The collapsed label says which block it is. A page of eight rows all reading
  "Section" is a page somebody opens eight times to find anything
- The image picker offers **only publishable media**, with helper text
  explaining why one might be missing. `Media::isPublishable()` refuses anything
  without alt text or with its camera metadata still on it, and the image
  component refuses it too — so blocking it at the point of choosing is the only
  place with room to say why

**Per-block presentation, as a closed vocabulary**
- `settings` on `page_sections`, and `SectionSettings` to read it: background,
  padding, width, alignment, a dark variant, and which screen sizes it shows on
- Every option is a fixed list resolving to a theme token or a spacing step.
  There is no colour picker and no pixel field — a colour picker produces pages
  that fail WCAG contrast the moment somebody chooses a light green, and a pixel
  field produces a site with fourteen different spacings
- **Nothing stored reaches a class attribute.** `SectionSettings` *looks up* the
  stored value in a map and returns the class from the map, so a `settings`
  column edited by hand can only ever produce a class the file already contains
- The admin's options are generated from the same constants the renderer reads,
  so a background offered in the panel is by construction one the renderer knows
  how to draw
- Hiding a block on phones is documented, in the field's own helper text, as
  visual emphasis only — it is `display: none`, still read by screen readers and
  still indexed, and must never be used to give different people different
  information

**Revisions with restore**
- A snapshot is taken **before every save**, not after. One taken after records
  a change that already happened — useful for an audit, useless for undoing
  anything
- Restoring is itself snapshotted, so restoring the wrong version is recoverable
- A "History" action lists the last twenty versions with who saved each and when

**Visible at a glance**
- The navigation badge counts **published pages with no blocks** — a heading
  over white space, the commonest way a CMS goes live looking broken, and
  invisible in a list that only shows a status. There is a filter for them too
- "View" appears only on a page that is actually live, because a view button
  leading to a 404 teaches people the button is broken rather than that the page
  is a draft

#### Fixed

- **`Page::snapshot()` omitted three columns an editor can set** — `settings`,
  `visible_from` and `visible_until`. A snapshot that omits a column is a
  restore that silently clears it: the page comes back looking restored and the
  missing part is noticed only by whoever set it. The presentation settings
  would have been reset to site defaults on the first restore anybody performed
- The block-type selector was disabled after the first save, to stop `data` from
  being left shaped for the wrong block. The reasoning was right and the
  mechanism was wrong: Filament omits disabled fields from the submitted state,
  so **no block could be added from the edit screen at all** — every new row
  arrived with no type and failed on insert. The mismatch is now prevented in
  `PageSection` itself, which resets `data` to the new block's defaults when the
  type changes, so it also holds for a seeder or an import
- `PageResource` resolves records by ULID. `Page::getRouteKeyName()` is `path`,
  so that a public URL is one lookup — and a path contains slashes, which cannot
  be a single admin route segment
- Added `PageFactory`, which did not exist. Draft by default, because that is
  the state a page is really created in — a factory handing out published pages
  lets `isLive()` and everything gated on it rot untested

### Phase 4 — Open questions 16 and 17, answered — 2026-09-05

Both were left open because in each case the convenient version is the dangerous
one. Both are now built.

#### Added

**Changing an email address, in three steps**
- The **current password**, because a session left open on a shared computer is
  not consent
- A confirmation link the **new address** must open — and until it does, `email`
  is untouched. An attacker with a stolen session who gets this far has changed
  nothing
- A warning to the **old address**, sent on the *request* rather than on the
  completion, carrying a cancel link. This is the control rather than a
  courtesy: it is the one moment the account holder can stop a takeover, and it
  reaches the inbox they still control
- Cancelling **ends every session**, because somebody had to be signed in to ask
  — if the owner says it was not them, whoever did it is still there and would
  simply ask again
- Neither link needs a sign-in. The confirmation is opened from a different
  device as often as not; the cancellation is for somebody who may be locked out
  of their own session, which is the entire situation it exists for
- The **giving history is deliberately not re-matched**. `claimDonorRecord()`
  matches an unclaimed donor by email and is not called on a change — otherwise
  moving your account to an address that happens to belong to an existing donor
  record would hand you that person's whole giving history
- `donors.email` is left alone too: it is the address given at the time of a
  gift, attached to financial records with a six-year statutory life. It records
  what happened, not where to write today

**Two-factor authentication for donors — optional, and offered**
- Enrolment on the security page: QR code plus a typed key, because the
  commonest way to do this is on the phone you are reading the page on, and you
  cannot scan your own screen
- **The secret never reaches the database unproved.** It waits in the session
  until a code generated from it verifies, so a populated `two_factor_secret`
  always means a factor the person can actually produce — there is no
  half-enrolled row that would lock an account out of itself
- **The account is never authenticated while the challenge is on screen.** What
  exists is an id in the session saying who is halfway through, re-read from the
  database each time so a suspension mid-challenge is not bypassed. A factor
  somebody can skip by closing the tab is a suggestion
- Rate limited on **account + IP**, the same pair the login form uses. Six digits
  is a million guesses to somebody who already has the password
- Single-use recovery codes, shown exactly once, with the remaining count on the
  security page — nobody notices they are down to their last one until the day
  they need the second
- The current password is required to turn it on *as well as* off. Adding a
  factor to somebody else's account locks them out of it just as effectively as
  removing one lets an attacker in
- `UserType::requiresTwoFactor()` is unchanged: mandatory for staff, optional
  for donors, per Blueprint §7.1

**Four new emails, and two of them nobody asks for**
- `account.email_change_confirm`, `account.email_change_alert`,
  `account.two_factor_enabled`, `account.two_factor_disabled`
- The two **alerts** are the point. A change of address and a second factor
  switched off are exactly what an attacker does once inside, and both are
  silent everywhere else in the system

#### Fixed

- **`LoginOutcome::TwoFactorFailed` had existed since Module 1 with nothing
  writing it.** A new `TwoFactorChallengeFailed` event now records it — Laravel
  has no event for "right password, wrong second factor", and filing it under
  `Failed` would say the credentials did not match, which is a different and far
  less interesting fact
- `auth.two_factor_disabled` had been declared in `config/system.php` since
  Module 8 with nothing recording it. It is recorded now, and
  `auth.two_factor_enabled` is added beside it
- The two-factor throttle was first keyed on the **session id**, which is wrong
  for a reason worth keeping: a session id is not stable, and authentication
  regenerates it deliberately — so a limit keyed on it resets whenever the thing
  it protects makes progress. A control that can be shed by discarding a cookie
  is not a control

### Phase 4 — The navigation, at both widths — 2026-09-05

The mobile menu, and the desktop dropdowns it turned out could not be built
without. Phase 4 is complete.

#### Added

**The mobile menu**
- An **expanding panel below the header, not a full-screen overlay.** A
  slide-over covering the page is a modal, and a modal owes the visitor a focus
  trap, `inert` on everything behind it, a scroll lock and a way out that is not
  the back button — four things to get wrong, each of which strands a keyboard
  or screen-reader user inside a menu. An in-flow disclosure owes none of them.
  With seven top-level items that is not a compromise; it is the simpler thing
  that is also the more correct one
- Built from **`<details>`, so it works with JavaScript switched off**. The
  browser already implements disclosure: click and Enter open it, expanded state
  is announced with no ARIA from us, and no script is needed. That matters
  specifically here — these visitors are on low-end Android phones, sometimes
  behind data-saver proxies that rewrite or drop scripts, and a navigation that
  needs JavaScript is a site they cannot move around
- `navigation.js` adds Escape (returning focus to the control that opened it),
  click-away, focus-away, and closing when the viewport crosses the breakpoint.
  Every one of those is a **convenience that is absent rather than broken** if
  the file never arrives — which is the test to apply to anything added to it
- The donate button stays in the header at every width, never inside the
  hamburger. It is the most important control on the site and hiding it behind a
  tap on a phone is hiding it from most of this foundation's donors
- Signing in moves into the panel below `md`, so the corner a thumb reaches
  first belongs to donating

**Desktop dropdowns**
- Top-level items with children now render as dropdowns, **click-activated, not
  hover**. A hover menu is unusable with a finger, hostile to anybody whose
  hands are not steady, and invisible to a keyboard without a pile of ARIA to
  compensate
- The parent's own page is the first entry inside its dropdown, so a parent that
  is both a page and a group does not become a pure toggle — `/about` exists and
  is published, and it was about to be reachable from nowhere

#### Fixed

- **Ten seeded menu items rendered nowhere, at any width.** The header menu has
  children under "About" and "Get Involved", `max_depth 1`, and a description
  that promises "one level of dropdown" — and the header rendered only the top
  level. Published pages, linked from the navigation table, reachable from
  neither the desktop row nor a phone. This is why the mobile menu could not be
  built on its own: rendering children on a phone and not on a laptop would have
  been worse than rendering them nowhere
- `x-site.menu-link` now merges classes passed to it instead of discarding them,
  which is what let one component serve both renderings

#### Changed

- **axios is gone, and with it 96% of the site's JavaScript.** Nothing
  referenced it — it was Laravel's default scaffolding putting a HTTP client on
  `window` for a site that makes no requests of its own. It was also the bulk of
  what every visitor downloaded before anything they came for. **52.41 kB →
  1.76 kB** (19.87 → 0.72 kB gzipped). Livewire and Filament carry their own
  request layer; `fetch` is in every browser this site supports
- The footer's duplicate account link is removed. It existed because the header
  hid signing-in below `sm` while the mobile menu was outstanding, and the
  comment saying so would now be describing something that is no longer true
- `summary` markers are hidden in `app.css` — two selectors, because
  `list-style` covers Firefox and modern Chrome while
  `::-webkit-details-marker` is what older WebKit still reads, which is Safari
  on the iOS versions this foundation's donors are actually running

### Phase 4 — The media library UI — 2026-09-05

The first Filament resource in the project, sitting on the engine from earlier
today. Phase 4's media library is now complete.

#### Added

- **The library screen**: thumbnail grid, folder filter, search, and a **"Cannot
  be published" filter** — because the job that brings somebody here is "why
  will this image not go on the page", and a library that makes you open forty
  files to find the blocked one is a library people work around
- The navigation badge counts blocked files, not total files. It is the only
  part of the screen visible without opening it, so it carries the thing that
  needs a person. Zero shows nothing — a permanent badge is one people stop
  reading
- **No Create page.** You do not create a media row; you upload a file and a row
  is what happens next. A create form would let somebody produce a `media`
  record pointing at nothing on disk, bypassing the only door in. Uploading is a
  header action that hands files to `MediaLibrary::add()`
- Alt text is **required unless the image is marked decorative**, enforced in
  the form rather than discovered later. `isPublishable()` already refuses a
  file without it, so a form that allowed it would produce files nobody can use
  and the person would find out on a different screen with no explanation
- A read-only status panel: publishable or why not, when metadata was removed,
  whether the file arrived carrying a location, which sizes exist, and how many
  files it costs on disk. None of it editable — whether an image has been
  sanitised is a fact about the bytes, and a form field would invite somebody to
  assert it instead
- **Replace file**, as a first-class action, with the count of affected records
  in the confirmation. Delete-and-re-upload leaves thirty references pointing at
  a row that no longer exists
- Deleting shows what is using the file *before* asking, and turns the model's
  refusal into a sentence rather than a 500 — a red error page would teach
  people the library is broken rather than careful

#### Fixed

- **`media.upload` granted nothing.** `BasePolicy` resolves `create` through
  create → update → manage and `update` through update → manage; the seeded
  permission is `media.upload`, which appears in neither chain. Three roles held
  it, the policy denied every one of them, and the library was unusable by
  everybody while looking correctly permissioned in the seeder. `MediaPolicy`
  now maps both onto `media.upload`
- **`User::hasPermissionTo()` threw on a partially loaded model.** It read
  `is_active` and `suspended_at` directly, so any `User::select('id', 'name')`
  followed by any permission check — a `@can` in a template, a Filament resource
  asking `canViewAny` — was a 500 with strict mode on, and *silently denied* in
  production where strict mode is off and `! null` is true. Two different wrong
  behaviours from one line. It now loads the two columns when they are absent
  rather than guessing in either direction: assuming good standing would let a
  suspended administrator keep their permissions, and assuming the opposite
  would deny a legitimate person with no explanation anybody could find. One
  query, only when needed, never on a real request
- `isInGoodStanding()` is now the single expression of "active and not
  suspended". The `Gate::before` in `AuthServiceProvider` was reading the same
  two columns raw and had the same fragility
- The test suite's memory limit is raised to 512M, with the reason recorded: the
  whole suite runs in one process and accumulates twelve hundred tests before it
  reaches the image conversions, which is not the application's shape. The
  production ceiling is enforced by `UploadPolicy`, which refuses an image whose
  bitmap would need more than 60% of the server's real `memory_limit`
- Conversions skip the optimiser chain when the server cannot launch external
  binaries. With `exec` disabled — common on shared hosting — spatie's
  optimisers read every file into memory, shell out to a binary that cannot
  start, and report success having done nothing

### Phase 4 — The media library engine — 2026-09-05

The upload path, the conversions, and the guard that stops a file being deleted
out from under the thirty things using it. The Filament UI sits on top of this
and lands separately.

#### Added

**Uploads are judged by their bytes**
- `UploadPolicy` sniffs the type with finfo and cross-checks it against the
  extension **in both directions**. A `.jpg` whose contents are PHP is refused;
  so is a genuine JPEG named `.php`, because the extension is what a
  misconfigured server dispatches on
- The browser's `Content-Type` is never consulted anywhere. It is a claim made
  by whatever did the uploading, and whatever did the uploading is not always a
  browser
- **SVG is refused outright.** It is XML that can contain `<script>`, served
  from the same origin as the admin panel — an uploaded one is stored XSS
  holding the session of whoever opens it. Sanitising SVG reliably is a game
  played forever against new parser quirks
- Filenames are transliterated, stripped, and **collapsed to a single dot**:
  `invoice.php.jpg` lands as `invoice-php.jpg`, because mod_mime can dispatch on
  any extension in the chain and this deploys to hosting whose configuration is
  not ours to audit. The extension comes from the sniffed type, never the upload
- Decompression bombs refused: a 64,000² PNG of one flat colour is a few
  kilobytes on disk and 16GB as a bitmap, so it passes a size limit and then
  takes the PHP process down
- `AcceptableUpload` exposes the same policy as a validation rule, so a form
  cannot get a different answer from the library

**Conversions, sized against the layout and the inode quota**
- thumb 320 / card 800 / hero 1600, WebP, queued
- **Three widths and no more.** Each conversion is a *file*, and files are the
  scarce resource here — the inode quota is what a media library exhausts first,
  long before the disk quota. Four files per image; a second output format would
  make it seven
- Never upscaled. A 500px original gets a thumb and nothing else — an 1600px
  "hero" cut from it is a bigger file that looks worse
- Animated GIFs keep their original only, rather than being silently converted
  into a picture of their first frame
- AVIF is **built and off**. Encoding costs seconds rather than milliseconds per
  image on a shared CPU, which on a bulk upload is a request that times out
  halfway through

**An unsanitised image gets no derivatives at all**
- The safeguarding rule, and the reason `HasLibraryMedia` exists. `isPublishable()`
  already refuses the original — but conversions live at derivable paths on a
  public disk, so generating them would put three more copies of a photograph
  still carrying a child's home coordinates where a URL can reach them
- Spatie fires `MediaHasBeenAddedEvent` *before* `createDerivedFiles()`, so in
  the normal case conversions are already cut from a cleaned original. This
  covers the case where the cleaning failed
- `scghf:regenerate-media-conversions` rebuilds them once the cause is fixed;
  dry by default, because rebuilding two thousand images is hours of CPU on an
  account that is sharing it

**Usage tracking, as a refusal rather than a display**
- `MediaUsage` reads the foreign keys pointing at `media` out of
  `information_schema` — **34 of them across 30 tables**, under a dozen column
  names (`media_id`, `image_id`, `photo_id`, `logo_id`, `cover_id`,
  `featured_image_id`, `og_image_id`, `photograph_id`, `evidence_media_id`,
  `pdf_media_id`, `cv_media_id`, `id_document_id`, `signature_id`, `document_id`)
- Discovered rather than declared, and this is the one registry in the project
  where that is the safer choice: **32 of the 34 are ON DELETE SET NULL**, so
  deleting an in-use file today does not fail and does not warn. A donation
  receipt loses its PDF. A beneficiary loses their ID document. A consent record
  loses the evidence it is evidence of — and still reads, to anybody auditing it
  later, as a consent that has evidence
- `Media::deleting()` refuses, in the model, so it holds for a console command
  and a cleanup script as well as for a button. There is deliberately no override
- A failure to answer counts as "in use". A wrong *no* silently detaches a
  receipt from its PDF; a wrong *yes* tells somebody to try again
- Confidential uses (beneficiaries, consents, safeguarding, volunteer
  applications) are counted but not named unless the asker holds
  `beneficiaries.view` — "this file is the evidence for Ama Mensah's consent"
  names a beneficiary to whoever is browsing a photo library

**Replace, keeping the id**
- `MediaLibrary::replace()` swaps the file behind a row. Delete-and-re-upload
  would leave thirty references pointing at the old row, and — those keys being
  SET NULL — some of them pointing at nothing
- Refuses to replace an image with a document, because thirty places expect an
  `<img>`
- Clears `metadata_stripped_at`, so the new file cannot inherit a clean bill of
  health issued for a different image
- Audited as `media.replaced`: nothing else records it, because every reference
  is unchanged while what a consent's evidence *shows* is now different

**Detect and degrade gracefully, for real**
- `ImageToolchain` asks the server rather than reading a belief out of config:
  Imagick or GD, WebP, AVIF, which optimiser binaries exist, and the PHP limits
  that are the actual ceiling
- Catches the quiet one: **`exec()` disabled**, which is common on shared
  hosting and makes spatie's optimisers fail *silently* — the chain runs, every
  binary fails to launch, and the library reports success having optimised
  nothing
- `IMAGE_DRIVER=auto` picks Imagick where the account has it. Imagick does not
  hold the decompressed bitmap inside PHP's `memory_limit` the way GD does,
  which on a 128MB shared account is the difference between a 6000px photograph
  converting and a white screen
- `scghf:media-doctor` reports all of it plus the inode budget, and exits
  non-zero when something needs attention. **Run it on the server** — every
  answer differs from the laptop's

**`x-media.image`**
- Renders `<picture>`-quality output from one tag: srcset across the generated
  widths only, `width`/`height` so the page does not reflow as images land (a
  Core Web Vitals budget this project has committed to), `loading="lazy"` for
  everything but the one image above the fold
- Refuses to render an unpublishable file at all, rather than trusting the page
  template that called it

#### Fixed

- **`MEDIA_MAX_UPLOAD_MB` was documented since Phase 2 and read by nothing.**
  Replaced by `MEDIA_MAX_IMAGE_MB` and `MEDIA_MAX_DOCUMENT_MB`, which are read —
  a photograph and a policy PDF are not the same size

### Phase 4 — Public donor accounts — 2026-09-04

Registration, sign-in, verification and password reset for donors — as full page
form posts through plain controllers rather than Livewire components. This is
the part of the site that has to work on a five-year-old Android phone on a 2G
fallback, and there is nothing here a form post does not do well.

**Nothing on this site requires an account.** A donor can give, get a receipt
and never come back, which is the majority path and the reason `donors` is a
separate table from `users`. An account exists to look at your own giving
afterwards. If registration ever appears between a donor and the payment button,
it is in the wrong place.

#### Added

**The front door**
- Register, sign in, sign out, confirm email, forgotten password, reset password
- An account area: overview with giving totals and recent gifts, editable
  details and communication preferences, and a security page
- `PasswordPolicy` — one policy in one place, twelve characters minimum and a
  Have I Been Pwned breach check by k-anonymity. **No character-class rules**:
  composition requirements produce `Password1!` on every site the person uses,
  and NIST SP 800-63B has preferred length over composition since 2017
- Honeypot on registration. A hidden field and a minimum fill time stops the
  volume bots without putting a CAPTCHA in front of the people least able to get
  past one

**Staff cannot sign in at the public form**
- The security decision in this module. Two-factor is mandatory for staff and it
  is enforced *inside Filament's login flow* — so a public form that
  authenticated a staff account would produce a fully authenticated session
  having shown one factor, and `canAccessPanel()` would then let it walk into
  the admin panel past the check with nothing visibly wrong
- Staff with the right password are redirected to the panel; staff without it
  get the same generic failure as anybody else, because "this is a staff
  account" is information about an address
- `LoginRequest::authenticate()` also constrains the attempt to donor accounts,
  so a change in the controller cannot quietly reopen the path
- Password reset excludes staff for the same reason

**The rate limits config/security.php has described since Phase 2**
- All of them were read by nothing. The registration form had no limit at all,
  and the comment explaining how carefully the numbers were chosen was
  describing something that did not exist
- Login is limited on **two keys at once**: address + IP narrowly, IP alone five
  times looser. On the address alone, anybody could lock a named donor out of
  their own account at no cost — a denial of service that looks exactly like the
  control working. On the IP alone, one shared mobile-network gateway is one
  bucket for thousands of people
- Throttled in `LoginRequest` rather than by middleware, because `throttle`
  cannot fire `Illuminate\Auth\Events\Lockout` — which is what puts the lockout
  in the login history and the audit trail — and applies a one-minute decay
  rather than the fifteen `lockout_seconds` sets

**Every account email goes through MessageDispatcher**
- `User::sendEmailVerificationNotification()` and `sendPasswordResetNotification()`
  are overridden. Laravel's defaults post straight to the mail channel, which
  would be a second way out of this application — one with no suppression check,
  no `email_logs` row and no share of the host's hourly cap
- Four new locked templates: `account.verify_email`, `account.password_reset`,
  `account.password_changed`, `account.new_device`
- They send immediately rather than through the outbox. A person is looking at
  their inbox right now, and a link that arrives ninety seconds later is a donor
  who has closed the tab. The hourly cap is still respected — `SendThrottle`
  counts rows in `email_logs`, and these write one
- **A password change emails the account holder**, whether it came from a reset
  link or from the security page. Nobody asks for that email, and it is the only
  thing that turns a silent account takeover into one the owner finds out about
- **A sign-in from an unrecognised device emails them too** — but never on the
  first sign-in of a new account, when every device is new and the alert would
  mean nothing. An alert that fires when it cannot mean anything is one people
  learn to dismiss, including on the day it matters

**Verification is what earns the giving history**
- `donors` is matched on email address. Attaching the record at registration
  would let anybody who types a known donor's address read what that person has
  given, and to what — so `User::claimDonorRecord()` refuses to run before
  `hasVerifiedEmail()`, and the dashboard is behind `verified`
- A record already claimed by another account is left alone. Two accounts on one
  address is a merge decision for staff, not something to resolve silently
  inside a request
- The security page is deliberately **not** behind `verified`: somebody who
  suspects the account was created by somebody else needs to change the
  password, and that must not require verifying an address they may not control

**Unticking a marketing box does something**
- The profile form writes both the column the campaign builder reads **and** a
  `marketing`-scope suppression, which is what `MessageDispatcher` checks on
  every message. Writing only the column would mean the box shows unticked and
  the next appeal goes out anyway
- Scope `marketing`, never `all` — an unsubscribe stops appeals, not receipts
- Ticking it back on releases **only** an unsubscribe. A hard bounce or a spam
  complaint on the same address stays: releasing a hard bounce because somebody
  ticked a checkbox sends mail to a mailbox that does not exist, and the damage
  lands on everybody else's receipts

**`login_histories` finally has a reader**
- The table has held sign-in records since Module 1 and nothing showed them to
  the person they are about. The account security page lists the last twenty
  attempts including the failures — a run of failures followed by one success is
  the shape of a password that was eventually guessed, and it is invisible if
  only successes are listed

#### Fixed

- **Every seeded email template was signing off "With gratitude," and every
  receipt subject read "Your donation to  — SCGHF-R…".** Three template globals
  in `config/communications.php` pointed at settings keys that do not exist —
  `general.site_name`, `general.site_url` and `organisation.legal_name`. An
  unresolved global collapses to an empty string rather than leaving a visible
  `{{token}}`, so the failure was silent by design and invisible in review
- `ProfileUpdateRequest` no longer clears the phone number when the field is
  absent from a request. A field that was not submitted is not a field that was
  cleared, and for donors who mostly pay by Mobile Money the number is the
  contact detail that matters most

#### Changed

- `bootstrap/app.php` sends already-signed-in visitors to `/account` rather than
  Laravel's default `/` — somebody who clicks a stale "Sign in" link and lands
  on the front page reasonably concludes the click did nothing
- Two new audit events, `auth.account_created` and `auth.password_changed`. Both
  are separate from the events they resemble because they are different facts: a
  reset is somebody who could not get in, a change is somebody who was already
  in, and the second one unexpected is what a takeover looks like from outside
- Header and footer carry an account control. It is a component rather than a
  menu item because it is session state, not content — the menu tables can
  already express guest-only and signed-in-only links if the foundation wants
  its own

### Phase 4 — The layout shell — 2026-09-04

Built against the menus `MenuSeeder` already produces, so the CMS rule holds
from the first render — the content is in the database, and Phase 5 adds the
editing UI on top rather than the shell waiting for it.

#### Added

**The theme system, and no flash of the wrong colours**
- `ThemeTokens` renders both palettes from `theme_settings` as CSS custom
  properties, **inlined in the head rather than compiled into `app.css`** — the
  palette is CMS content sampled from the logo pack, and compiling it would mean
  a deploy to change the brand colour
- Both themes always emitted, so a system flip or a toggle needs no round trip
- **A cookie as well as localStorage, and the cookie is the part that works.**
  localStorage is only readable by JavaScript, which runs after the HTML
  arrives — so with it alone the server always sends light, the browser paints
  it, and the script corrects it. That correction *is* the flash. The cookie
  lets the server put `.dark` on `<html>` in the bytes it emits
- The inline script covers the one case the server cannot know — `system` — and
  is deliberately **not deferred**: a deferred script runs after the paint
- **Three states.** `system` is a real choice, not "nothing recorded": somebody
  who picked dark stays dark when their laptop flips at sunset
- `color-scheme` on both, so scrollbars and autofill match rather than staying
  white on a dark page
- Token names and values are sanitised — they come from a column an
  administrator edits and land in an inline `<style>`, so a value closing the
  block would be stored XSS with the site's own blessing. `url()` is refused
  outright
- A fallback palette for when the table cannot be read. Not the brand colours,
  deliberately — a page rendering black on black because a query failed is worse
  than one rendering in plain greys

**The shell**
- Base layout with a skip link *before* the navigation, named landmarks, and a
  polite live region
- Header and footer rendered from the seeded menus via `Menu::renderable()`,
  which returns an empty collection rather than throwing — a fresh environment
  gets a bare header, not a 500 on the home page
- The donate CTA takes its destination from whichever menu item is highlighted,
  so a campaign can retarget it without a deploy
- Footer carries the registration number, registering authority and TIN — what a
  Ghanaian donor looks for, and whose absence is what a scam site has in common
  with a real one that forgot
- Three-state theme control as a `<select>`, because three states cannot be
  represented honestly by one cycling button
- `prefers-reduced-motion` respected as a blanket rule
- Branded 403/404/419/429/500/503 pages inside the site layout — an unstyled
  framework error on a donation site reads as "this is broken", which is the
  moment a donor abandons a payment

#### Changed

- Tailwind's `dark:` variant redefined against the `.dark` class. Its default is
  the `prefers-color-scheme` media query, which a toggle cannot override — a
  visitor on a dark laptop would have had no way to choose light
- `routes/web.php` serves a named `home` route; the `welcome` stub is gone

### Phase 4 — Policies, and the admin path — 2026-09-04

#### Added

**Policies for every model, enforced mechanically**
- `BasePolicy` maps the standard abilities onto the permission strings that
  already exist, so a concrete policy is usually three lines naming its prefix —
  and the ones that override something are therefore the ones worth reading
- **Deny by default.** An ability with no matching permission resolves to
  `false`, never to "nothing said no, so yes". The likeliest way this breaks is
  a permission renamed in the seeder and not in a policy; failing closed makes
  that a support ticket rather than a breach nobody notices
- A `.manage` fallback, so pages can keep `view`/`create`/`update`/`delete`
  while menus keep a single `menus.manage`, without forcing one shape onto both
- `PolicyCoverageTest` fails on **any model in neither the policy map nor the
  "authorised through its parent" list** — so "policies for every model" is a
  test rather than a matter of discipline. All 109 are accounted for

**Archetypes, where the rule is structural rather than per-resource**
- `AppendOnlyPolicy` — `delete` returns false for donations, payments, payouts,
  orders and inventory movements **whatever permissions somebody holds**. Not
  "no permission grants it": refused, so no future grant can turn it on. It also
  stops Filament rendering a "delete selected" checkbox that would throw a stack
  trace after somebody selected forty rows
- `ReadOnlyPolicy` — delivery logs, the audit trail, activity log, backup
  records, visitor counts. A log somebody can edit is not a log
- `PublishablePolicy` — `publish` as a first-class ability, so a contributor can
  draft and somebody accountable decides what goes out under the foundation's name

**Policies with rules of their own**
- `BeneficiaryPolicy` refuses hard deletion outright — not because the data must
  be kept, but because destruction is the retention runner's job, and it checks
  for a legal hold, writes the audit entry first, projects the anonymous record,
  and stores a one-way digest. A delete button would skip all five
- `ConsentPolicy` refuses **updates**. A consent that can be edited proves
  nothing; getting one wrong means capturing a new one, as it would on paper

**Permissions the policies needed and that did not exist**
- `suppressions.view` / `.release` — putting an address back into circulation
  after a complaint is not the same act as reading the list
- `messages.view` / `.cancel` — cancelling a queued message can stop a campaign
  mid-send
- `sponsorships.view` / `.manage` — its own permissions rather than riding on
  `beneficiaries`, since it is the one feature that deliberately discloses
  information about a child outside the foundation
- `audit.view` — and deliberately **no** `audit.manage`
- `compliance.view` / `.manage`, `api_tokens.manage`, `error_reports.*`,
  `visitor_stats.view`
- Auditor gains `audit.view` and `compliance.view`: the two records an auditor
  actually comes for, both read-only like everything else that role holds

#### Fixed

- **`ADMIN_PATH` moved off `admin`.** The panel is now at `/scghf-office` —
  `manage`, `backend`, `panel`, `console`, `dashboard` and `office` are all in
  the same wordlists `admin` is; a hyphenated organisation name is not
- **An empty `ADMIN_PATH` is refused rather than obeyed.** `ADMIN_PATH=` — a key
  somebody cleared rather than deleted — would have mounted the panel at `/`,
  turning the home page into a login form, silently, on deploy
- Paths that would collide with something already served (`webhooks`, `storage`,
  `livewire`, `up`, `api`) fall back too. Mounting there does not fail loudly; it
  wins or loses a routing race depending on registration order

### Phase 4 — Admin panel front door — 2026-09-04

#### Added

**The Filament panel**
- Path from `config/admin.php`, never hardcoded. `/admin` is the first path a
  scanner tries; moving it protects nothing on its own but takes the site out of
  the sweeps looking for a login form to spray credentials at
- Brand name resolved through a **closure**, so renaming the foundation in the
  settings screen takes effect immediately. Passing the resolved string would
  have frozen it at boot and quietly made the CMS rule false for the one piece
  of content on every admin page
- Colours read from the `theme_settings` tokens sampled from the logo pack —
  resolved at boot, because Filament compiles them into a CSS palette
- Both fall back safely: a panel that will not boot because a setting is missing
  is worse than one showing a placeholder, and `theme_settings` does not exist
  during `migrate:fresh`

**Two-factor authentication, required**
- Filament v5 ships TOTP, so no new dependency — which matters on a host where
  adding a package means a deploy
- Wired to the `two_factor_*` columns Module 1 already built, both already
  encrypted casts and both in `$hidden`
- `two_factor_confirmed_at` moves with the secret, so the two can never disagree
- Recovery codes enabled, because the alternative to a recovery code is a
  support call that ends in somebody disabling 2FA over the phone
- The authenticator entry is labelled with the **email**, not the name — staff
  hold more than one account here, and two entries reading "Ama Mensah" is a
  code typed from the wrong one at the worst moment

**Sign-in records, which had no writer**
- `login_histories` has existed since Module 1 and the `auth.*` audit events
  since Module 8, with nothing emitting into either. Both are written now
- A failed attempt records the address tried **including for addresses with no
  account** — a spraying run is only visible if the misses are recorded, and
  recording only the hits would make the log a list of valid addresses
- A lockout is recorded separately from a plain failure: failures happen to
  everybody, a lockout is an attack or somebody who needs help in five minutes
- New-device detection, used only to say "check it was you", never to deny

**Configuration that had been documented and ignored since Phase 2**
- `config/admin.php` reads `ADMIN_PATH`, `ADMIN_2FA_REQUIRED`,
  `ADMIN_SESSION_TIMEOUT`
- `config/security.php` reads every `RATE_LIMIT_*` key and `FORCE_HTTPS`
- `RecordAdminActivity` middleware ends an idle staff session — measured from
  the last request, so somebody working steadily is never interrupted

#### Fixed

- **`hasTwoFactorEnabled()` threw on any `User` whose column was not loaded** —
  which a freshly created user is. It now treats "not loaded" as "no evidence of
  2FA", which fails towards asking somebody to enrol
- **`isNewDevice()` queried the logging table outside the protected block**, so
  "logging never breaks a sign-in" was true of the insert and false of the
  lookup one line before it
- **`runningInConsole()` was the wrong guard for device detection** — Pest runs
  in console, so it disabled the whole path under test while looking correct in
  production. It asks whether there is a user agent instead

### Phase 3 — Gap sweep — 2026-09-04

A pass over every table named in the ERDs, every seeded permission, every
feature flag and every documented `.env` key, checked against what actually
existed. Seven gaps, all closed.

**The pattern is why `CLAUDE.md` now carries a standing rule about it.** Each
gap read as a *feature* to anybody auditing the code — a permission that grants
nothing, a flag switched on with nothing behind it, an env key annotated "never
optional" that nothing reads. That is worse than an obvious absence, because
somebody has looked at it and believed it.

#### Added

**EXIF and GPS stripping** *(`MEDIA_STRIP_EXIF` had been documented since Phase
2, annotated "never optional", read by nothing)*
- Stripped synchronously on upload, not queued — the cron queue would leave a
  window in which the unsanitised original is on disk and reachable
- `Media::isPublishable()` refuses anything unsanitised; null means "not yet"
- Key names recorded, never values. `had_gps_data` kept as a safeguarding signal
- Turning the switch off makes publishing **impossible**, not easier

**Inbound delivery webhooks** *(`markBounced()` existed with nothing to call it)*
- `inbound_webhook_events`, on the same terms as `payment_webhook_events`
- A provider with no configured secret verifies as **false**, never true
- Postmark's `SubscriptionChange` direction is read, not assumed — the obvious
  mapping would have silently removed people who had just opted back in

**The audit archive** *(open question 16, answered)*
- `audit_archives` + `scghf:archive-audit-log`. Whole closed years to a
  compressed, hash-verified file outside the web root
- The chain continues across the gap; a gap with no **pruned** archive is still
  reported as tampering
- Write and verify before deleting anything

**`pledges` and `payouts`** *(named in §2.4, never built)*
- A pledge is not income, and lives apart so no total can accidentally include it
- Payouts need two people — the requester may not approve — and evidence

**`fundraisers`** *(promised in a Module 4 migration comment, never added)*
**`sponsorships`** *(the flag was **on** with nothing behind it)*
**`event_tickets`, `product_reviews`** *(`reviews.moderate` was seeded over a
table that did not exist)*

#### Fixed

- **The audit verifier accepted the first entry's `previous_hash`
  unconditionally**, so deleting the oldest entries would have passed. It now
  checks that against null, a pruned archive, or an explicit `--from`
- **GD stamps `CREATOR: gd-jpeg` into every JPEG it writes**, so counting that
  as metadata made every sanitised image look unsanitised again — re-encoded on
  every pass, losing quality each time, never converging

### Phase 3 — Module 8, System — 2026-09-04

**Phase 3 complete.** Eight modules, ~150 tables, 973 tests, 2014 assertions.

#### Added

**An audit trail that records reads, not just writes**
- `audit_logs` captures ACTIONS, including the ones that change nothing —
  reading a beneficiary's medical history, exporting donor records, running the
  retention sweep. spatie/laravel-activitylog records model *changes*; none of
  those are changes, so until now none left a trace
- Events are declared in `config/system.php` with a category and a severity, and
  recording an undeclared one **throws** — a new export screen has to be
  classified before it can log
- **Volume escalates severity.** One donor record viewed is somebody doing their
  job; two thousand exported is a question that needs asking the same day
- Append-only and **hash-chained** — an edit or a deletion breaks every hash
  after it, and `scghf:verify-audit-log` names the first break. It makes
  tampering *detectable, not impossible*; the head hash is anchored to the
  application log on every clean run, which is what an auditor can check against
- Impersonation is recorded separately from the person impersonated
- The actor's name is snapshotted, so the trail survives them leaving
- **Auditing never breaks the action it audits** — a failed write goes to the
  log off-database and the caller proceeds

**API tokens**
- Hashed and shown once; a leaked database is not a leaked set of credentials
- **No abilities by default**, and a mistyped ability is refused rather than
  silently granting nothing
- Beneficiary, safeguarding, donor and refund abilities can never be granted
- A **mandatory expiry**, with an expiring-soon list so an integration does not
  stop working on a Saturday with nobody knowing why

**Errors, grouped**
- Keyed by a fingerprint of class, file, line and the message with its variable
  parts normalised, so a failing page does not produce a row per visitor
- **Nothing from the request body, ever.** Route parameter names, never values
- Ordinary traffic — 404s, failed logins, validation — is not an error
- A fault that recurs after being resolved **reopens**

**Backups**
- `backups_log` records **restore tests** as first-class rows. One with no row
  count verified nothing; one with no named verifier is an assertion. Both are
  refused
- `healthWarnings()` says "no backup has ever been restored and verified" —
  which is true, and stays true until somebody does one
- `file_count` beside `size_bytes`, because shared hosting counts inodes

**Visitor statistics**
- Aggregate **by construction**: no IP, no fingerprint, no cross-site
  identifier, no per-visitor row. A test asserts the exact column list
- Counts after the response and swallows every failure — statistics are never
  worth a millisecond on a 3G connection, and never worth an error page
- Path only, never the query string, which is where tokens and email addresses
  live. Referring host, never the full referrer
- **This application cannot report unique visitors**, and that is the trade

**Feature flags**
- The database overrides a flag; it cannot invent one. `config/features.php`
  stays the source of truth for which flags exist
- Every override carries a **reason** — required for switching on as much as
  off — and an **expiry**, which is what stops a temporary measure becoming
  permanent
- A lapsed override is simply not loaded, so nothing has to run to expire one
- `donations` is locked against admin-panel override
- Falls back to config when the table cannot be read

**Settings history**
- Hooked into the `Setting` model, not into Filament, so it holds however the
  value is changed
- Encrypted settings record *that* they changed, with both values redacted
- `valueAt()` answers "what did the receipts we issued in March say?"
- No foreign key to `settings`, so the history survives the setting

#### Notes

- New commands: `scghf:verify-audit-log` (daily, quiet when clean),
  `scghf:sms-delivery-reports` (hourly), plus a daily error-table prune
- Two open questions recorded: who performs the quarterly restore test and
  where, and where the audit trail is archived from year three

### Phase 3 — mNotify — 2026-09-04

#### Added

- **`MnotifyGateway`** — the SMS provider the foundation chose, sending under the
  registered sender ID `GreaterHope`. The only class that talks to mNotify, on
  the same terms as `PaystackService`
- **Accepting is never read as delivering.** mNotify accepting a message says
  only that mNotify accepted it; an unregistered or lapsed sender ID is accepted
  by the provider and dropped by MTN/Telecel/AT with no error returned anywhere
- An **unrecognised response code is a failure**, not a shrug. Treating an
  unknown response as "probably fine" is how a provider changing its API goes
  unnoticed until somebody asks why nobody got their receipt
- Account-level failures — no credit, bad API key, rejected sender ID — are told
  apart from message-level ones and logged `critical`. They break *every*
  message, and the queue would otherwise fail quietly one message at a time
- Remaining SMS credit is read from every send and warned on below a threshold.
  Credits are pre-paid and running out is silent
- **`scghf:sms-delivery-reports`**, hourly. The only way a dead sender ID becomes
  visible: it looks like a total collapse in delivery while sending continues to
  report success. Below 60% delivered over 20+ reported messages it exits
  non-zero, naming the sender ID, so cron emails somebody
- `ReportsDelivery` contract, separate from `SmsGateway` because `log` genuinely
  cannot answer the question

#### Changed

- `SMS_SENDER_ID` default is now `GreaterHope` — eleven characters, exactly the
  GSM maximum, and it must match the registration including casing
- Production refuses to boot with `SMS_DRIVER=mnotify` and no `MNOTIFY_API_KEY`.
  That configuration cannot send a single message, and finding out one failed
  receipt at a time is the expensive way
- `SMS_DRIVER` stays `log` until the key is in the server's `.env`

#### Notes

- Still worth confirming with mNotify before the first live send: that the
  registration covers all three networks, and that the delivery-report response
  shape matches what the gateway parses. The parsing is defensive and degrades
  to "still don't know", but a verified shape beats a defensive one

### Phase 3 — Module 7, Communications — 2026-09-03

#### Added

**The suppression list, as a gate rather than a report**
- `suppressions` — one row per address per channel, and every send checks it.
  Blueprint risk DEL-4: without it, bounces accumulate until the sending domain
  stops being delivered, and the first casualty is the donation receipt
- **Suppression has a scope.** `all` stops everything including receipts;
  `marketing` stops appeals only. An unsubscribe is `marketing` — somebody who
  no longer wants appeals has not asked to stop receiving the record of a gift
  they just made
- Suppression only ever **strengthens** automatically. Coming off the list needs
  a named person and a recorded reason; an address suppressed under an Act 843
  objection cannot be released at all
- Addresses are **normalised** before storage — lowercased email, E.164 phone.
  `Ama@Example.com` and `ama@example.com` are one mailbox, and five spellings of
  a number are five chances to text somebody who asked not to be
- Deliberately **excluded from the retention sweep**. Forgetting that somebody
  objected is how they start receiving mail again after asking not to

**Templates, editable but not everywhere**
- `email_templates` and `sms_templates`, keyed, CMS-editable, with declared
  variables. The seeder refreshes structure on every deploy and **never**
  overwrites wording the foundation has changed
- The GRA acknowledgement paragraphs stay in `config/compliance.php` and reach
  the template as one `{{acknowledgement}}` variable — nobody can reword a
  statement made under s.97 of Act 896 by editing an email in a browser
- Rendering **refuses** on a missing required variable. "Dear ," cannot be
  recalled; a failed job can be fixed in five minutes
- Placeholders are not Blade. A database column rendered as Blade is arbitrary
  PHP execution one compromised admin account away from being somebody else's
- A **locked** template can be reworded but not deactivated or deleted. The
  failure guarded is quiet: somebody tidies the list, receipts stop, nothing
  reports an error
- Subject lines are stripped of newlines — a donor-supplied name containing one
  turns whatever follows into a header of its own

**SMS, costed before a provider exists**
- `SmsSegmenter` measures encoding, septets and segments properly: GSM-7
  extension characters cost two, emoji cost two UCS-2 units, and a multipart
  message loses seven bits per segment to the concatenation header
- **The cedi sign is not in GSM-7.** `CLAUDE.md` mandates "GH₵ 1,234.56" for
  display, so the correct format for the website roughly triples the cost of
  every SMS sent from a template containing it. `SmsTemplate` refuses to save a
  template that blows its segment budget, and names the character responsible
- `PhoneNumber` normalises every way a Ghanaian number gets typed to E.164, and
  **refuses** a nine-digit pre-2010 number rather than guessing — the migration
  inserted a digit, and a guessed number sends a receipt to a stranger
- `SMS_DRIVER=log` writes a complete, segmented, network-attributed, costed row
  and sends nothing, so a month of running the site estimates what SMS will cost
- `sent` is never read as `delivered`. An unregistered sender ID is accepted by
  the provider and dropped by the network with no error anywhere

**Logs, the outbox and the throttle**
- `email_logs`, `sms_logs`, `notification_logs` — every attempt recorded
  **including the refusals**. A suppressed receipt produces a row explaining
  itself so Finance can post it or hand it over
- `scheduled_messages` is the outbox: visible, cancellable, priority-ordered and
  **expirable**. A backlog on this host is measured in days, and a queue that
  delivers "the event is tomorrow" three days late is worse than one that
  delivers nothing and says why. Receipts never expire
- Claimed under `lockForUpdate` with a TTL, because cron starts a worker every
  minute and the previous one may still be running
- `idempotency_key` is unique — a replayed webhook collides instead of producing
  a second receipt
- **The throttle is a `COUNT`, not a counter.** It counts rows in `email_logs`
  with `sent_at` inside the window: atomic without a lock, self-correcting after
  a killed worker, and incapable of drifting from what was actually sent
- `scghf:send-messages`, scheduled every minute, drains what the host's hourly
  cap allows and stops. Exits non-zero when the oldest message has waited six
  hours — the number that actually matters here, not the queue length

**Newsletters** *(deferred out of Module 6; they needed templates and suppressions)*
- `newsletters`, `newsletter_campaigns`, `campaign_recipients`, plus three
  seeded lists so `subscribers.topics` means something concrete
- **Consent is re-checked per message, not per campaign.** At two hundred an
  hour a campaign takes most of a day; somebody who unsubscribes in hour three
  has unsubscribed
- Two gates before a campaign can go: a **test send** must have happened, and an
  **approval** recorded by somebody holding `newsletter.send`. Editing the
  content afterwards withdraws both
- A campaign can be **paused** mid-flight — only meaningful because 1,600
  messages are still waiting
- Every campaign email carries an RFC 8058 one-click unsubscribe, and a
  marketing message with no working unsubscribe link is **refused** rather than
  sent without one
- Skips are recorded with a reason rather than deleted, so "why did I not get
  the newsletter?" has an answer

**Retention and privacy**
- New class `communication_log` — 24 months from send, then deleted. Delivery
  evidence has a shorter life than the financial record it relates to, which
  lives under `financial_record` independently
- Open and click tracking are **off**. Recording that a named person read a
  message is Act 843 processing needing its own lawful basis; the columns exist
  so enabling it is a config change, not a migration

#### Changed

- `bootstrap/providers.php` registers `CommunicationServiceProvider`, which
  refuses to boot production with an over-length SMS sender ID

#### Notes

- 895 tests, 1867 assertions. `config/communications.php` carries the policy;
  three open questions are recorded in `docs/PHASE-3-DATA-ARCHITECTURE.md`
- Only the `log` SMS driver is implemented. A stub that silently succeeded would
  be worse than none, because `log` at least tells the truth about what it did

### Phase 3 — Module 6, Engagement — 2026-09-03

#### Added

**Safeguarding, as a gate rather than a policy**
- `VolunteerApplication::approve()` **refuses** while any required check is
  outstanding, and names which ones. The failure guarded is not malice — it is
  approving a keen volunteer "and doing the police check next week"
- `involves_vulnerable_contact` defaults to **true**; an application with no
  opportunity is treated the same. A default of false would mean every role
  somebody forgot to configure quietly skipped its checks
- Roles with no contact get a lighter set — requiring a police check to hand out
  leaflets turns the requirement into a formality, and a formality is not a
  safeguard
- `safeguarding_checks` is one **row** per check with a reference, a date and a
  named verifier. A pass without either is refused; a waiver needs a stated
  reason *and* an authoriser
- **Clearances go stale.** A police certificate carries an expiry and
  `isCurrentlyCleared()` is computed from the dates, not read from a cached flag
- A concern **suspends immediately** — before investigation, without implying a
  finding. It insists the concern is written down, and insists on a written
  outcome before reinstating

**Volunteers**
- Hours in **minutes**, capped at a day, future-dated entries refused
- Only **verified** hours count towards the figure a funder is shown, and nobody
  can verify their own

**Events**
- Capacity counts **people, not bookings** — three guests take four places
- Over capacity **waitlists** rather than refusing; registration runs under a row
  lock so two people cannot both take the last two places
- `photography_consent` is **nullable with no default**: "never asked" and "said
  no" are different answers, and only the wrong one can be inferred from silence
- Three separate consents — photography, event contact, newsletter. A
  registration is not a mailing list
- Ticketing is feature-flagged off and the model refuses to mark an event
  ticketed while it is

**Prayer requests**
- **Confidential by default.** `is_confidential` true, `consent_to_publish`
  false, and publication needs both cleared. Enforced on every save, so
  `forceFill` cannot walk past it
- Anonymous by default even *with* consent — being happy for a situation to be
  prayed about publicly is not being happy to be named in it
- An anonymous request has its contact details stripped on creation

**Retention**
- New classes: declined volunteer application (12 months), withdrawn (6),
  volunteer record including safeguarding (72, sensitive), event registration
  (24 from the event ending), prayer request (12 from submission, sensitive)
- Accessibility and dietary needs classified as **health data**; a prayer
  request's text likewise

⚠ **The volunteer retention periods are defaults, not advice** — recorded as an
open question rather than presented as settled.

817 tests, 1722 assertions.

### Phase 3 — Module 5, Shop — 2026-09-03

#### Added

**Catalogue and the FDA guard**
- Every product save screens name, summary and description against
  `compliance.shop.prohibited_keywords`. A flagged product cannot go live until
  somebody records a review **with a reference** — "we looked at it" is not a
  review an auditor can follow up
- Screening runs on **every** save, so a published mug edited to mention a
  supplement is caught. An existing review is cleared when new flags appear that
  it did not cover
- A newly flagged save **unpublishes** rather than refusing — refusing would
  discard the editor's work and leave the older text live. An explicit
  `publish()` still throws, naming the keywords and the regulator
- Word-boundary matched: "creamery" does not trip "cream", "drugstore" does trip
  "drug"
- The taxonomy is **seeded from the compliance policy**, not duplicated, so the
  two cannot drift. Categories outside the agreed list are visibly marked

**Stock as an append-only ledger**
- `inventory_movements` with a reason on every row; `stock_on_hand` is a cache
  and `recalculateStock()` rebuilds it. An adjustment without a note is refused
- Holds reserve the shelf at **order time**, not at payment, so two customers
  cannot buy the last mug while both sit on the payment page

**Orders, on the shared payment path**
- `Order` is a `Payable` — same gateway, same verification, same mismatch
  handling. Two payment paths is how a ledger diverges
- `order_items` snapshot name, SKU and unit price; the variant FK is nullable
  with `ON DELETE SET NULL`, so a discontinued product takes no history with it
- Line totals are **derived**, never accepted from the caller
- Totals reconcile — lines to subtotal, and subtotal + shipping − discount to
  total — before the gateway is called
- Status history is append-only and attributed; a system change is marked as
  such rather than shown as nobody
- A paid order cannot be cancelled outright: the money has to go back, and a
  refund is its own record with its own approval

**Invoices — the separation rule, both directions**
- `SCGHF-INV-…`, its own counter table, never the `SCGHF-R-…` donation series
- `InvoiceIssuer` **asserts** before writing that the order could not lawfully
  receive a charitable acknowledgement
- Every invoice states in words that it is not a donation acknowledgement and
  cannot support a section 100 claim

**Shipping, coupons, downloads**
- All sixteen Ghanaian regions covered, seeded **inactive with no rates** — the
  regions are a fact, the prices are a commercial decision
- Collection is a zone with a zero rate, not a branch in the checkout
- Free-delivery thresholds compare against the **subtotal**, not the total
- Coupons in basis points, capped, never larger than the basket; a refused code
  returns a reason
- Digital downloads are long random tokens with an expiry and a use limit

#### Fixed
- **Abandoned checkouts stranded their stock.** Reconciliation marked the
  transaction abandoned but told the payable nothing. `Payable` gains
  `onPaymentAbandoned()`; a donation is marked abandoned, an order puts its
  goods back on the shelf
- `stock_held` is UNSIGNED, so `stock_held - 1` underflowed before
  `GREATEST(0, …)` could clamp it — a repeated release errored instead of being
  a no-op. Cast to SIGNED first

#### Changed
- `App\Models\Order` removed from `TaxDeductibility`'s pending-module list now
  that the class exists — keeping it would silence the warning that should fire
  if the model is ever renamed

746 tests, 1562 assertions.

### Phase 3 — Module 4, Fundraising — 2026-09-03

#### Added

**The gateway boundary**
- One payment path for donations *and* shop orders, via a polymorphic payable.
  Two payment paths is how a ledger diverges from the gateway
- **Webhook is truth.** The redirect only says the donor came back; the
  HMAC-authenticated endpoint says the money arrived. Raw body, `hash_equals()`,
  stored before parsing, queued, 200 returned immediately
- Answers **200 even to a forged request** — Paystack retries anything that is not
  2xx, so a 401 turns a probe into a retry storm. The exception is a failure to
  *store* the event, which returns 500 so it is not lost
- Idempotency lives in the database: `payment_webhook_events.event_id` is UNIQUE,
  so a replayed `charge.success` is a no-op even if the PHP handling it is wrong.
  An event with no id falls back to a hash of its body
- `PaymentTransaction` stores **expected** and **actual** separately. A mismatch
  becomes `mismatch` — not `success` (wrong figure) and not `failed` (the money
  may have been taken). It alerts and waits for a human
- `FeeCalculator` holds the rate in basis points, never a float. Gross-up solves
  `charge − fee(charge) = intended` rather than adding the fee to the original,
  which always leaves the foundation short. Property-tested at 18 amounts
- Card data: allow-listed authorization fields, a refusal to store more than four
  digits, and a scrubber that redacts card-shaped **values** at any depth
- Refunds are their own rows, need a stated reason, cannot exceed what remains,
  and **cannot be approved by the person who requested them**
- `FakeGateway` makes the module buildable before the merchant account exists —
  and verifies signatures with the same HMAC-SHA512, because faking that would
  leave the most security-critical line untested. **Production refuses to boot**
  on the fake driver or a test key

**Donations**
- Append-only, enforced: a completed gift refuses to have its amount, cause,
  reference, currency or deductible subtotal changed, and none can be deleted
- `donation_items` for **every** gift. A GH₵ 500 donation can be GH₵ 300
  deductible and GH₵ 200 not, so the subtotal is a sum over items
- Items are reconciled against the total **before** the gateway is called
- `is_tax_deductible` is snapshotted from `TaxDeductibility` and cannot be
  rewritten. A gift stays deductible after the approval lapses — the
  acknowledgement in the donor's hands must not change meaning
- Settlement is idempotent under a row lock; totals increment atomically in SQL
- Donors are separate from users, matched by email then normalised phone, never
  by name. Their details are snapshotted onto each gift
- Consent captured per channel with its Act 843 evidence

**Acknowledgements**
- Sequential per financial year with **no gaps**, from a counter row under
  `lockForUpdate` — not an AUTO_INCREMENT, which burns a number on a rolled-back
  insert
- Every figure and sentence **snapshotted**, never re-rendered
- The tax wording covers the **deductible subtotal**, not the gross
- Refuses a gift that has not completed, a payable on the never-acknowledge list,
  and a missing Foundation TIN

**Recurring giving**
- ⚠ **The mobile-money caveat is surfaced, not hidden.** Recurring charges need a
  reusable authorization; Paystack issues those readily for cards but not
  reliably for MoMo — which is how most Ghanaian donors pay. The default is
  conservative, blocked subscriptions are **reported** in the run summary and as
  skipped charge rows, and the decision is the foundation's to make
- Two drivers: `gateway` (Paystack owns the schedule) and `managed` (we charge a
  stored authorization from cron). A gateway-driven one is never charged locally
- Overlapping cron runs cannot double-charge — unique on
  `(subscription_id, scheduled_on)`
- One decline sets `failing`; three consecutive failures pause it. Any success
  clears the counter. A cancelled commitment refuses to resume
- Deductibility snapshotted fresh per cycle; every cycle gets its own receipt

**Offline gifts and reconciliation**
- Cash, cheques and bank transfers go into the **same** ledger and number series
- Reconciliation recovers payments the gateway settled that the site never heard
  about, and verifies once more before writing off an abandonment
- Mismatches, unprocessed webhooks and missing acknowledgements are reported,
  never auto-fixed. `--series` finds gaps in a year's receipt numbers
- Both commands registered in the **scheduler**, not as new cron lines. The
  retention sweep is scheduled **dry**

#### Fixed
- **The donation append-only guard did nothing.** It compared
  `getOriginal('status')` — a cast enum — against a string. `getRawOriginal` now
- `received_on` had no date cast; settlement was copying the offline method onto
  the donation's `channel`

646 tests, 1357 assertions.

### Phase 3 — Module 3, Programmes — 2026-09-03

#### Added

**Divisions and projects**
- `divisions` is the spine — the four divisions from the foundation profile, seeded
  and **locked**: projects, causes, donations and years of reporting hang off them,
  so deletion is refused and deactivation offered instead
- Each division names a **theme token** for its colour, never a hex. A hex in a data
  column would bypass `theme_settings` and its AA contrast validation exactly as a hex
  in a Blade template would; a test asserts every division names a token that exists
- `division_id` is nullable everywhere and **null means foundation-wide, not unknown**.
  `forDivision()` therefore includes the shared rows by default. Foreign keys are
  `ON DELETE SET NULL`: content outlives the structure
- The eight CMS tables Module 2 deferred gain `division_id` **with its foreign key**,
  as that migration promised — expand-only, constraint and column together
- `projects`, `project_updates`, `project_milestones`, `project_locations`, and pivots
  to focus areas, partners and documents. Budgets are integer pesewas through
  `MoneyCast`; a float budget is refused rather than silently stored a hundredfold low

**Causes and impact**
- `causes` is the fundraising unit, `projects` the work — separate tables, because a
  cause can fund several projects and a project can run with no appeal behind it
- **General Fund** seeded and locked, so a gift made with no appeal chosen still has a
  destination. `Cause::generalFund()` throws rather than returning null
- Per-division funds seeded as drafts with no copy: the foundation writes its own
  appeal text
- Progress is not clamped at 100% — an appeal that raised 140% should say so
- `impact_metrics` + `impact_metric_values` as a **time series**, not a running total,
  with who verified each figure and when

**Beneficiaries, consent and analytics**
- **Two datasets.** `beneficiaries` is operational and destroyed at retention expiry;
  `beneficiary_impact_records` is anonymous, projected at case closure, and outlives
  it. `source_beneficiary_id` is `ON DELETE SET NULL`, so the linkage is severed in
  the same statement that destroys the case record
- **Closure starts the retention clock; it destroys nothing.** An open case has no
  anchor date, which is what stops a live case being swept up
- `consents` — polymorphic, evaluated from its dates. A story needs story consent; a
  photograph needs photo consent as well; a real name needs name-use consent on top.
  Enforced in the model's saving hook, so `update(['is_published' => true])` cannot
  walk past it. `missingConsents()` says *which* consent is missing
- A minor's consent is refused unless it names the guardian who gave it, and a minor
  cannot consent on their own behalf
- Sensitive documents get 24 months against the case record's 72, and a medical or
  identity document is sensitive whatever the flag says
- `Beneficiary::privacyElements()` classifies every column; a test fails on any column
  that maps to nothing

#### Changed
- **The GRA acknowledgement wording.** The document is an *acknowledgement of
  contribution/donation to a worthwhile cause*, not a "tax-deductible receipt" — the
  Foundation acknowledges the gift, the GRA decides the deduction. Three paragraphs:
  s.97 status (only while a valid approval is held), the acknowledgement itself with
  the amount in figures and words, and the mandatory s.100 disclaimer
- **Approval validity is evaluated against the donation date, not today.** A receipt
  reprinted after an approval lapses still cites what was true when the gift was
  received; a gift received before approval never acquires it retroactively; a revoked
  approval is never cited
- **`causes.is_tax_deductible` is necessary but not sufficient.** Deductibility also
  requires a current written GRA approval; `qualifiesForTaxRelief()` delegates to the
  single gate rather than reading the column
- **The de-identification boundary gained a third disposition.** Destroy / generalise /
  keep, because an exact amount and an exact day beside a division and a district
  identify a person with no name in the row. Community, case reference and payment
  reference moved to destroy
- `AmountInWords` is pure PHP rather than `ext-intl` — intl is not guaranteed on shared
  cPanel hosting, and a legal document whose wording changes when the host upgrades PHP
  is not acceptable

#### Fixed
- **Fixture tables in tests caused a full `migrate:fresh` before every test.** MySQL
  implicitly commits on DDL, ending the transaction `RefreshDatabase` wraps each test
  in; Laravel then resets its migrated flag, re-migrates, drops the fixture table, and
  the test recreates it. Thirteen seconds per test. Fixture tables moved to
  `tests/database/migrations`, loaded only in the testing environment
- **`de-identify` was not idempotent** — a NOT NULL column got a fresh random redaction
  marker on every run. Already-redacted values are now skipped
- **`AGGREGATION_LATEST` returned the earliest figure.** The `values()` relation carries
  an ascending order for display, and appending `orderByDesc` does not override it

468 tests, 982 assertions.

### Phase 3 — Database architecture — 2026-09-02

#### Added

**Money foundation**
- `App\ValueObjects\Money` — integer minor units plus a currency, immutable. No float
  anywhere inside it and no public way to get one out: `percentage()` uses bcmath,
  everything else is integer arithmetic, `toMajorString()` returns a string
- `allocate()` / `allocateEvenly()` distribute the rounding remainder one pesewa at a
  time, so split or designated giving reconciles to the total exactly
- `App\Casts\MoneyCast` — refuses anything that is not a `Money` or an integer of
  minor units, so a stray `50.00` cannot be written as if it were 50 pesewas
- 53 tests including the cases that justify the class: `0.1 + 0.2` summing to exactly
  30 pesewas, and 1,000 additions of GH₵ 0.07 landing on exactly GH₵ 70.00

**Data architecture** — `docs/PHASE-3-DATA-ARCHITECTURE.md`
- ~150 tables across eight modules, with cardinalities, in dependency order
- Conventions: `BIGINT` internal key + ULID public identifier + human reference;
  `BIGINT UNSIGNED` pesewas; `VARCHAR` + PHP enum rather than MySQL `ENUM`; indexed
  string columns sized against the utf8mb4 3072-byte index limit
- Financial tables are append-only — corrections are new rows, never edits
- One payment path for donations *and* shop orders via a polymorphic payable
- Binding migration-safety policy: expand-only within a deploy, because migrations
  run before the release symlink flips and `rollback.sh` does not undo schema

**Module 1 — Core identity & authorisation**
- `users` carries staff and donors; `login_histories` records failed attempts too
- Ghanaian phone numbers normalised to E.164 on write, raw input retained
- `UserType` / `LoginOutcome` enums, `User` and `LoginHistory` models, factories
- `RoleAndPermissionSeeder` — 101 permissions across 10 roles, encoding the
  Blueprint §7.1 capability matrix as data. Idempotent, so it runs on every deploy
- 72 tests, 135 assertions, verified against real MySQL 8.4

#### Fixed
- **A deactivated user kept every permission their role granted.** The suspension
  guard was a `Gate::before`, but spatie/laravel-permission registers its own and
  package providers boot before app providers — so spatie returned true for a held
  permission and short-circuited ours. The guard now lives on the model as a
  `hasPermissionTo()` override, which every path routes through. The
  suspended-super-admin test had been passing for the wrong reason.
- **The Admin role was granted `payments.view_keys`**, contradicting the comment
  beside it: `fundraising.*` sweeps up the whole group. Added a `!permission`
  negation applied after wildcard expansion, with a test asserting each holds.
- Dropped a redundant standalone index on `users.type` — leftmost column of both
  composites, so it cost write time for no read benefit.

#### Local environment
- MySQL 8.4.9 initialised and bound to `127.0.0.1` only. The winget package installs
  binaries but never runs Oracle's configurator, so there was no data directory and
  no service; `scripts/dev-mysql.ps1` starts it without administrator rights
- `phpunit.xml` points the suite at MySQL rather than SQLite, so schema constraints
  SQLite does not enforce are caught locally
- `tests/Pest.php` added — `pest:install` had never been run, so Feature tests were
  not bound to Laravel's `TestCase`

### Phase 2 — Environment, repository & deployment pipeline — 2026-09-02

#### Added

**Repository scaffolding**
- `.editorconfig`, `.gitattributes` (LF everywhere, `export-ignore` so docs and CI never ship to the server)
- `.gitignore` covering secrets, built assets, backups and SQL dumps — donor data must never reach Git
- `.gitmessage` commit template with the `type(scope): subject` convention
- `pint.json` — Laravel preset with project overrides
- `README.md`, `CHANGELOG.md`

**Environment**
- Complete `.env.example`: app, logging, database, session/cache/queue, filesystem and media,
  mail (incl. bulk throttling), SMS (driver-agnostic), Paystack, money policy, security,
  backups, SEO/indexing, 16 feature flags, Vite
- Every key annotated with where its value comes from — cPanel, Paystack, SMS provider, or a project decision

**CI/CD**
- `.github/workflows/ci.yml` — PR gate: Pint, migrations against real MySQL 8, Pest,
  Vite build, **gzipped asset budget (100 KB JS / 50 KB CSS)**, secret scan
- `.github/workflows/deploy.yml` — `main` → production, `develop` → staging.
  Builds `vendor/` and assets on the runner so the server needs neither Composer nor Node.
  rsync over SSH port 2222 → migrate → warm caches → atomic symlink flip → smoke test →
  automatic rollback on failure
- `.github/PULL_REQUEST_TEMPLATE.md` with conditional checklists for money/auth/webhooks,
  UI and dark mode, uploads and beneficiary consent, and migrations
- `.github/dependabot.yml` — weekly grouped updates targeting `develop`; major framework
  bumps excluded as planned work

**Deployment**
- `deploy/scripts/bootstrap-server.sh` — idempotent one-time server setup: directory skeleton,
  shared storage, PHP binary and extension check, deny-all `.htaccess` on the app root,
  docroot symlink with automatic backup of existing content
- `deploy/scripts/activate.sh` — the atomic release switch. Verifies the upload, links shared
  state, **refuses to deploy to production with `APP_DEBUG=true` or a Paystack test key**,
  migrates, warms caches, generates environment-appropriate `robots.txt`, sets permissions,
  flips the symlink, prunes to 5 releases
- `deploy/scripts/rollback.sh` — one symlink move back, with an explicit warning that
  migrations do not roll back with the code
- `deploy/cpanel/cron.txt` — scheduler and `flock`-guarded queue worker for production and staging
- `deploy/cpanel/.cpanel.yml` — documented fallback, with its costs stated

**Server hardening**
- `public/.htaccess` — HTTPS and canonical-host redirects, dotfile blocking (with `.well-known`
  preserved for AutoSSL), sensitive-file denial, `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, `Permissions-Policy`, cross-origin policies, CSP in report-only mode,
  Deflate/Brotli, immutable caching for fingerprinted assets, WordPress probe blocking.
  HSTS present but commented until SSL is confirmed on every host

**Documentation**
- `docs/PHASE-2-RUNBOOK.md` — 11 steps, every cPanel click and terminal command, with
  expected output and a failure table for each
- `docs/DEPENDENCIES.md` — justification per package, shared-hosting risk, rejected
  alternatives, and three decisions still open

#### Decisions recorded
- Deployment strategy **A** (GitHub Actions → SSH/rsync) confirmed viable; SSH enabled on port 2222
- `robots.txt` generated at deploy time from `APP_ENV` rather than committed — staging can
  never inherit production's file, and a static file survives a 500 where a route would not
- Migrations run **before** the symlink flip, so a failure leaves the live site untouched.
  This makes expand-only migrations a hard requirement
- `PAYMENT_DRIVER=fake` and `SMS_DRIVER=log` are the defaults, so Phases 3–8 proceed
  without Paystack or SMS credentials

#### Local toolchain — resolved
- Herd 1.30.0 installed (winget `BeyondCode.Herd`; the ID is not `Laravel.Herd`). PHP **8.4.25**
  and Composer **2.10.2** confirmed, all 16 required extensions present.
- Composer has no winget package; the winget PHP packages ship with every extension commented
  out. Runbook Step 0 rewritten with both routes and a tested `php.ini` extension-enabling script.
- **`config.platform.php` pinned** so Composer resolves the lockfile for the server, not the
  workstation — see the stack decisions below for the final value.

#### Application installed
- Laravel **13.30.1** skeleton generated and merged over the Phase 2 overlay with
  `robocopy /XC /XN /XO` (copy-if-absent), preserving every Phase 2 file byte-for-byte
- Filament **5.7.8** · Livewire **4.4.3** · Pest **5.1.3** · PHPUnit **13.3.1** ·
  spatie: permission 8.3.0, medialibrary 11.23.6, activitylog 4.12.3, backup 10.3.2,
  honeypot 4.7.2, sluggable 4.0.3, sitemap 8.2.0
- `php artisan test` green (2/2) · `pint --test` green · migrations run

#### Stack decisions — both forced by dependency resolution, not preference
- **PHP 8.3 → 8.4** (approved). On 8.3, Pest is not installable at all:
  `pest-plugin-laravel` v5 needs PHP ^8.4; Pest 4 conflicts with the PHPUnit 12.5 that
  Laravel 13 ships; Pest 5 needs PHPUnit 13, which needs PHP ≥ 8.4.1. Separately,
  `spatie/laravel-sitemap` has no version compatible with both PHP 8.3 and Guzzle 8.
  Platform pinned to `8.4.1`; `require.php` set to `^8.4`; both CI workflows and all
  cron/deploy paths moved to `ea-php84`. `CLAUDE.md` amended.
- **Livewire 3 → 4.** Filament v5 requires Livewire 4, so the two locked entries in
  `CLAUDE.md` could not both hold. Phase 4 onward must use Livewire 4 idioms.
- **Pint config relaxed** to the plain Laravel preset. The `concat_space` and
  `trailing_comma_in_multiline` overrides conflicted with Laravel's own generated code,
  which would have made every `artisan make:` output fail CI until hand-fixed.

#### Hosting — direction changed, 2026-09-02
- **New hosting will be procured, running PHP 8.4. The project will not launch on `presti98`.**
- Closes risks **SH-18, OPS-9, OPS-11 and OPS-12** outright rather than accepting them.
  Blueprint §4.4.1 and decision #29 marked superseded; the §11.6.1 portability rules are
  retained as standing engineering rules.
- Runbook split: **steps 0–6 are host-independent and proceed now**; steps 7–11 (cPanel
  config, server bootstrap, cron, SSL, first deploy) are **deferred** until the host exists.
- The pipeline architecture is unchanged — build on runner, rsync over SSH, atomic release
  symlink, rollback script. Only values change: `SSH_HOST`, `SSH_PORT`, `SSH_USER`,
  `DEPLOY_PATH`, `PHP_BIN`, `APP_URL`, the docroot path, and cron syntax if not cPanel.
- The `ea-php84` patch-version and extension checks are moot for now; they carry over to
  whatever host is chosen.

#### Local database
- Local development stays on SQLite for the remainder of Phase 2, and **moves to MySQL 8.4
  before Phase 3** — runbook step 2.5 added. SQLite tolerates index key-length limits,
  `ENUM`, lax `ALTER TABLE` and unenforced foreign keys in ways MySQL does not, which is
  precisely what Phase 3's schema work needs to surface.
- CI's MySQL service bumped **8.0 → 8.4** (8.0 reached EOL in April 2026).

---

## Phase 1 — Discovery & architecture blueprint — 2026-09-01

### Added
- `PHASE-1-BLUEPRINT.md` v1.3 — brand tokens with every WCAG 2.2 AA ratio computed,
  sitemap, role matrix, eight user journeys, 42 modules, 60-entry risk register,
  environment plan, and the §0 placeholder register

### Confirmed
- cPanel `presti98`, home `/home/presti98`, docroot `/home/presti98/greaterhopefoundations.com`
- PHP 8.3 system default (8.4 available) · SSH port 2222 · 200 GB disk · 2 GB PMEM · 80 entry processes
- Brand: deep green `#0B4D3F` + orange `#FC6302`; logo blue `#0068EC` reassigned to the
  Every Soul Missions division accent

### Accepted risks
- SH-18 / OPS-9 / OPS-11 — the foundation runs as an addon domain on a shared cPanel account
  whose primary domain is an unrelated business. Migration to dedicated hosting deferred;
  portability rules in §11.6.1 keep the eventual move cheap

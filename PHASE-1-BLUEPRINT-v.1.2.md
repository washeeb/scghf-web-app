# St. Cecilia's Greater Hope Foundations — Web App
## Phase 1 Blueprint & Project Brief

| | |
|---|---|
| **Document** | Phase 1 — Discovery, Asset Intake & Architecture Blueprint |
| **Version** | 1.2 — SSH confirmed (port 2222); deployment strategy locked |
| **Date** | 1 September 2026 |
| **Status** | Awaiting client sign-off on §12(c) decisions before Phase 2 |
| **Companion docs** | `CLAUDE.md` (standing context) · `FOUNDATION-WEBAPP-MASTER-PROMPT.md` (phase sequence) |

**Sources used for this document**

| Source file | What it gave us | Completeness |
|---|---|---|
| `St-Cecilias-Greater-Hope-Foundations-Profile.docx` | Identity, story, vision, mission, motto, values, 4 divisions, beneficiaries, objectives, approach | ~55% — no contact, registration, board, bank, or social data |
| `SCGHF Logo.png` (2000×2000, RGBA) | Icon-only mark, orange/blue colourway | Usable; no vector |
| `SCGHF Logo 2.png` (1608×411, RGBA) | Horizontal lockup, green "Greater" + orange "HOPE" | Usable; no vector |
| `SCGHF Logo 2.1.png` (1608×411, RGBA) | Horizontal lockup, orange "Greater" + green "HOPE" | Usable; no vector |
| `Sample-Web-App-Template.jpg` | "KidHope" charity theme full-page preview | Visual reference only |
| `cPanel-screenshot-…-jupiter.png` | cPanel Tools page, feature inventory | Superseded by the full screenshot below |
| `Full-cPanel-screenshot-…-jupiter.png` | **General Information + Statistics panel** — username, home directory, domain, IP, quotas, LVE limits | Near-complete; inode count not exposed |
| `PHP-manager-screenshot-PHP-version.png` | **MultiPHP Manager** — system PHP version, per-domain PHP version, PHP-FPM state, available versions | Complete |
| SSH Access page screenshot | **SSH is available**, key management present, **port 2222** | Complete |

> All colour values below were sampled programmatically from the actual PNG pixel data, not eyeballed. All contrast ratios were computed with the WCAG relative-luminance formula.

---

# 0. Placeholder register

Everything not yet supplied appears in this document as a `{{TOKEN}}`. **Every token below is a CMS settings key, not a hardcoded string** — so filling one in later is a form entry in Filament, never a code change or a redeploy. That is the CMS rule working for you rather than against you.

Fill in the **Your value** column as information becomes available, and this table becomes the seed data for Phase 4's `settings` table.

## 0.1 Identity & legal

| Token | What it is | Your value | Needed by |
|---|---|---|---|
| `{{LEGAL_NAME}}` | Exact registered name, character-for-character | *(default: St. Cecilia's Greater Hope Foundations)* | Phase 4 |
| `{{SHORT_NAME}}` | Display name in the header/nav | *(default: Greater Hope Foundations)* | Phase 4 |
| `{{REGISTRATION_NUMBER}}` | Registrar-General / Dept. of Social Welfare NGO number | | Phase 4 · footer, receipts |
| `{{REGISTERING_AUTHORITY}}` | Which body registered you | | Phase 4 |
| `{{TIN}}` | Tax Identification Number | | Phase 8 · receipts |
| `{{TAX_DEDUCTIBLE}}` | Are donations tax-deductible for Ghanaian donors? yes / no | | Phase 8 · receipt wording |
| `{{DPC_REGISTRATION}}` | Ghana Data Protection Commission controller registration no. | | Phase 12 · privacy policy |

## 0.2 Contact

| Token | What it is | Your value | Needed by |
|---|---|---|---|
| `{{OFFICE_ADDRESS}}` | Street address | | Phase 4 |
| `{{GPS_ADDRESS}}` | Ghana Post GPS digital address (e.g. `GA-123-4567`) | | Phase 4 |
| `{{CITY}}` / `{{DISTRICT}}` / `{{REGION}}` | Location, for schema.org and local SEO | | Phase 4 |
| `{{POSTAL_ADDRESS}}` | P.O. Box, if used | | Phase 4 |
| `{{PHONE_PRIMARY}}` | Main line, E.164 (`+233…`) | | Phase 4 · header |
| `{{PHONE_SECONDARY}}` | Optional second line | | Phase 4 |
| `{{WHATSAPP_NUMBER}}` | For the `wa.me` link | | Phase 6 |
| `{{OFFICE_HOURS}}` | e.g. Mon–Fri 08:00–17:00 GMT | | Phase 6 |
| `{{EMAIL_GENERAL}}` | `info@greaterhopefoundations.com` | | Phase 4 |
| `{{EMAIL_DONATIONS}}` | Finance / receipts reply-to | | Phase 8 |
| `{{EMAIL_VOLUNTEER}}` | Volunteer Coordinator inbox | | Phase 11 |
| `{{EMAIL_SHOP}}` | Orders inbox | | Phase 9 |
| `{{EMAIL_MEDIA}}` | Press & partnerships | | Phase 6 |
| `{{EMAIL_SAFEGUARDING}}` | **Confidential** safeguarding channel | | Phase 11 |
| `{{EMAIL_NOREPLY}}` | Transactional `From:` address | | Phase 10 |
| `{{EMAIL_DMARC}}` | DMARC aggregate report inbox | | Phase 2 |

## 0.3 Social

| Token | Your value |
|---|---|
| `{{FACEBOOK_URL}}` | |
| `{{INSTAGRAM_URL}}` | |
| `{{X_URL}}` | |
| `{{LINKEDIN_URL}}` | |
| `{{YOUTUBE_URL}}` | |
| `{{TIKTOK_URL}}` | |

*Any left blank simply do not render — the footer's social row is data-driven, so there are no dead icons.*

## 0.4 People

| Token | What it is | Needed by |
|---|---|---|
| `{{FOUNDER_BIO}}` · `{{FOUNDER_PHOTO}}` | Adam Kingsley Washeeb — short bio + portrait | Phase 6 · `/about/our-story` |
| `{{CECILIA_PHOTO}}` · `{{CECILIA_STORY_LONG}}` | Mrs Cecilia Anyatuik Adam — portrait + the full story | Phase 6 · flagship page |
| `{{BOARD_MEMBERS[]}}` | Repeatable: name, role, photo, bio | Phase 6 · `/about/leadership` |
| `{{DIVISION_LEAD_LIFESPRING}}` etc. | One lead per division (optional) | Phase 7 |

## 0.5 Money

| Token | What it is | Needed by |
|---|---|---|
| `{{BANK_NAME}}` · `{{BANK_BRANCH}}` | For `/donate/other-ways` | Phase 8 |
| `{{BANK_ACCOUNT_NAME}}` · `{{BANK_ACCOUNT_NUMBER}}` | Must be in the foundation's name | Phase 8 |
| `{{BANK_SWIFT}}` | For international gifts | Phase 8 |
| `{{MOMO_MERCHANT_NAME}}` · `{{MOMO_MERCHANT_NUMBER}}` | Direct MoMo giving | Phase 8 |
| `{{DONATION_PRESETS}}` | *(default: GH₵ 50 / 100 / 250 / 500 / 1,000)* | Phase 8 |
| `{{MIN_DONATION}}` · `{{MAX_DONATION}}` | *(default: GH₵ 5 / GH₵ 100,000)* | Phase 8 |

> **Paystack keys are deliberately NOT placeholders in this document.** `PAYSTACK_PUBLIC_KEY`, `PAYSTACK_SECRET_KEY` and the webhook secret live **only** in the server `.env`, documented in `.env.example` with dummy values. They must never appear in this file, in the repository, or in any chat.

## 0.6 Hosting & providers

| Token | What it is | Your value | Status |
|---|---|---|---|
| `{{CPANEL_USER}}` | cPanel account username | **`presti98`** | ✅ confirmed |
| `{{HOME_DIR}}` | Account home directory | **`/home/presti98`** | ✅ confirmed |
| `{{APP_DOMAIN}}` | Production domain | **`greaterhopefoundations.com`** | ✅ confirmed |
| `{{ADDON_DOCROOT}}` | Document root of the addon domain | | ⚠️ verify (§4.4) |
| `{{SSH_PORT}}` | SSH port | **`2222`** | ✅ confirmed |
| `{{SSH_HOST}}` | SSH hostname for CI | *(use `23.235.219.254`, or the server hostname from cPanel → Server Information)* | ⚠️ pick one |
| `{{DB_NAME}}` | *(suggested: `presti98_scghf_prod`)* | | Phase 2 |
| `{{DB_USER}}` · `{{DB_PASSWORD}}` | DB credentials — `.env` only | | Phase 2 |
| `{{MYSQL_VERSION}}` | From phpMyAdmin | | ⚠️ verify |
| `{{INODE_LIMIT}}` | Not shown in cPanel statistics | | ⚠️ from InMotion |
| `{{CRON_MIN_INTERVAL}}` | Minimum permitted cron frequency | | ⚠️ verify |
| `{{STAGING_SUBDOMAIN}}` | *(suggested: `staging.greaterhopefoundations.com`)* | | Phase 2 |
| `{{SMS_PROVIDER}}` | Arkesel / Hubtel / mNotify | | Phase 2 |
| `{{SMS_SENDER_ID}}` | *(suggested: `GreaterHOPE`, 11 chars)* | | **register now** |
| `{{EMAIL_PROVIDER}}` | Resend / Brevo / Postmark / Mailgun | | Phase 2 |

## 0.7 Content & brand

| Token | What it is | Needed by |
|---|---|---|
| `{{HEADING_TYPEFACE}}` | *(proposed: Plus Jakarta Sans or Poppins)* | Phase 6 |
| `{{LOGO_SVG_LOCKUP}}` · `{{LOGO_SVG_ICON}}` · `{{LOGO_MONO_WHITE}}` | Missing brand assets (§2.3) | Phase 6 |
| `{{SHOP_PRODUCT_TYPES}}` | What the shop actually sells | Phase 9 |
| `{{DELIVERY_REGIONS}}` | Which of Ghana's 16 regions you deliver to | Phase 9 |
| `{{OPERATING_REGIONS}}` | Where the foundation currently works | Phase 7 |
| `{{PARTNER_LOGOS[]}}` | Existing partners & supporters | Phase 6 |
| `{{PROGRAMME_PHOTOS[]}}` | Consented photography | Phase 6 |

---

# 1. Foundation facts extracted

## 1.1 Identity

| Field | Value | Source |
|---|---|---|
| **Legal name** | St. Cecilia's Greater Hope Foundations *(note: plural "Foundations")* | Profile |
| **Short name / display name** | Greater Hope Foundations | Inferred from wordmark |
| **Wordmark / logotype** | **GreaterHOPE** | Logo pack |
| **Acronym / repo slug** | SCGHF | Filenames |
| **Motto (tagline)** | Faith. Compassion. Service. Hope. | Profile |
| **Type** | Ghanaian-owned foundation / non-profit | Profile |
| **Formed** | 25 October 2025 | Profile |
| **Registered** | 15 January 2026 | Profile |
| **Founder** | Adam Kingsley Washeeb | Profile |
| **Named in honour of** | Mrs Cecilia Anyatuik Adam (founder's late mother) | Profile |
| **Faith basis** | Explicitly Christian (evangelism is a core division) | Profile |

## 1.2 Vision, mission, purpose

- **Vision** — "To become a trusted Ghanaian foundation that brings hope, healing, education, care, and the love of Christ to vulnerable individuals, families, and communities."
- **Mission** — "To honour and continue the legacy of Mrs Cecilia Anyatuik Adam by providing compassionate support in health, education, family welfare, and evangelism, while restoring dignity and hope to people in need."
- **Purpose** — "To turn remembrance into impact."

## 1.3 Core values (7)

`Faith` · `Compassion` · `Love` · `Dignity` · `Service` · `Integrity` · `Legacy`

Each has a one-paragraph definition in the profile — all seven go into the CMS as a `core_values` repeater with icon + title + body, rendered on `/about` and reusable as a homepage section.

## 1.4 The four divisions (this is the spine of the whole site)

| # | Division | Remit | Focus areas (CMS-seeded) | Serves |
|---|---|---|---|---|
| 1 | **Life Spring Foundation** | Health | Basic health outreach · preventive health education · community health screening · support for vulnerable patients · maternal & child health awareness · wellness campaigns · health-worker partnerships | Vulnerable individuals, low-income families, women, children, elderly, communities with limited healthcare access |
| 2 | **BrightPath Fund Initiative** | Education | Scholarships & bursaries · school supplies & learning materials · mentorship · career guidance · at-risk-of-dropout support · character development · skills & leadership training | Needy but promising students, orphans, vulnerable children, low-income families, young people |
| 3 | **Legacy of Love Initiative** | Orphans, Widows & Widowers | Orphans & vulnerable children · widows & widowers · bereaved families · food & clothing · household support · emotional/spiritual encouragement · livelihood & skills training · seasonal donations & community visits | Orphans, widows, widowers, vulnerable children, elderly, single-parent households, families after loss |
| 4 | **Every Soul Missions** | Evangelism | Community evangelism · prayer & counselling · Bible distribution · Christian literature · discipleship · hospital & home visits · outreach to vulnerable groups · church/mission partnerships | Individuals, families, communities, new believers, vulnerable groups |

**Architectural consequence:** `divisions` is a first-class entity, not a blog category. Every project, cause, donation, event, volunteer opportunity, shop product, story and impact metric carries a `division_id` (nullable → "Foundation-wide"). Each division gets a hub landing page, its own accent colour, its own donation designation, and its own impact numbers.

## 1.5 Strategic objectives (9)

Preserve the legacy · support vulnerable people across health/education/welfare/spiritual growth · educational assistance · support orphans, widows, widowers · health awareness & outreach · evangelism & missions · build partnerships · operate with transparency & accountability · create visible lasting impact in Ghanaian communities.

→ Objective 8 ("transparency, accountability") drives the **public Transparency & Accountability page** (annual reports, financial summaries, policies) — see §6.

## 1.6 Beneficiary groups (drives an audience filter + tagging vocabulary)

Orphans · Widows · Widowers · Vulnerable children · Needy students · Low-income families · Sick and vulnerable individuals · Elderly persons · Families affected by loss · Communities needing spiritual encouragement

## 1.7 Partnership / support modes (drives the "Get Involved" IA and the donation designations)

Sponsor a child's education · support health outreach · donate food/clothing/books/medical supplies · support widows, widowers, orphans · volunteer professional skills · partner in evangelism & mission · fund community-based programmes

## 1.8 ⚠️ Data the profile does **not** contain (blocking or near-blocking)

These are hard gaps. Nothing in the uploaded profile supplies them, and several are legally required on a fundraising site. **Each one has a `{{TOKEN}}` in §0 — the build proceeds around them and you fill them in through the CMS as they arrive.**

| Missing | Needed for | Severity |
|---|---|---|
| Registration number (Registrar-General / Department of Social Welfare NGO number) | Footer legal block, receipts, donor trust, Paystack merchant onboarding | **Blocker** |
| Tax status / TIN, and whether donations are tax-deductible | Receipts, donation copy | **Blocker** |
| Physical address & region/district | Footer, contact page, `Organization` schema.org, Google Business | **Blocker** |
| Phone number(s), incl. WhatsApp | Header top-bar, contact page, SMS reply-to | **Blocker** |
| Email addresses (general, donations, media, safeguarding) | Contact routing, transactional mail `from`, receipts | **Blocker** |
| Bank account + Paystack merchant/settlement account details | Payouts, offline donation instructions, reconciliation | **Blocker** |
| ~~Domain name~~ — ✅ **`greaterhopefoundations.com`**, live as an addon domain on cPanel account `presti98` | Deployment, SSL, SPF/DKIM/DMARC, staging | ✅ Resolved *(DNS host still to confirm)* |
| Board of trustees / leadership / staff (names, roles, photos, bios) | `/about/leadership`, `team_members` seed, governance credibility | High |
| Social media handles | Header/footer socials, Open Graph, sharing | High |
| Existing partners / donors / logos | Partners strip, credibility | Medium |
| Geography of operation (which regions/districts today) | Project locations, "Where we work" map | Medium |
| Real programme/beneficiary photography (with signed consent) | Every page — the alternative is stock, which undermines trust | High |
| Shop product list, photos, prices, weights | Shop seeding | High (Phase 9 blocker) |
| Annual report / financials | Transparency page | Medium |
| Safeguarding & child-protection policy | Legally important given work with orphans and children | High |

> **Naming note to confirm:** the profile consistently writes "Foundation**s**" (plural) while the wordmark reads "Greater**HOPE**". Confirm the exact registered legal name character-for-character — it must match the registration certificate on receipts and in the footer.

---

# 2. Brand extraction from the logo pack

## 2.1 Variants supplied

| File | Type | Dimensions | Alpha | Description | Correct use |
|---|---|---|---|---|---|
| `SCGHF Logo.png` | **Icon / symbol only** | 2000×2000 | Yes (72.2% transparent) | Two abstract human figures forming a heart — **orange** figure + **sky-blue** figure | Favicon, app icon, avatar, social profile image, watermark |
| `SCGHF Logo 2.png` | **Primary horizontal lockup** | 1608×411 | Yes (82.4% transparent) | Icon in orange + **teal-to-deep-green**, with wordmark: "Greater" in **deep green**, "HOPE" in **orange** | Light backgrounds — header, footer-on-white, letterhead |
| `SCGHF Logo 2.1.png` | **Reversed-emphasis lockup** | 1608×411 | Yes | Same mark; wordmark colours swapped: "Greater" in **orange**, "HOPE" in **deep green** | Alternate / secondary contexts only |

## 2.2 Colours sampled from pixel data

**From the lockup (`Logo 2` / `Logo 2.1`) — this is the brand pair:**

| Role | Hex | Pixel count | Notes |
|---|---|---|---|
| Deep green (wordmark, heart terminal) | `#0B4D3F` | 34,943–39,156 | Dominant flat colour |
| Brand orange (wordmark, figure) | `#FC6302` | 34,941–39,154 | Dominant flat colour |
| Orange-red (gradient dark end) | `#FE4406` | 1,857 | Shadow/overlap of the orange figure |
| Teal (gradient light end of green figure) | `#2EC4A8` → `#2FC8AB` | gradient ramp | Top of the green figure |
| Orange gradient light end | `#FE8600` → `#FFA000` | gradient ramp | Head/arc of the orange figure |

**From the icon-only mark (`Logo.png`) — a second, different colourway:**

| Role | Hex range |
|---|---|
| Blue figure gradient | `#00BEFA` (light) → `#00A9F7` (mid) → `#0068EC` (dark) |
| Orange figure gradient | `#FE8600` (light) → `#FC6302` (mid) → `#FE4406` (dark) |

### ⚠️ Brand conflict to resolve

**The two files do not use the same palette.** The icon-only mark pairs orange with **sky blue**; the lockup pairs orange with **deep green / teal**. Shipping both as-is makes the brand look unfinished.

**Our decision (reversible — see §12c):**

- **Deep green `#0B4D3F` + orange `#FC6302` is the brand.** It is carried by the lockup, which contains the wordmark and is therefore the authoritative brand asset; it also matches the visual register of a serious foundation better than the blue.
- **The blue `#0068EC` is not discarded** — it is promoted to the accent colour of the **Every Soul Missions** division (§5.4). Nothing is invented; every colour in the system comes out of a file you uploaded.
- **`SCGHF Logo.png` (blue icon) must be re-coloured** to the green/teal + orange icon that already exists inside `Logo 2.png`, so the favicon matches the header. This is a small production task in Phase 6.

## 2.3 Missing brand assets (Phase 6 will be blocked without these)

| Needed | Why |
|---|---|
| **SVG versions of all three variants** | The PNGs are raster only. Header logos, favicons, OG images and email logos all need vector for crispness at every DPR and for tiny file size on 3G. This is the single most important missing asset. |
| **Mono / one-colour version** (all-white and all-dark) | Dark-mode header, watermarks, embroidery, print, low-fidelity documents |
| **Stacked / vertical lockup** | Mobile header, square social posts, centred print layouts |
| **Icon at small sizes (16/32/48 px), hand-optimised** | The current icon has thin gradient tips that will disappear at favicon size |
| **The exact typeface of the wordmark** | Headings should either use it or a deliberate complement. It reads as a heavy rounded geometric sans (Poppins/Museo-Sans family). Confirm with the designer. |

## 2.4 Logo usage rules (defined here, enforced in the CMS media rules)

**Clear space (safe area).** Minimum clear space on all four sides = **the height of the "H" in HOPE** (≈ 0.55 × the height of the icon). Nothing — text, image edge, button, or another logo — may enter that zone.

**Minimum sizes.**

| Variant | Minimum on screen | Minimum in print |
|---|---|---|
| Horizontal lockup | **140 px** wide (below this the wordmark counters fill in) | 30 mm wide |
| Icon only | **24 px** (with the small-size optimised version); **32 px** with the current file | 8 mm |
| Favicon | Use a purpose-drawn 16/32 px version, not a downscale | — |

**Light vs dark background.**

| Background | Variant |
|---|---|
| White / `#FFFFFF` / any light surface | `SCGHF Logo 2.png` (primary lockup) |
| Light brand tint (`#ECFAF6`, `#FFF3EA`) | `SCGHF Logo 2.png` |
| Deep green brand section (`#0B4D3F`) | **All-white mono lockup** — *to be produced.* The deep-green wordmark of `Logo 2.png` scores 1.00:1 against `#0B4D3F` and is literally invisible. |
| Dark theme surface (`#071310` / `#122420`) | **All-white mono lockup**, or a light-teal + orange variant. *To be produced.* |
| Photography | White mono lockup with a scrim, never the colour lockup |

**Never:** stretch, rotate, recolour outside the approved set, add drop shadows or outlines, place the colour lockup on a busy photo, or re-typeset the wordmark.

---

# 3. Sample template analysis — "KidHope"

The uploaded reference is a commercial nonprofit/charity WordPress theme preview (dark-green + amber, child-focused imagery). We treat it as a **layout and interaction reference only**. No markup, CSS, JS, images or copy from it enter this project.

> ⚠️ The preview image is watermarked with a theme-piracy site. We are not licensing, downloading, or copying that theme. Reading layout ideas from a picture is fine; cloning its code or assets would not be.

## 3.1 Component & section inventory observed

| # | Section / component | What it does in the template |
|---|---|---|
| 1 | Utility top bar | Address · phone · email · language switcher |
| 2 | Sticky header | Logo, horizontal nav, search icon, account icon, pill **DONATE NOW** CTA |
| 3 | Hero | Full-bleed photo, dark scrim, eyebrow label, large serif headline with underline flourish, two CTAs (solid donate + outlined video) |
| 4 | Curved section mask | Rounded top corners lifting the next section over the hero |
| 5 | Feature triptych | Three icon cards ("Clean Water & Energy", "Global Health", "Social Ventures") + a portrait supporting image |
| 6 | Split content block | Tilted/stacked photo cards on the left, "GIFT OF $36" eyebrow, headline, two paragraphs, CTA |
| 7 | Featured campaigns carousel | Cards with image, category, title, progress bar, raised/goal, DONATE button |
| 8 | Impact stat band | 4 large numbers on a dark green field (999k, 4.9, 65+, 120K) |
| 9 | Inline donation widget | Amount input + preset chips + image grid ("Make An Impact All Year Long") |
| 10 | Full-bleed video CTA band | "Volunteer Opportunities Now Open for You" + play button |
| 11 | Testimonials carousel | Avatar, quote, name, role |
| 12 | FAQ accordion | Accordion + supporting image |
| 13 | Mission/vision tabbed panel | "Our Mission / Our Vision" toggle with checklist |
| 14 | Gallery mosaic | Mixed-aspect photo grid |
| 15 | Partner logo strip | Greyscale logos |
| 16 | Footer | Newsletter form, 4 link columns, contact block, social icons, legal bar |

## 3.2 Verdict: reuse / improve / reject

### ✅ Reuse (the pattern is sound and proven for this audience)

| Pattern | Why we keep it |
|---|---|
| Utility top bar with phone + email | In Ghana, a visible, tappable phone number and WhatsApp link materially increase enquiry conversion. We keep it and make it a `tel:` / `wa.me` link. |
| Persistent pill **Donate** CTA in the header | The single most important conversion element. Sticky on scroll, present on every page, present in the mobile header (not buried in the hamburger). |
| Hero → scrim → headline → dual CTA | Clear, works with real photography, sets the emotional register in one screen. |
| Feature triptych | Maps perfectly onto our **four divisions** — we run it as a 4-up grid (2×2 on mobile). |
| Campaign cards with progress bars | Progress-toward-goal is the strongest single driver of donation intent. Core to our `causes` module. |
| Impact stat band | Maps onto `impact_metrics`. Fully CMS-driven, with a source/date note under each number for honesty. |
| Inline donation widget with preset amounts | Reduces donation friction to two taps. We make it the primary homepage conversion unit. |
| Testimonials, FAQ accordion, partner strip, mosaic gallery | All standard, all useful, all CMS-managed. |
| 4-column footer with newsletter | Standard and expected. |

### 🔧 Improve (keep the idea, change the execution)

| Template does | We do instead | Why |
|---|---|---|
| Hero background is a full-bleed desktop-resolution image | Responsive `<picture>` with AVIF/WebP, art-directed mobile crop, `fetchpriority="high"`, explicit dimensions, LQIP placeholder, ~40–60 KB mobile budget | LCP under 2.5 s on simulated 3G is a hard requirement. The template's hero would be 400 KB+. |
| "Video playing theme" CTA that autoplays a hosted video | Click-to-load facade (poster image + play button, iframe injected on click) | Never spend a Ghanaian visitor's data on a video they did not ask for. |
| Carousels everywhere (campaigns, testimonials) | Keyboard-operable, `prefers-reduced-motion`-aware carousels with visible pagination, **and** a "View all" link; on mobile they become a native horizontal scroll-snap list, no JS | The template's carousels are almost certainly mouse-only and not screen-reader safe. |
| Decorative tilted/rotated image cards | Kept, but CSS-transform only, `aria-hidden` on the decorative duplicates, flattened under `prefers-reduced-motion` | Nice visual, must not become an accessibility or CLS problem. |
| Donation widget posts to a generic form | Livewire widget → amount in **integer pesewas** → designation select (division / cause / General Fund) → optional "cover the transaction fee" → Paystack | This is where our money rules live. |
| Amounts shown as `$36` | `GH₵ 50` presets, tuned to realistic Ghanaian giving levels, CMS-editable per cause | Currency is GHS, full stop. |
| Impact numbers hardcoded in the theme | `impact_metrics` table, editable in Filament, with `as_of_date` shown | The CMS rule is non-negotiable, and unsourced stats are a trust risk. |
| Single light theme | Full light + dark theme, both AA-checked component by component | Project requirement. |
| Generic "Charity" nav (Home/Campaigns/About/Pages/Blog/Contacts) | Division-led IA (§6) — our four divisions are the product | The foundation's structure *is* the story. |
| Newsletter input in the footer with no consent language | Explicit consent checkbox + privacy link + honeypot + double opt-in | Ghana Data Protection Act (Act 843), and deliverability. |

### ❌ Reject

| Template element | Why |
|---|---|
| Theme code, CSS, JS, fonts, icons, images | Licensing, security, and it would fight Tailwind + Livewire. Zero lines are copied. |
| Stock photography of non-Ghanaian children | The foundation serves Ghanaian communities. Real, consented photography or nothing. |
| Language switcher in the top bar (for launch) | English-only at launch unless you say otherwise (§12c). We still build the `translations` scaffolding so adding Twi later is not a rewrite. |
| Search icon in the header (for launch) | With <50 content items, site search is dead weight and a performance cost. Add it when the blog and project archive justify it. |
| Decorative underline-scribble on headlines | Renders as a background image; we do it as an SVG that inherits `currentColor` and disappears in high-contrast mode, or we drop it. |
| Sitejet/WordPress-style freeform page builder | We use a curated block library (§9). Unlimited freeform blocks is how a CMS becomes unmaintainable and off-brand. |

---

# 4. cPanel environment audit

**Read from:** the full Tools page with the General Information + Statistics panel, and the MultiPHP Manager page. InMotion-branded, Jupiter theme, cPanel **134.0.53**.

## 4.0 Account facts — now confirmed

| Fact | Value | Consequence |
|---|---|---|
| **cPanel username** | `presti98` | Every path, cron entry and DB prefix is built from this |
| **Home directory** | `/home/presti98` | Plain `/home/`, not `/home2`/`/home4`. All §11 paths are now concrete. |
| **Primary domain** | `prestigerocktravels.com` | ⚠️ **Not ours.** See §4.4. |
| **Our domain** | **`greaterhopefoundations.com`** | ✅ Already exists on the account as an **addon domain**. Currently on PHP 8.3 (inherited). |
| **System PHP version** | **PHP 8.3 (`ea-php83`)** | ✅ **Laravel 13 is green.** Risk SH-1 is closed. |
| **PHP versions available** | 5.6, 7.0–7.4, 8.0 (all flagged deprecated) … and **8.3, 8.4** in active use on this account | PHP 8.4 is available if we want it (§4.4) |
| **PHP-FPM** | ⊘ **Disabled on both domains** | Performance opportunity — see §4.4 |
| **SSH access** | ✅ **Available**, with key management. **Port `2222`**, not 22. | ✅ **Deployment strategy A is confirmed** (§11.2). The non-standard port must be set in every `ssh`, `rsync` and `ssh-keyscan` call — see §11.4. |
| **Shared IP** | `23.235.219.254` | Shared, so email reputation risk DEL-3 / SH-14 stands. Also the likely `{{SSH_HOST}}` for CI. |
| **SSL certificate** | Active on the primary domain | Must be confirmed separately for `greaterhopefoundations.com` |
| **Theme** | Jupiter | — |

**Quotas and limits — all comfortable:**

| Resource | Used / Limit | Headroom |
|---|---|---|
| Disk usage | 2.17 GB / **200 GB** | 98.9% free — generous |
| Database disk usage | 20.87 MB / **197.85 GB** | Effectively unlimited |
| Backup usage | 2.16 GB / 10 GB | 78% free |
| Databases | 2 / **∞** | Fine for prod + staging |
| Addon domains | 1 / 10 | 9 spare |
| Subdomains | 1 / **∞** | Staging subdomain is free to create |
| Email accounts | 5 / **∞** | All 8 mailboxes in §11.5 can be created |
| **CPU** | 0 / **100%** | 1 core equivalent |
| **Entry processes** | 0 / **80** | Very generous for shared hosting |
| **Physical memory** | 0 / **2 GB** | Comfortable for Laravel + Filament |
| **Number of processes** | 0 / **200** | Comfortable |
| **Inodes** | **not exposed in the statistics panel** | ⚠️ Still unknown — see §4.3 |

> **The LVE headroom materially de-risks the queue design.** 80 entry processes, 200 processes and 2 GB PMEM is roomy. Risk SH-5 drops from High/Med to **Low/Med** — a single bounded `queue:work` will not come close to these ceilings.

## 4.1 Confirmed present

| Item | Evidence | Meaning for us |
|---|---|---|
| **cPanel version** | Footer: **`134.0.53`** | Current-generation cPanel. Git Version Control, Terminal, and Email Deliverability are all in the modern form. |
| **Theme** | Jupiter | — |
| **Host** | InMotion Hosting (branded sidebar, "Account Management Panel", "WordPress Manager by InMotion Hosting") | Matches the brief |
| **MultiPHP Manager** | Software section | We can set the PHP version per domain/subdomain |
| **MultiPHP INI Editor** | Software section | We can set `memory_limit`, `upload_max_filesize`, `max_execution_time`, OPcache settings |
| **Setup Node.js App** | Software section | CloudLinux Node.js Selector present → Node binaries exist on the server |
| **Setup Python App / Setup Ruby App** | Software section | (Not needed; confirms CloudLinux selectors are enabled) |
| **Git™ Version Control** | Files section | `.cpanel.yml` deployment is available as a fallback strategy |
| **SSH Access** | Security section | We can upload a public key and manage SSH keys |
| **Terminal** | Advanced section | Browser shell — lets us verify Composer/Node/PHP CLI before committing to a deploy strategy |
| **Cron Jobs** | Advanced section | Scheduler + queue worker are viable |
| **phpMyAdmin / Manage My Databases / Database Wizard / Remote Database Access** | Databases section | MySQL/MariaDB provisioning is standard |
| **Email Accounts, Forwarders, Email Routing, Email Filters** | Email section | Mailboxes available |
| **Email Deliverability** | Email section | **This is the SPF + DKIM management tool.** DMARC still has to be added by hand in Zone Editor. |
| **SPF Verify** | Email section | SPF record validation |
| **MailChannels** | Email section | InMotion routes outbound cPanel mail through MailChannels — relevant to §10.3 |
| **Zone Editor** | Domains section | DNS records (DMARC, CNAME, TXT) manageable here **if** DNS is hosted at InMotion |
| **SSL/TLS Certificates** | Security section | Certificate install/management |
| **Two-Factor Authentication** | Security section | Enable on the cPanel account itself |
| **Backup Manager** | Files section | Host-level backups (separate from `spatie/laravel-backup`) |
| **ModSecurity** | Security section | WAF present — **must be watched**, it can block Paystack webhook POSTs (§10.1) |
| **Monarx Security** | Security section | InMotion malware protection |
| **Resource Usage** | Metrics section | CloudLinux LVE stats — CPU/RAM/IO/entry-process limits. **This is where we read the real ceilings.** |
| **Redirects, Error Pages, Indexes, Apache Handlers, MIME Types** | Domains/Advanced | Standard, all useful |
| **Domains** | Domains section | Addon domain / subdomain creation → the staging subdomain is possible |
| **Manage API Tokens** | Security section | Optional automation path |

## 4.2 Confirmed **absent** (and why it matters)

| Not present | Consequence |
|---|---|
| **"Select PHP Version" (CloudLinux PHP Selector)** — only *MultiPHP Manager* + *MultiPHP INI Editor* are shown | **We cannot toggle individual PHP extensions from cPanel.** The extension set is whatever EasyApache has compiled for the chosen `ea-phpXX`. Laravel 13 + Filament + Media Library need: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `gd` *or* `imagick`, `hash`, `intl`, `mbstring`, `openssl`, `pcre`, `pdo_mysql`, `session`, `tokenizer`, `xml`, `zip`, `exif`, `sodium`, `zlib`. **Action:** verify with `php -m` in Terminal before Phase 2, and raise a support ticket for anything missing. `intl`, `exif` and `imagick` are the ones most often absent. |
| **"Application Manager"** | No Passenger-hosted app. Confirms the classic document-root + `.htaccess` deployment model — which is what we planned anyway. |
| **A visible "Composer" tile** | Composer is a CLI tool, not a cPanel icon — its presence must be tested in Terminal. Our chosen deploy strategy (§11) removes the dependency on it entirely. |

## 4.3 Remaining unknowns — verify before Phase 2

Your screenshots closed every gap that could change the architecture. **Nothing blocking remains.** Six operational details are still to confirm:

| Unknown | How to get it | Why it matters |
|---|---|---|
| ~~Is SSH enabled?~~ | ✅ **Resolved — available, port 2222** | Deployment strategy A confirmed |
| **The addon domain's document root** for `greaterhopefoundations.com` | Domains page, or `ls -la /home/presti98` | The whole release layout hangs off it (§4.4, §11.3) |
| **Which PHP extensions are compiled in** | Terminal → `php -m` | No PHP Selector on this account, so we cannot self-enable (§4.2) |
| **Inode limit and current usage** | Not in the statistics panel — Terminal `df -i /home/presti98`, or ask support | Sizes the media strategy (risk SH-6). The only quota still hidden. |
| **MySQL vs MariaDB, and version** | phpMyAdmin home page → "Server version" | Index length limits, JSON functions, `utf8mb4` defaults |
| **Minimum permitted cron interval** | Cron Jobs page; or the 5-minute test in §12(d) item 11 | If per-minute cron is blocked, the queue cadence is redesigned (risk SH-4) |
| **Composer / Node / npm on the server** | Terminal → `composer -V`, `node -v`, `npm -v` | Nice to know; strategy A does not depend on it |

> **Run this in cPanel → Terminal and send me the output before Phase 2 starts.** It closes every row above except the SSH question.

```bash
echo "HOME: $HOME"; echo "USER: $(whoami)"; ls -la "$HOME" | head -30; \
php -v; which php; ls /opt/cpanel/ | grep ea-php; \
composer -V 2>&1 | head -1; node -v 2>&1; npm -v 2>&1; git --version; \
php -m | tr '\n' ' '; echo; \
mysql --version 2>&1; df -h "$HOME" | tail -1; df -i "$HOME" | tail -1
```

## 4.4 Three findings that change the plan

### ⚠️ 4.4.1 This is a shared account — the foundation is an addon domain on a travel company's hosting

The account's **primary domain is `prestigerocktravels.com`**. `greaterhopefoundations.com` sits on it as **an addon domain** (1 of 10 used).

This works technically, and there is enough disk, CPU and memory for both. But it carries real risks that are worth naming now rather than discovering later:

| Risk | Consequence |
|---|---|
| **Shared fate** | If the account is suspended, over quota, or blacklisted because of the *other* site, the foundation's donation page goes down with it. |
| **Shared IP reputation** | Both sites send mail from `23.235.219.254`. The other site's mail behaviour affects donation-receipt deliverability. |
| **Shared credentials** | One cPanel login administers both. Anyone with cPanel access to the travel business has full filesystem access to the foundation's donor database. |
| **Governance** | A registered non-profit's donor data living on a for-profit's hosting account is awkward to explain to an auditor, an institutional donor, or the Data Protection Commission. |
| **Ownership** | If the travel business winds down or changes hands, the foundation's hosting goes with it. |

**Recommendation:** it is fine to **build and stage here**, and it is fine to launch here if budget is tight. But before you start taking real money, move `greaterhopefoundations.com` to **its own cPanel account in the foundation's name**, with its own credentials, its own IP where possible, and recovery access held by two trustees. This is the same point as risk OPS-9, and it is now concrete rather than hypothetical. *(Decision → §12c #29.)*

Everything in this blueprint works either way; only the paths in §11.3 change.

### ✅ 4.4.2 PHP 8.3 is confirmed — and 8.4 is available

The system default is **PHP 8.3 (`ea-php83`)**, and `greaterhopefoundations.com` already inherits it. `prestigerocktravels.com` runs **PHP 8.4 (`ea-php84`)**, which proves 8.4 is installed on this server.

**Risk SH-1 is closed. Laravel 13 runs here.**

Two actions:

1. **Set the PHP version explicitly rather than leaving it "Inherited."** Right now `greaterhopefoundations.com` shows `PHP 8.3 (ea-php83) [Inherited]` — meaning it follows the *system* default. If InMotion changes that default, our production site changes PHP version without warning. Select the domain in MultiPHP Manager and apply a version explicitly so it is pinned.
2. **Consider PHP 8.4** for a longer support runway. 8.3 is entirely fine for Laravel 13 and is the safer default; 8.4 buys roughly another year of security support. Either is acceptable — **my recommendation is to pin 8.3 for the build and move to 8.4 after launch once the full extension set is verified on it.**

> ⚠️ **Do not** use the MultiPHP Manager dropdown carelessly. In the screenshot it currently reads `PHP 5.6 (ea-php56)` — that is just the unselected default of the picker, but applying it to the wrong domain would take a site down instantly. Select the domain checkbox, *then* change the dropdown, *then* Apply.

### 🔧 4.4.3 PHP-FPM is disabled on both domains

MultiPHP Manager shows the PHP-FPM column as ⊘ for both `greaterhopefoundations.com` and `prestigerocktravels.com`, meaning PHP runs under suPHP/CGI rather than a persistent FastCGI process manager.

**Enabling PHP-FPM is the single cheapest performance win available on this account.** It keeps PHP workers warm between requests instead of paying process-startup cost on every hit, and it makes OPcache genuinely effective — which matters a great deal for Filament's admin panel and for hitting the 2.5 s LCP budget on a 3G connection.

**Action:** enable PHP-FPM for `greaterhopefoundations.com` in MultiPHP Manager during Phase 2, then re-measure. If InMotion's plan restricts FPM pool memory, tune `pm.max_children` against the 2 GB PMEM ceiling rather than turning it back off.

---

# 5. Brand token sheet

All tokens are emitted as **CSS custom properties on `:root` and `[data-theme="dark"]`**, consumed by Tailwind via `theme.extend.colors` referencing `rgb(var(--x) / <alpha-value>)`. Every colour token is **overridable from Filament** (`theme_settings` table), per the CMS rule — with the AA-checked values below as seeded defaults, and a contrast validator in the admin form that warns when an override breaks AA.

## 5.1 Primitive ramps (derived from the sampled logo colours)

**Green — "Hope Green", anchored on the sampled `#0B4D3F` and `#2EC4A8`**

| Step | Hex | Source |
|---|---|---|
| green-50 | `#ECFAF6` | derived tint |
| green-100 | `#D2F4EB` | derived |
| green-200 | `#A6E9D8` | derived |
| green-300 | `#5FE0B8` | derived |
| green-400 | **`#2EC4A8`** | **sampled from logo gradient** |
| green-500 | `#16A98D` | derived |
| green-600 | `#0B7D66` | derived (AA-tuned) |
| green-700 | `#0A6A58` | derived |
| green-800 | **`#0B4D3F`** | **sampled — brand primary** |
| green-900 | `#073A30` | derived |
| green-950 | `#04241E` | derived (ink) |

**Orange — "Hope Orange", anchored on the sampled `#FC6302` / `#FE4406`**

| Step | Hex | Source |
|---|---|---|
| orange-50 | `#FFF3EA` | derived tint |
| orange-100 | `#FFE2CC` | derived |
| orange-200 | `#FFC299` | derived |
| orange-300 | `#FF9C5C` | derived (AA-tuned for dark theme) |
| orange-400 | `#FE8600` | **sampled from logo gradient** |
| orange-500 | **`#FC6302`** | **sampled — brand secondary** |
| orange-600 | `#E14E00` | derived (fill-only) |
| orange-700 | `#B83E00` | derived (AA text) |
| orange-800 | `#9A3B00` | derived |
| orange-900 | `#6B2606` | derived |
| orange-950 | `#3A1200` | derived (ink) |
| orange-hot | `#FE4406` | **sampled — gradient dark end, gradients only** |

**Blue — "Mission Blue", from the icon-only mark**

| Step | Hex | Source |
|---|---|---|
| blue-200 | `#B9DCFF` | derived |
| blue-300 | `#63B3FF` | derived (AA for dark) |
| blue-400 | `#00BEFA` | **sampled** |
| blue-500 | `#00A9F7` | **sampled** |
| blue-600 | `#0068EC` | **sampled** |
| blue-700 | `#0059C9` | derived (AA text on white) |

**Neutrals — green-tinted greys so the whole UI sits in the brand's temperature**

`neutral-0 #FFFFFF` · `neutral-25 #F7F9F8` · `neutral-50 #F1F5F4` · `neutral-100 #E2E8E5` · `neutral-200 #CBD5D1` · `neutral-300 #7A8683` · `neutral-400 #6E7B78` · `neutral-500 #5E706B` · `neutral-600 #44544F` · `neutral-700 #24403A` · `neutral-800 #18302A` · `neutral-850 #122420` · `neutral-900 #0F1A17` · `neutral-950 #071310`

## 5.2 Light theme tokens

| Token | Hex |
|---|---|
| `--bg` | `#FFFFFF` |
| `--bg-subtle` | `#F7F9F8` |
| `--surface` | `#FFFFFF` |
| `--surface-sunken` | `#F1F5F4` |
| `--surface-inverse` | `#0B4D3F` |
| `--border` | `#E2E8E5` *(decorative dividers only)* |
| `--border-strong` | `#CBD5D1` *(decorative)* |
| `--border-interactive` | `#7A8683` *(inputs, checkboxes, control boundaries — SC 1.4.11)* |
| `--text-primary` | `#0F1A17` |
| `--text-secondary` | `#44544F` |
| `--text-muted` | `#5E706B` |
| `--text-on-brand` | `#FFFFFF` |
| `--brand-primary` | `#0B4D3F` |
| `--brand-primary-hover` | `#073A30` |
| `--brand-secondary` | `#FC6302` |
| `--brand-secondary-ink` | `#B83E00` *(orange as **text**)* |
| `--brand-secondary-on` | `#3A1200` *(text **on** an orange fill)* |
| `--accent-teal` | `#2EC4A8` |
| `--success` `#15803D` · `--success-bg` | `#ECFDF3` |
| `--warning` `#8A5300` · `--warning-fill` `#FACC15` · `--warning-bg` | `#FEFCE8` |
| `--danger` `#C81E1E` · `--danger-bg` | `#FEF2F2` |
| `--info` `#0059C9` · `--info-bg` | `#E8F2FE` |
| `--focus-ring` | `#0B7D66` (3 px ring, 2 px offset) |

## 5.3 Dark theme tokens

| Token | Hex |
|---|---|
| `--bg` | `#071310` |
| `--bg-subtle` | `#0B1A16` |
| `--surface` | `#122420` |
| `--surface-raised` | `#18302A` |
| `--surface-inverse` | `#ECF5F2` |
| `--border` | `#24403A` *(decorative)* |
| `--border-strong` | `#33544C` *(decorative)* |
| `--border-interactive` | `#5A736C` *(controls — SC 1.4.11)* |
| `--text-primary` | `#ECF5F2` |
| `--text-secondary` | `#ACC2BB` |
| `--text-muted` | `#8AA39C` |
| `--brand-primary` | `#2EC4A8` *(the deep green is the background family in dark mode, so teal takes the interactive role)* |
| `--brand-primary-hover` | `#5FE0B8` |
| `--text-on-brand` | `#04241E` |
| `--brand-secondary` | `#FF9C5C` |
| `--brand-secondary-on` | `#3A1200` |
| `--success` `#4ADE80` · on-fill | `#04241E` |
| `--warning` `#FBBF24` · on-fill | `#3A2600` |
| `--danger` `#FF7B7B` · on-fill | `#2A0A0A` |
| `--info` | `#63B3FF` |
| `--focus-ring` | `#2EC4A8` |

## 5.4 Division accent tokens

| Division | Light ink (text/icon on white) | Fill | Badge bg | Dark theme |
|---|---|---|---|---|
| Life Spring (Health) | `#0F766E` | `#0F766E` | `#E6F7F3` | `#2EC4A8` |
| BrightPath (Education) | `#B83E00` | `#B83E00` | `#FFF3EA` | `#FF9C5C` |
| Legacy of Love (Orphans/Widows) | `#0B4D3F` | `#0B4D3F` | `#ECFAF6` | `#5FE0B8` |
| Every Soul Missions (Evangelism) | `#0059C9` | `#0059C9` | `#E8F2FE` | `#63B3FF` |

Division colour is never the **only** signifier — each division also has a distinct icon and its name in text (WCAG 1.4.1, use of colour).

## 5.5 WCAG 2.2 AA contrast verification

Computed with the WCAG relative-luminance formula. Thresholds: **4.5:1** normal text · **3:1** large text (≥24 px, or ≥18.66 px bold) and non-text UI components/graphics (SC 1.4.11).

### Light theme

| Pair | Ratio | Req | |
|---|---|---|---|
| `#0F1A17` text-primary on `#FFFFFF` | **17.79:1** | 4.5 | ✅ |
| `#0F1A17` on `#F7F9F8` bg-subtle | **16.82:1** | 4.5 | ✅ |
| `#0F1A17` on `#F1F5F4` surface-sunken | **16.19:1** | 4.5 | ✅ |
| `#44544F` text-secondary on `#FFFFFF` | **7.99:1** | 4.5 | ✅ |
| `#44544F` on `#F1F5F4` | **7.27:1** | 4.5 | ✅ |
| `#5E706B` text-muted on `#FFFFFF` | **5.24:1** | 4.5 | ✅ |
| `#5E706B` on `#F7F9F8` | **4.96:1** | 4.5 | ✅ |
| `#5E706B` on `#F1F5F4` | **4.77:1** | 4.5 | ✅ |
| `#5E706B` on `#FFF3EA` orange tint | **4.81:1** | 4.5 | ✅ |
| `#0B4D3F` brand green link on `#FFFFFF` | **9.78:1** | 4.5 | ✅ |
| `#0B4D3F` on `#F1F5F4` | **8.90:1** | 4.5 | ✅ |
| `#0B4D3F` on `#FFF3EA` | **8.97:1** | 4.5 | ✅ |
| `#FFFFFF` on `#0B4D3F` — **primary button** | **9.78:1** | 4.5 | ✅ |
| `#FFFFFF` on `#0A6A58` green-700 — hover/secondary | **6.53:1** | 4.5 | ✅ |
| `#FFFFFF` on `#0B7D66` green-600 | **5.07:1** | 4.5 | ✅ |
| `#3A1200` on `#FC6302` — **DONATE button** | **5.47:1** | 4.5 | ✅ |
| `#0F1A17` on `#FC6302` — alt donate ink | **5.87:1** | 4.5 | ✅ |
| `#B83E00` orange-as-text on `#FFFFFF` | **5.63:1** | 4.5 | ✅ |
| `#B83E00` on `#FFF3EA` | **5.17:1** | 4.5 | ✅ |
| `#B83E00` on `#F1F5F4` | **5.13:1** | 4.5 | ✅ |
| `#FFFFFF` on `#B83E00` orange-700 fill | **5.63:1** | 4.5 | ✅ |
| `#15803D` success text on `#FFFFFF` | **5.02:1** | 4.5 | ✅ |
| `#FFFFFF` on `#15803D` | **5.02:1** | 4.5 | ✅ |
| `#8A5300` warning text on `#FFFFFF` | **6.33:1** | 4.5 | ✅ |
| `#0F1A17` on `#FACC15` warning fill | **11.62:1** | 4.5 | ✅ |
| `#C81E1E` danger text on `#FFFFFF` | **5.74:1** | 4.5 | ✅ |
| `#FFFFFF` on `#C81E1E` | **5.74:1** | 4.5 | ✅ |
| `#0059C9` info text on `#FFFFFF` | **6.42:1** | 4.5 | ✅ |
| `#FFFFFF` on `#0059C9` | **6.42:1** | 4.5 | ✅ |
| `#0F766E` Life Spring ink on `#FFFFFF` | **5.47:1** | 4.5 | ✅ |
| `#0F766E` on `#E6F7F3` badge | **4.94:1** | 4.5 | ✅ |
| `#0059C9` on `#E8F2FE` badge | **5.67:1** | 4.5 | ✅ |
| `#0B4D3F` on `#ECFAF6` badge | **9.11:1** | 4.5 | ✅ |
| `#7A8683` border-interactive vs `#FFFFFF` | **3.77:1** | 3.0 | ✅ |
| `#0B7D66` focus ring vs `#FFFFFF` | **4.45:1** | 3.0 | ✅ |
| `#FC6302` brand orange on `#FFFFFF` — **LARGE text only** | **3.03:1** | 3.0 | ✅ ⚠️ never for body text |

### Light theme — deep-green sections (footer, stat band, CTA band on `#0B4D3F`)

| Pair | Ratio | Req | |
|---|---|---|---|
| `#ECF5F2` on `#0B4D3F` | **8.81:1** | 4.5 | ✅ |
| `#ACC2BB` secondary on `#0B4D3F` | **5.21:1** | 4.5 | ✅ |
| `#FF9C5C` orange on `#0B4D3F` | **4.73:1** | 4.5 | ✅ |
| `#FFC299` orange-200 on `#0B4D3F` | **6.25:1** | 4.5 | ✅ |
| `#5FE0B8` on `#0B4D3F` | **5.98:1** | 4.5 | ✅ |
| `#2EC4A8` teal on `#0B4D3F` — **large text / graphics only** | **4.46:1** | 3.0 | ✅ |

### Dark theme

| Pair | Ratio | Req | |
|---|---|---|---|
| `#ECF5F2` on `#071310` bg | **17.04:1** | 4.5 | ✅ |
| `#ECF5F2` on `#122420` surface | **14.56:1** | 4.5 | ✅ |
| `#ECF5F2` on `#18302A` surface-raised | **12.65:1** | 4.5 | ✅ |
| `#ACC2BB` secondary on `#071310` | **10.08:1** | 4.5 | ✅ |
| `#ACC2BB` on `#122420` | **8.61:1** | 4.5 | ✅ |
| `#ACC2BB` on `#18302A` | **7.48:1** | 4.5 | ✅ |
| `#8AA39C` muted on `#071310` | **7.03:1** | 4.5 | ✅ |
| `#8AA39C` on `#122420` | **6.00:1** | 4.5 | ✅ |
| `#2EC4A8` brand link on `#071310` | **8.63:1** | 4.5 | ✅ |
| `#2EC4A8` on `#122420` | **7.37:1** | 4.5 | ✅ |
| `#04241E` on `#2EC4A8` — primary button | **7.50:1** | 4.5 | ✅ |
| `#FF9C5C` on `#071310` | **9.16:1** | 4.5 | ✅ |
| `#FF9C5C` on `#122420` | **7.82:1** | 4.5 | ✅ |
| `#3A1200` on `#FF9C5C` — donate button | **8.02:1** | 4.5 | ✅ |
| `#FC6302` on `#071310` | **6.24:1** | 4.5 | ✅ |
| `#3A1200` on `#FC6302` | **5.47:1** | 4.5 | ✅ |
| `#4ADE80` success on `#071310` | **10.86:1** | 4.5 | ✅ |
| `#04241E` on `#4ADE80` | **9.44:1** | 4.5 | ✅ |
| `#FBBF24` warning on `#071310` | **11.34:1** | 4.5 | ✅ |
| `#3A2600` on `#FBBF24` | **8.63:1** | 4.5 | ✅ |
| `#FF7B7B` danger on `#071310` | **7.54:1** | 4.5 | ✅ |
| `#2A0A0A` on `#FF7B7B` | **7.30:1** | 4.5 | ✅ |
| `#63B3FF` info on `#071310` | **8.49:1** | 4.5 | ✅ |
| `#5FE0B8` Legacy accent on `#071310` | **11.57:1** | 4.5 | ✅ |
| `#5FE0B8` on `#122420` | **9.89:1** | 4.5 | ✅ |
| `#63B3FF` on `#122420` | **7.25:1** | 4.5 | ✅ |
| `#5A736C` border-interactive vs `#071310` | **3.70:1** | 3.0 | ✅ |
| `#2EC4A8` focus ring vs `#071310` | **8.63:1** | 3.0 | ✅ |

### 🚫 Combinations that **fail** and are therefore banned in code

Recorded so nobody "helpfully" reintroduces them later. A Pest test will assert the token pairs, and the Filament colour picker will refuse them.

| Banned pair | Ratio | Why it fails |
|---|---|---|
| `#FFFFFF` on `#FC6302` (white on brand orange) | **3.03:1** | The obvious "white text on the donate button" — fails AA. Use `#3A1200` ink on orange, or white on `#B83E00`. |
| `#FC6302` on `#FFFFFF` as **body** text | **3.03:1** | Large text only (≥24 px). Body copy uses `#B83E00`. |
| `#FFFFFF` on `#E14E00` orange-600 | **3.97:1** | Close but short. `orange-600` is a fill for dark ink only. |
| `#FFFFFF` on `#0D8770` | **4.45:1** | Fails by 0.05. Use `#0B7D66` or darker. |
| `#0B4D3F` on `#2EC4A8` | **4.46:1** | Fails by 0.04. Ink on teal is `#04241E`. |
| `#F59E0B` amber as an icon on white | **2.15:1** | Non-text still needs 3:1. Use `#8A5300`. |
| `#2EC4A8` teal as text on white | **2.19:1** | Teal is a dark-theme and fill colour, never light-theme text. |
| Deep-green wordmark logo on a `#0B4D3F` section | **1.00:1** | Invisible. Requires the white mono logo (§2.3). |
| `#E2E8E5` / `#CBD5D1` as an input border | 1.24 / 1.50:1 | Decorative dividers only. Control boundaries use `--border-interactive`. |

## 5.6 Type scale

**Families (proposed — needs your confirmation, §12c).** Self-hosted `woff2`, latin subset, `font-display: swap`, preloaded, **no Google Fonts CDN** (an extra DNS + TLS round trip is expensive on Ghanaian mobile networks, and it is a third-party disclosure we do not need under Act 843).

- **Display / headings:** a heavy rounded geometric sans matching the wordmark — *Plus Jakarta Sans* or *Poppins* (variable, ~28 KB subset)
- **Body / UI:** *Inter* variable (~30 KB subset)
- **Fallback stack:** `system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif`
- Total web-font budget: **≤ 70 KB**. If the confirmed brand typeface is a paid font, we ship one weight only.

**Scale** (1.200 minor-third on mobile, 1.250 major-third from `md` up; fluid via `clamp()` for display sizes):

| Token | Mobile | Desktop | Line-height | Tracking | Use |
|---|---|---|---|---|---|
| `text-2xs` | 11px | 11px | 1.45 | +0.04em | Legal microcopy, table meta |
| `text-xs` | 12px | 12px | 1.5 | +0.02em | Captions, badges, form hints |
| `text-sm` | 14px | 14px | 1.55 | 0 | Secondary UI, table cells |
| `text-base` | **16px** | 16px | **1.65** | 0 | Body — never below 16 px |
| `text-lg` | 18px | 18px | 1.6 | 0 | Lead paragraphs, card titles |
| `text-xl` | 20px | 22px | 1.45 | −0.005em | Section sub-headings (h4) |
| `text-2xl` | 24px | 28px | 1.35 | −0.01em | h3 |
| `text-3xl` | 28px | 34px | 1.25 | −0.015em | h2 |
| `text-4xl` | 32px | 42px | 1.2 | −0.02em | Page titles (h1) |
| `text-5xl` | 38px | 56px | 1.1 | −0.025em | Hero headline |
| `text-6xl` | 44px | 68px | 1.05 | −0.03em | Impact stat numbers |

Weights: 400 / 500 / 600 / 700 / 800. Measure: **60–75 characters** (`max-w-[68ch]`) for prose. `text-4xl`+ never uses `font-weight: 400`.

## 5.7 Spacing scale

4 px base unit. `space-{n}` = `n × 4px`.

`0` · `0.5`=2 · `1`=4 · `1.5`=6 · `2`=8 · `3`=12 · `4`=16 · `5`=20 · `6`=24 · `8`=32 · `10`=40 · `12`=48 · `16`=64 · `20`=80 · `24`=96 · `32`=128 · `40`=160

- **Section rhythm:** mobile `py-16` (64px) → `md:py-20` (80px) → `lg:py-24` (96px) → hero/feature `lg:py-32`
- **Container:** `max-w-[1280px]`, gutters `px-4` mobile / `px-6` md / `px-8` lg
- **Grid gap:** `gap-4` mobile / `gap-6` md / `gap-8` lg
- **Minimum tap target: 44 × 44 px.** (WCAG 2.2 SC 2.5.8 requires 24×24; we hold 44 because of low-end Android touch accuracy.)

## 5.8 Radius scale

The logo is built entirely from soft organic curves, so the system leans generous.

| Token | Value | Use |
|---|---|---|
| `radius-xs` | 4px | Tags, chips, inline code |
| `radius-sm` | 6px | Small badges |
| `radius-md` | 10px | Inputs, selects, small buttons |
| `radius-lg` | 14px | Cards, alerts, dropdowns |
| `radius-xl` | 20px | Feature cards, modals |
| `radius-2xl` | 28px | Hero panels, media frames, curved section masks |
| `radius-3xl` | 40px | Large decorative image frames |
| `radius-full` | 9999px | **All primary/secondary buttons (pill)**, avatars, progress bars, tabs |

## 5.9 Shadow scale

Light theme — tinted with the brand green rather than pure black, so shadows sit in the same temperature as the surfaces:

| Token | Value |
|---|---|
| `shadow-xs` | `0 1px 2px 0 rgb(7 19 16 / 0.05)` |
| `shadow-sm` | `0 1px 3px 0 rgb(7 19 16 / 0.08), 0 1px 2px -1px rgb(7 19 16 / 0.06)` |
| `shadow-md` | `0 4px 8px -2px rgb(7 19 16 / 0.10), 0 2px 4px -2px rgb(7 19 16 / 0.06)` |
| `shadow-lg` | `0 12px 20px -6px rgb(7 19 16 / 0.12), 0 4px 8px -4px rgb(7 19 16 / 0.08)` |
| `shadow-xl` | `0 24px 40px -12px rgb(7 19 16 / 0.16), 0 8px 16px -8px rgb(7 19 16 / 0.10)` |
| `shadow-brand` | `0 10px 28px -8px rgb(252 99 2 / 0.40)` — donate CTA only |
| `shadow-focus` | `0 0 0 3px rgb(11 125 102 / 0.45)` |

**Dark theme:** shadows barely read on dark surfaces. Elevation is expressed by **surface lightness + a 1 px border**, not shadow. `--shadow-*` collapses to `0 1px 2px rgb(0 0 0 / 0.4)` at all levels, and `shadow-brand` becomes a soft teal glow `0 0 0 1px rgb(46 196 168 / 0.25)`.

## 5.10 Motion & theme-switch tokens

- `duration-fast` 120ms · `duration-base` 200ms · `duration-slow` 320ms · easing `cubic-bezier(0.4, 0, 0.2, 1)`
- **Every** transition and animation wrapped in `@media (prefers-reduced-motion: no-preference)`
- **Theme switch:** an inline `<head>` script (before any CSS) reads `localStorage.theme` → falls back to a `theme` cookie (so the server can render the right class and avoid a flash) → falls back to `prefers-color-scheme`. It sets `class="dark"` + `data-theme` on `<html>` synchronously. The toggle cycles **light → dark → system**, writes both `localStorage` and a 1-year cookie, and announces the change via `aria-live`. `<meta name="theme-color">` is updated per theme.

---

# 6. Sitemap / Information architecture

## 6.1 Public site

```
/                                       Home
│
├── /about                              About the Foundation
│   ├── /about/our-story                Our Story (Mrs Cecilia Anyatuik Adam, the legacy)
│   ├── /about/vision-mission           Vision, Mission, Motto, What We Believe
│   ├── /about/core-values              The seven core values
│   ├── /about/leadership               Board of trustees / leadership / staff  [needs data]
│   ├── /about/how-we-work              Our Approach — identify · assess · support · follow up
│   ├── /about/transparency             Governance, annual reports, financial summaries, policies
│   └── /about/partners                 Partners & supporters
│
├── /divisions                          The Four Divisions (overview)
│   ├── /divisions/life-spring-foundation        Health
│   ├── /divisions/brightpath-fund-initiative    Education
│   ├── /divisions/legacy-of-love-initiative     Orphans, Widows & Widowers
│   └── /divisions/every-soul-missions           Evangelism
│        └── each: intro · focus areas · who we serve · projects · causes · impact · stories · donate CTA
│
├── /projects                           Project index (filter: division, region, status, year)
│   └── /projects/{slug}                Project detail — gallery, updates, milestones, docs, location, donate
│
├── /causes                             Causes / campaigns index (filter: division, urgency, progress)
│   └── /causes/{slug}                  Cause detail — goal, raised, progress, story, updates, donor wall
│
├── /impact                             Impact dashboard — metrics, map, beneficiary numbers, annual report
│   └── /impact/stories                 Beneficiary stories index
│       └── /impact/stories/{slug}      Story detail  [consent-gated]
│
├── /donate                             Donation landing (designation, amount, one-off/recurring)
│   ├── /donate/{division-or-cause}     Pre-designated donation
│   ├── /donate/checkout                Donor details + payment method
│   ├── /donate/processing/{ref}        Awaiting webhook confirmation
│   ├── /donate/thank-you/{ref}         Success — receipt, share, recurring upsell
│   ├── /donate/failed/{ref}            Failure with retry
│   ├── /donate/other-ways              Bank transfer, MoMo direct, cheque, in-kind  [needs bank data]
│   └── /donate/faq                     Donation FAQ
│
├── /sponsor-a-child                    Child sponsorship (BrightPath) — recurring product
│
├── /shop                               Shop home
│   ├── /shop/category/{slug}
│   ├── /shop/product/{slug}
│   ├── /shop/cart
│   ├── /shop/checkout                  Guest or account; delivery / pickup / digital
│   ├── /shop/order/processing/{ref}
│   ├── /shop/order/confirmation/{ref}
│   └── /shop/track-order               Order lookup by reference + email
│
├── /get-involved                       Ways to help — hub
│   ├── /get-involved/volunteer         Volunteer overview
│   │   ├── /volunteer/opportunities    Open roles (filter: division, region, skill, remote)
│   │   ├── /volunteer/opportunities/{slug}
│   │   └── /volunteer/apply/{slug}     Application form
│   ├── /get-involved/partner           Corporate / church / institutional partnership enquiry
│   ├── /get-involved/fundraise         Peer-to-peer fundraising  [if approved — §12c]
│   │   ├── /fundraise/start
│   │   └── /fundraisers/{slug}
│   ├── /get-involved/in-kind           Donate goods (food, clothing, books, medical supplies)
│   └── /get-involved/pray              Prayer requests & prayer partner sign-up (Every Soul Missions)
│
├── /events                             Events index
│   ├── /events/{slug}                  Event detail
│   └── /events/{slug}/register         Registration / ticketing
│
├── /news                               News & blog index (filter: category, division, tag)
│   ├── /news/{slug}
│   ├── /news/category/{slug}
│   └── /news/tag/{slug}
│
├── /gallery                            Photo & video galleries
│   └── /gallery/{slug}
│
├── /downloads                          Documents — annual reports, policies, brochures, forms
│
├── /contact                            Contact — department routing, map, hours, WhatsApp
│
├── /faq                                General FAQ (categorised)
│
├── /newsletter/confirm/{token}         Double opt-in confirmation
├── /newsletter/unsubscribe/{token}     One-click unsubscribe
│
├── Legal & trust
│   ├── /privacy-policy                 Act 843 compliant
│   ├── /terms                          Terms of use
│   ├── /donation-policy                How donations are used, allocation, restricted giving
│   ├── /refund-policy                  Donation & shop refund policy   (Paystack requires this)
│   ├── /shipping-and-delivery          Delivery zones, times, costs    (Paystack requires this)
│   ├── /cookie-policy                  + preference centre
│   ├── /safeguarding                   Child protection & beneficiary dignity policy
│   ├── /accessibility                  Accessibility statement + how to report a barrier
│   └── /whistleblowing                 Confidential concerns channel
│
└── System
    ├── /search                         (post-launch)
    ├── /sitemap.xml  /sitemap-{n}.xml  /robots.txt  /rss
    ├── /offline                        PWA offline fallback
    ├── 404 / 419 / 429 / 500 / 503     Branded error pages
    └── /health                         Uptime probe (unlisted)
```

## 6.2 Donor account area (`/account/*`, auth required)

| Route | Purpose |
|---|---|
| `/account` | Dashboard — lifetime giving, active recurring plans, next charge date, impact summary |
| `/account/donations` | Donation history, filter by year/division, download receipts |
| `/account/donations/{ref}` | Single donation + PDF receipt |
| `/account/recurring` | Active plans: pause, resume, change amount, change designation, **cancel** |
| `/account/recurring/{id}/charges` | Charge history for a plan |
| `/account/receipts/annual/{year}` | Consolidated annual giving statement (PDF) |
| `/account/orders` | Shop order history |
| `/account/orders/{ref}` | Order detail, status timeline, tracking, invoice, digital downloads |
| `/account/sponsorships` | Sponsored children/students, updates from the beneficiary |
| `/account/fundraisers` | My peer-to-peer pages *(if enabled)* |
| `/account/volunteering` | My applications, approved roles, logged hours |
| `/account/events` | My event registrations & tickets |
| `/account/profile` | Name, phone, address, photo |
| `/account/communication-preferences` | Email/SMS opt-ins per channel & topic, unsubscribe-all |
| `/account/security` | Password, **2FA**, active sessions, login history |
| `/account/privacy` | **Act 843 rights:** export my data, request deletion, consent log |
| `/account/payment-methods` | Saved Paystack authorisation codes (last4 + brand only), remove |
| `/login` `/register` `/forgot-password` `/reset-password` `/verify-email` `/two-factor-challenge` | Auth |

## 6.3 Admin / CMS (Filament, `/admin`)

**Dashboard** — donations today/week/month/YTD, recurring MRR, active donors, failed payments needing attention, webhook health, pending volunteer applications, unread contact messages, low-stock products, orders to fulfil, backup status, queue health.

| Group | Resources / screens |
|---|---|
| **Fundraising** | Donations (list, detail, refund, resend receipt) · Offline/cash donations (manual entry) · Donors (profile, giving history, lifetime value, tags, merge duplicates) · Recurring plans & subscriptions · Subscription charges · Pledges · Donation designations · **Causes/Campaigns** (goal, progress, updates) · Donation goals · Peer-to-peer fundraisers (approve/reject) · Receipts · Refunds · **Payment transactions** · **Webhook events** (raw payload, replay, signature status) · **Paystack reconciliation** (daily settlement vs our ledger) · Payouts |
| **Shop** | Products · Variants & options · Categories · Inventory & stock movements · Orders (status workflow, fulfilment, notes) · Order statuses · Shipping zones (Ghana's 16 regions) & rates · Delivery methods · Coupons & redemptions · Invoices · Digital download tokens · Product reviews (moderation) · Abandoned carts |
| **Programmes** | Divisions · Focus areas · Projects (updates, milestones, gallery, documents, partners, locations) · Project locations (region/district) · Beneficiaries *(restricted)* · Impact metrics & values · Beneficiary stories *(consent-gated)* |
| **Content (CMS)** | Pages & page-builder blocks · Homepage sections · Menus (nested drag-and-drop) · Banners/sliders · Blog posts, categories, tags · Comments (moderation) · FAQs & FAQ categories · Testimonials · Partners · Team members & departments · Galleries · Documents/downloads · Announcements · Popups · Page revisions · **Redirects (incl. the 404→redirect workflow)** · SEO meta |
| **Engagement** | Volunteer opportunities · Volunteer applications (approve/reject/interview) · Volunteers & logged hours · Events · Event registrations & tickets · Prayer requests · Newsletter subscribers · Newsletter campaigns (compose, test-send, schedule, stats) · Contact messages (assign, reply, resolve) · Contact departments |
| **Communications** | Email templates (per-event, with a variable helper + live preview) · SMS templates (with a GSM-7 character counter & segment/cost estimate) · Email log · SMS log · Notification log · Scheduled messages |
| **Appearance** | Theme settings (colours light+dark with **live AA contrast validation**) · Logos per variant & theme · Typography · Header layout · Footer layout & columns · Social links · Favicon & OG defaults · Custom CSS (guarded) |
| **Settings** | General (name, legal name, registration no., TIN) · Contact & addresses · Payments (Paystack keys, fee-cover toggle, min/max, presets) · Donations (designations, General Fund fallback, receipt numbering) · Shop (currency, tax, order-number format) · Email (driver, from, reply-to) · SMS (provider, sender ID, balance) · SEO defaults · Analytics · Social · Legal page mapping · Maintenance mode · Feature flags · Cookie/consent config |
| **System** | Users · Roles & permissions · Admin activity log · Login history · Failed jobs (retry/flush) · Queue monitor · Scheduled task monitor · Backups (run, download, **restore-test log**) · Error reports · Health check · Media library & folders · Import/export |

---

# 7. Roles and permissions

Implemented with `spatie/laravel-permission`. **Permissions are checked, never roles** (`@can('donations.refund')`, not `@role('finance')`) so the matrix can change without touching code. All admin roles require **2FA**; the Super Admin role cannot be deleted or have 2FA disabled.

## 7.1 Capability matrix

Legend: **✔** full · **◐** limited / own-records-only · **👁** read-only · **—** no access

| Capability | Super Admin | Admin | Content Editor | Finance Officer | Shop Manager | Volunteer Coord. | Donor | Guest |
|---|---|---|---|---|---|---|---|---|
| **Content** | | | | | | | | |
| Pages, blocks, homepage sections | ✔ | ✔ | ✔ | — | — | — | — | — |
| Publish / unpublish content | ✔ | ✔ | ✔ | — | — | — | — | — |
| Menus & navigation | ✔ | ✔ | ✔ | — | — | — | — | — |
| Blog, FAQs, testimonials, galleries | ✔ | ✔ | ✔ | — | — | — | — | — |
| Projects, causes, impact metrics | ✔ | ✔ | ✔ | 👁 | — | 👁 | — | — |
| Beneficiary stories (consent-gated) | ✔ | ✔ | ◐ *needs consent record* | — | — | — | — | — |
| Theme & appearance settings | ✔ | ✔ | ◐ *content only, not colours* | — | — | — | — | — |
| Media library | ✔ | ✔ | ✔ | 👁 | ◐ *product media* | ◐ *volunteer media* | — | — |
| Redirects / 404 workflow | ✔ | ✔ | ✔ | — | — | — | — | — |
| **Fundraising** | | | | | | | | |
| View donations | ✔ | ✔ | — | ✔ | — | — | ◐ *own* | — |
| Donor PII (full contact details) | ✔ | ✔ | — | ✔ | — | — | ◐ *own* | — |
| Record offline / cash donation | ✔ | ✔ | — | ✔ | — | — | — | — |
| Issue / resend receipt | ✔ | ✔ | — | ✔ | — | — | ◐ *own* | — |
| **Initiate refund** | ✔ | ✔ | — | ✔ *(≤ limit; above → Admin approval)* | — | — | — | — |
| Manage causes & goals | ✔ | ✔ | ✔ | ✔ | — | — | — | — |
| Recurring plans (pause/cancel on behalf) | ✔ | ✔ | — | ✔ | — | — | ◐ *own* | — |
| Payment transactions & webhook events | ✔ | ✔ | — | 👁 | — | — | — | — |
| **Replay a webhook event** | ✔ | ✔ | — | — | — | — | — | — |
| Paystack reconciliation | ✔ | ✔ | — | ✔ | — | — | — | — |
| Export financial reports | ✔ | ✔ | — | ✔ | — | — | — | — |
| **Shop** | | | | | | | | |
| Products, variants, inventory | ✔ | ✔ | ◐ *descriptions/images* | — | ✔ | — | — | — |
| Orders & fulfilment | ✔ | ✔ | — | 👁 | ✔ | — | ◐ *own* | — |
| Refund a shop order | ✔ | ✔ | — | ✔ | ◐ *request only* | — | — | — |
| Coupons, shipping zones & rates | ✔ | ✔ | — | 👁 | ✔ | — | — | — |
| Moderate product reviews | ✔ | ✔ | ✔ | — | ✔ | — | ◐ *own* | — |
| **Engagement** | | | | | | | | |
| Volunteer opportunities | ✔ | ✔ | ◐ *copy only* | — | — | ✔ | — | — |
| Volunteer applications & PII | ✔ | ✔ | — | — | — | ✔ | ◐ *own* | — |
| Log volunteer hours | ✔ | ✔ | — | — | — | ✔ | ◐ *own* | — |
| Events & registrations | ✔ | ✔ | ✔ | 👁 *ticket revenue* | — | ✔ | ◐ *own* | — |
| Contact messages | ✔ | ✔ | ✔ | ◐ *finance dept* | ◐ *shop dept* | ◐ *volunteer dept* | — | — |
| Newsletter subscribers | ✔ | ✔ | 👁 | — | — | — | ◐ *own prefs* | — |
| **Send** a newsletter campaign | ✔ | ✔ | ◐ *draft + test-send only* | — | — | — | — | — |
| Prayer requests | ✔ | ✔ | ◐ *if not private* | — | — | — | ◐ *own* | — |
| **Communications** | | | | | | | | |
| Email & SMS templates | ✔ | ✔ | ✔ | ◐ *finance templates* | ◐ *order templates* | ◐ *volunteer templates* | — | — |
| Email/SMS logs | ✔ | ✔ | 👁 | 👁 | 👁 | 👁 | — | — |
| **System** | | | | | | | | |
| Users & role assignment | ✔ | ◐ *cannot create/edit Admin+* | — | — | — | — | — | — |
| Roles & permissions definition | ✔ | — | — | — | — | — | — | — |
| Payment gateway keys | ✔ | — | — | 👁 *masked* | — | — | — | — |
| Global settings | ✔ | ✔ | — | — | — | — | — | — |
| Feature flags, maintenance mode | ✔ | ✔ | — | — | — | — | — | — |
| Backups & restore | ✔ | ◐ *run + download, no restore* | — | — | — | — | — | — |
| Activity log & login history | ✔ | ✔ | 👁 *own* | 👁 *own* | 👁 *own* | 👁 *own* | ◐ *own* | — |
| Failed jobs / queue | ✔ | ✔ | — | — | — | — | — | — |
| Access `/admin` at all | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✖ | ✖ |
| **2FA required** | **Yes** | **Yes** | **Yes** | **Yes** | **Yes** | **Yes** | Optional (encouraged) | — |

## 7.2 Guest (unauthenticated) can

Browse all public content · **donate one-off without an account** · subscribe to the newsletter (double opt-in) · submit a contact enquiry (honeypot + rate-limited) · apply to volunteer · register for an event · **shop as a guest** · look up an order by reference + email · submit a prayer request · download public documents.

Guest **cannot**: create a recurring plan (an account is required so it can be managed and cancelled), access `/account/*`, or comment on the blog.

## 7.3 Roles I recommend adding

| Role | Why |
|---|---|
| **Programme Officer** (one per division) | Each of the four divisions will want to publish its own projects, updates and impact numbers without editing the others'. Scoped by `division_id`. |
| **Auditor / Trustee (read-only finance)** | Board members and external auditors need to see the financial picture with no write capability. A real governance requirement for a registered NGO. |
| **Support / Front Desk** | Answer contact messages, look up a donation, resend a receipt — nothing else. Keeps the Finance role small. |

---

# 8. User journeys

Notation: **[U]** user action · **[S]** system action · **[A]** admin action · ⚠️ failure branch.

## 8.1 One-off donation (card, guest)

1. **[U]** Lands on `/`, `/causes/{slug}`, or a division page; taps **Donate**.
2. **[S]** The donation form pre-fills the designation from context (cause → division → **General Fund** fallback, so a donation can never be undesignated).
3. **[U]** Picks a preset (`GH₵ 50 / 100 / 250 / 500 / 1,000`) or enters a custom amount.
4. **[S]** Converts to **integer pesewas** immediately (`GH₵ 250 → 25000`). Validates against `min_donation` / `max_donation`. Displays `GH₵ 250.00`.
5. **[U]** Optionally toggles **"Cover the transaction fee (+GH₵ X.XX)"**; optionally marks the gift **in honour of / in memory of** someone (a natural fit for this foundation) with an optional notification email.
6. **[U]** Enters name, email, phone; ticks **consent to be contacted** (separate, unticked checkboxes for email and SMS); optionally **"Give anonymously"** (hidden from the public donor wall, still recorded internally).
7. **[U]** Chooses **Card**. Submits.
8. **[S]** Honeypot + rate-limit + CSRF checks. Creates a `donations` row: `status=pending`, unique **ULID reference** `SCGHF-D-XXXXXXXX`, `amount_minor`, `currency=GHS`, `designation`, consent snapshot, IP, UA.
9. **[S]** `PaystackService::initializeTransaction()` → `POST /transaction/initialize` with `amount` (pesewas), **`currency: "GHS"`**, `reference`, `email`, `callback_url`, `metadata{donation_id, designation, cover_fee}`. Records a `payment_transactions` row.
10. **[S]** Redirects to Paystack's hosted checkout. **We never see card data** (PCI DSS SAQ-A).
11. **[U]** Pays on Paystack.
12. **[S]** ⚡ **Webhook** `POST /webhooks/paystack` → read the **raw** body → `hash_hmac('sha512', $raw, $secret)` → `hash_equals()` against `x-paystack-signature` → ⚠️ mismatch = log + `401`, no processing → store the raw event in `payment_webhook_events` → **respond `200` immediately** → dispatch `ProcessPaystackWebhook` to the queue.
13. **[S]** Queue job (idempotent, keyed on `event.id` + `reference`): ⚠️ already processed → no-op. Re-verifies `amount` **and** `currency` against the stored expectation. ⚠️ mismatch → `needs_review`, alert Finance, **do not** mark complete. Match → `donations.status = completed`, `paid_at`, `paystack_reference`, `channel`, `fees`; atomically increments the cause's `raised_minor`.
14. **[S]** Generates a numbered **PDF receipt**; queues the receipt email + a short thank-you SMS (if consented).
15. **[U]** The browser returns to `/donate/processing/{ref}`, which polls (or listens) until the webhook lands, then redirects to `/donate/thank-you/{ref}`. **The redirect never marks anything paid.**
16. **[U]** Thank-you page: receipt download, "what your gift does", share buttons, **"Make this monthly?"** upsell, newsletter opt-in.
17. ⚠️ **Webhook never arrives:** a scheduled `ReconcilePendingTransactions` job re-queries Paystack `GET /transaction/verify/{reference}` for anything `pending` older than 10 minutes and settles it. Anything still pending after 24 h is flagged to Finance.

## 8.2 Recurring donation (monthly)

1. **[U]** On `/donate` selects **Monthly** (or Weekly / Quarterly / Annually).
2. **[S]** Requires an account (so the donor can manage and cancel it). Offers register / login / **"create my account with this donation"**.
3. **[U]** Sets amount + designation; confirms.
4. **[S]** Creates a Paystack **Plan** (or reuses one matching amount + interval + currency), then initialises a transaction with `plan`. GHS throughout.
5. **[U]** Completes the first charge on Paystack.
6. **[S]** Webhooks: `charge.success` → first donation recorded; `subscription.create` → local `subscriptions` row with `paystack_subscription_code`, `email_token`, `next_payment_date`, status `active`.
7. **[S]** Every subsequent cycle: `charge.success` → a new `donations` row linked to the subscription, plus a `subscription_charges` row and a receipt.
8. ⚠️ `invoice.payment_failed` → mark the charge failed, email the donor with a card-update link, retry per policy, alert Finance after N failures.
9. ⚠️ `subscription.disable` / `subscription.not_renew` → mark cancelled, send a warm "thank you for X months of support" email, notify Finance.
10. **[U]** In `/account/recurring`: pause, resume, change amount (cancel + recreate), change designation, or **cancel** (Paystack `subscription/disable` with `code` + `email_token`). Cancellation is **one click, no retention dark patterns**, and confirmed by email.
11. **[S]** Monthly "your impact this month" email. Annual consolidated giving statement PDF.

## 8.3 Mobile Money donation (MTN MoMo / Telecel Cash / AT Money) — **the dominant method in Ghana**

1. **[U]** Selects **Mobile Money** on the donation form.
2. **[U]** Enters the MoMo number and picks the network. The field uses `inputmode="tel"`, accepts `024…` / `+23324…` / spaced formats, and is normalised server-side to E.164 `+233…`.
3. **[S]** Initialises the Paystack transaction with `currency: "GHS"` and `channels: ["mobile_money"]`, passing the phone + provider so Paystack can trigger the USSD prompt directly.
4. **[U]** Receives the prompt on their handset and enters their MoMo PIN. **This can take 30–120 seconds and may require the user to dial `*170#` manually.**
5. **[S]** `/donate/processing/{ref}` shows a **MoMo-specific waiting state**: a clear "check your phone and approve the prompt" message, network-specific fallback instructions, a live timer, and a "didn't get the prompt?" help panel. **No auto-redirect to failure before 180 seconds.**
6. **[S]** `charge.success` webhook → identical processing to §8.1 step 13. Some MoMo flows return `send_otp` / `pending` interim states — these are stored, not treated as success.
7. ⚠️ Timeout / user cancels / insufficient funds → a friendly failure page naming the likely cause, a **Retry** button that reuses the same designation, and an offer to switch to card.
8. **[S]** Receipt by email **and SMS**. For MoMo, phone is required and email is optional — the SMS receipt is the primary receipt.

> **Design consequence:** MoMo is not a secondary option. It is listed **first** on the payment selector, with card second.

## 8.4 Shop purchase

1. **[U]** Browses `/shop`, opens a product, picks a variant, adds to cart.
2. **[S]** Cart in a session (guest) or DB (logged in), merged on login. Stock checked on add **and again** at checkout.
3. **[U]** `/shop/cart` → adjust quantities → checkout.
4. **[U]** Guest or account. Enters contact + delivery details. Chooses **Delivery** (Ghana region → district → shipping rate), **Pickup**, or **Digital download**.
5. **[S]** Recomputes the entire total **server-side** in pesewas: items + shipping + tax − coupon. The client total is never trusted.
6. **[U]** Applies a coupon (validated: active, in date, usage limit, min spend, product eligibility).
7. **[U]** Places the order.
8. **[S]** Creates `orders` (`status=pending_payment`, reference `SCGHF-O-XXXXXXXX`) + `order_items` snapshotting name, SKU and **price at time of order**. **Reserves stock** with a hold expiry.
9. **[S]** Paystack initialise → the same `PaystackService`, the same webhook endpoint, a polymorphic `payable`.
10. **[S]** `charge.success` → verify amount + currency → `status=paid` → **decrement stock** inside a transaction → invoice PDF → confirmation email + SMS → notify the Shop Manager → issue signed, expiring download tokens for digital items.
11. ⚠️ Payment fails/abandoned → release the stock hold after N minutes; abandoned-cart email at +1 h and +24 h (if consented).
12. **[A]** Shop Manager: `paid → processing → shipped → delivered` (or `ready_for_pickup → collected`). Every transition emails/SMSes the customer and is logged in `order_status_histories`.
13. **[U]** Tracks at `/shop/track-order` (reference + email) or in `/account/orders`.
14. ⚠️ Refund: Finance initiates → Paystack refund API → `refunds` row → stock restored if returned → refund confirmation email.

## 8.5 Volunteer sign-up

1. **[U]** `/get-involved/volunteer` → `/volunteer/opportunities`, filters by division, region, skill, time commitment, remote/in-person.
2. **[U]** Opens an opportunity → role, division, location, dates, hours, requirements, contact.
3. **[U]** `/volunteer/apply/{slug}`: name, email, phone, region/district, occupation/skills, availability, motivation, referee, **police-check / safeguarding declaration if the role involves children**, CV upload (PDF/DOCX, ≤5 MB, MIME-validated, stored outside the web root), consent checkbox.
4. **[S]** Honeypot + rate limit + validation. Creates `volunteer_applications` (`status=submitted`) and a `volunteers` record. Confirmation email to the applicant; notification to the **Volunteer Coordinator**.
5. **[A]** Coordinator reviews → `shortlisted` → `interview_scheduled` → `approved` / `rejected` / `waitlisted`, with an internal note. Each transition can fire a templated email.
6. **[S]** On approval: welcome email, safeguarding/code-of-conduct pack, and an **account invitation** so they can see their assignments.
7. **[U]** In `/account/volunteering`: view assignments, log hours (Coordinator approves), download a service certificate.
8. **[A]** Coordinator reports on hours by division, region and period.

## 8.6 Newsletter sign-up

1. **[U]** Enters an email in the footer, an inline block, or a (delayed, dismissible, once-per-30-days) popup.
2. **[U]** Ticks the **explicit, unticked consent box** ("Yes, email me updates…") beside a link to the privacy policy. *(Act 843 requires consent to be freely given, specific and informed — a pre-ticked box is not consent.)*
3. **[S]** Honeypot + timing check + rate limit + MX/DNS-level email validation.
4. **[S]** Creates `subscribers` with `status=pending`, a signed token, and a **consent record** (timestamp, IP, source URL, the exact consent text shown).
5. **[S]** Sends a **double opt-in** confirmation email. *(Single opt-in is the fastest way to destroy the domain's sending reputation.)*
6. **[U]** Clicks confirm → `/newsletter/confirm/{token}` → `status=confirmed`, `confirmed_at` recorded → welcome email.
7. ⚠️ Not confirmed within 7 days → reminder; within 30 days → purged.
8. **[U]** Every campaign carries a **one-click unsubscribe** link **and** a `List-Unsubscribe` header. Unsubscribe is instant — no login, no confirmation page.
9. **[A]** Admin composes a campaign, previews, sends a test, schedules. **Sending is throttled and chunked through the cron-driven queue** so shared-hosting mail limits are not breached (§10.3).

## 8.7 Contact enquiry

1. **[U]** `/contact` — address, phone (`tel:`), WhatsApp (`wa.me`), email, hours, map, and a department selector: General · Donations & Finance · Volunteering · Shop & Orders · Media & Partnerships · **Safeguarding concern**.
2. **[U]** Fills name, email, phone (optional), subject, message; ticks consent.
3. **[S]** `spatie/laravel-honeypot` + timing trap + rate limit (per IP and per email) + strict validation + HTML stripping.
4. **[S]** Creates `contact_messages` with department, IP, UA, referrer. Auto-reply to the sender with a reference number; notification to the **department's routing address** (`contact_departments.email`).
5. **[A]** Admin sees it in the Filament inbox → assign → reply (logged against the thread) → `resolved` / `spam`. SLA timer visible.
6. ⚠️ A **Safeguarding** submission bypasses the normal inbox: it goes only to the designated safeguarding contact, is excluded from the general admin listing, and is flagged in the activity log.

## 8.8 Admin content update (the CMS proof)

1. **[A]** A Content Editor logs in at `/admin` → email + password → **2FA code**.
2. **[A]** Content → Pages → *Home* → the **page-builder block list**.
3. **[A]** Drags a **Featured Causes** block above **Impact Stats**; opens the **Hero** block and changes the headline, sub-headline, background image (media library or upload), and both CTA labels/links.
4. **[S]** The uploaded image is validated by MIME **and** content, given a safe filename, **EXIF/GPS stripped**, converted to WebP/AVIF, responsive sizes generated, and alt text is **required before save**.
5. **[A]** Clicks **Preview** → a signed, noindexed preview URL renders the draft in both light and dark theme.
6. **[A]** Saves → a `page_revisions` snapshot is written; `admin_activity_log` records who changed what, field by field.
7. **[S]** The page's fragment cache and the settings cache are invalidated. **No deployment, no developer, no code change.**
8. **[A]** If it went wrong: **Revisions → restore** to any previous version.
9. **[A]** The same flow edits: header, footer, every menu, phone numbers, email addresses, social links, theme colours, email/SMS templates, SEO meta, and the legal pages. **Nothing on the public site is hardcoded.**

---

# 9. Module list

| # | Module | One-line purpose |
|---|---|---|
| 1 | **Core & Auth** | Users, sessions, password reset, email verification, 2FA, login history, roles & permissions. |
| 2 | **Settings & Configuration** | Grouped, typed, cached key/value settings that make every operational value editable without deployment. |
| 3 | **Theme & Appearance** | Light/dark colour tokens, typography, logo variants and layout options, editable in Filament with AA validation. |
| 4 | **CMS — Pages & Blocks** | Flexible page builder: pages composed from a curated library of content blocks, with revisions and preview. |
| 5 | **CMS — Navigation** | Nested, drag-and-drop header/footer/mobile menus pointing at any internal entity or external URL. |
| 6 | **CMS — Media Library** | Central asset store with folders, EXIF stripping, responsive conversions, alt-text enforcement and inode-aware storage. |
| 7 | **CMS — SEO** | Per-entity meta, canonical URLs, Open Graph/Twitter cards, JSON-LD, sitemaps, robots, and a 404→redirect workflow. |
| 8 | **Divisions & Focus Areas** | The foundation's four divisions as first-class entities that scope and colour everything else. |
| 9 | **Projects** | Programme work with updates, milestones, galleries, documents, locations, partners and beneficiary counts. |
| 10 | **Causes & Campaigns** | Time-bound fundraising appeals with a goal, live progress, updates and their own donation flow. |
| 11 | **Impact** | Measurable outcome metrics with values over time, plus a public impact dashboard and beneficiary stories. |
| 12 | **Beneficiaries & Consent** | Restricted-access beneficiary records with photo/story consent tracking, especially for children. |
| 13 | **Donations** | One-off giving in GHS with designation, fee-cover, tribute gifts, anonymity, and integer-pesewa accounting. |
| 14 | **Recurring Giving** | Paystack plans and subscriptions with charge history and full donor self-service cancellation. |
| 15 | **Offline Donations** | Manual recording of cash, cheque, bank-transfer and in-kind gifts so the ledger is complete. |
| 16 | **Receipts & Statements** | Numbered PDF donation receipts and annual consolidated giving statements. |
| 17 | **Payments (Paystack)** | The single `PaystackService`, one transaction table, one signature-verified idempotent webhook handler for all payables. |
| 18 | **Reconciliation** | Daily comparison of our ledger against Paystack settlements, with pending-transaction sweeps and discrepancy alerts. |
| 19 | **Refunds** | Controlled, audited refunds for donations and orders via the Paystack refund API. |
| 20 | **Donors (CRM-lite)** | Unified donor profiles: giving history, lifetime value, segments, tags, duplicate merge and communication preferences. |
| 21 | **Peer-to-Peer Fundraising** | Supporter-created fundraising pages that route donations to a cause *(optional — pending §12c)*. |
| 22 | **Sponsorship** | Recurring child/student sponsorship with beneficiary updates delivered to the sponsor. |
| 23 | **Shop — Catalogue** | Products, variants, options, categories, images, reviews and stock levels. |
| 24 | **Shop — Cart & Checkout** | Guest and account checkout with server-side pricing, coupons and stock holds. |
| 25 | **Shop — Orders & Fulfilment** | Order lifecycle, status history, invoices, digital download tokens and customer notifications. |
| 26 | **Shop — Shipping** | Ghana region/district shipping zones and rates, pickup, and digital delivery. |
| 27 | **Volunteers** | Opportunities, applications with safeguarding checks, approvals, assignments and logged hours. |
| 28 | **Events** | Events with registration and optional free or paid ticketing. |
| 29 | **Blog & News** | Posts, categories, tags and moderated comments for storytelling and SEO. |
| 30 | **Newsletter** | Double opt-in subscribers, segmented campaigns, throttled sending and one-click unsubscribe. |
| 31 | **Contact & Enquiries** | Department-routed enquiry inbox with auto-reply, assignment, SLA and a separate safeguarding channel. |
| 32 | **Prayer Requests** | Private or shareable prayer requests feeding Every Soul Missions. |
| 33 | **Email Communications** | CMS-editable templates, queued sending, delivery logging and bounce/complaint handling. |
| 34 | **SMS Communications** | Ghanaian-provider SMS with a registered sender ID, GSM-7 segment/cost estimation and delivery logs. |
| 35 | **Notifications** | Unified in-app/email/SMS dispatch honouring per-channel donor preferences. |
| 36 | **Search & Filtering** | Faceted filtering across projects, causes, products, events and posts *(site-wide search post-launch)*. |
| 37 | **Analytics & Reporting** | Privacy-respecting traffic stats plus donation, campaign, shop and volunteer reports with CSV/PDF export. |
| 38 | **Security & Compliance** | OWASP hardening, rate limiting, 2FA, audit logging, consent records and Act 843 data-subject rights. |
| 39 | **Backups & Recovery** | Scheduled off-server backups with a documented and **actually tested** restore procedure. |
| 40 | **System Health & Ops** | Queue/scheduler monitors, failed-job management, webhook health, error reporting and uptime checks. |
| 41 | **Performance** | Caching, image pipeline, asset budgets and shared-hosting-safe optimisation for low-bandwidth Ghana. |
| 42 | **Localisation Scaffolding** | Locale-ready strings and content so Twi/Ga/Ewe can be added later without a rewrite. |

---

# 10. Risk register

Scoring: **L** = likelihood, **I** = impact, each Low / Med / High.

## 10.1 Shared-hosting risks

| # | Risk | L | I | Mitigation |
|---|---|---|---|---|
| SH-1 | ~~PHP 8.3+ not offered → Laravel 13 cannot run~~ | — | — | ✅ **CLOSED.** MultiPHP Manager confirms **PHP 8.3 (`ea-php83`) is the system version** and 8.4 is installed. Residual action: pin the version explicitly instead of "Inherited", so an InMotion default change cannot move production PHP under us (§4.4.2). |
| SH-2 | **Required PHP extensions missing** (`intl`, `exif`, `zip`, `gd`/`imagick`, `sodium`) — and no PHP Selector to enable them | Med | **High** | `php -m` in Terminal now; support ticket for anything missing; degrade gracefully (GD instead of Imagick, a polyfill instead of `intl`) |
| SH-3 | **No SSH / Composer on the server** | Med | High | The chosen strategy (§11) builds `vendor/` and `public/build/` **on the CI runner**, so the server never needs Composer or Node |
| SH-4 | **Per-minute cron blocked** (InMotion sometimes enforces ≥5 or ≥15 min on shared plans) | Med | High | If blocked: run `schedule:run` at the allowed interval and set all scheduled tasks to that granularity; size the queue worker's `--max-time` to the interval; anything needing sub-minute latency is dispatched after-response instead of queued |
| SH-5 | **CloudLinux LVE entry-process / CPU limits kill the queue worker** | ~~High~~ **Low** | Med | **Downgraded.** Confirmed limits are generous: 100% CPU, **80 entry processes**, **200 processes**, **2 GB PMEM** (§4.0). Still: `--stop-when-empty --max-time=55 --tries=3 --memory=96`; never more than one worker; a `flock` guard so overlapping cron runs cannot stack; review Resource Usage monthly |
| SH-6 | **Inode limit exhausted by the media library** (typically 200k–500k on shared plans; Media Library writes ~5–8 files per upload with conversions) | **High** | High | Cap conversion variants at 3; one responsive `srcset` set, not per-breakpoint files; scheduled purge of orphaned media; monitor inode count; **plan the move to object storage (Cloudflare R2 / Backblaze B2) now, not after we hit the wall** |
| SH-7 | **No Redis** → cache/session/queue all on the database or filesystem | Certain | Med | `database` driver for the queue, `file`/`database` for cache with tagged fragment caching; keep `cache` and `sessions` indexed and pruned on a schedule; **OPcache on** via MultiPHP INI Editor |
| SH-8 | **ModSecurity blocks the Paystack webhook POST** (JSON bodies with signature headers trip generic OWASP CRS rules) | **High** | **High** | Test the webhook against the live domain in Phase 8; if blocked, take the specific rule ID from the cPanel ModSecurity log and ask InMotion to whitelist it **for that URI only**; keep the endpoint path unguessable; never disable ModSecurity globally |
| SH-9 | **`.env` or `storage/` exposed to the web** | Low | **Critical** | App root lives **outside** `public_html`; `public_html` contains only `public/`; belt-and-braces `.htaccess` deny rules; an HTTP smoke test asserts `/.env`, `/storage/logs/laravel.log`, `/vendor/` and `/.git/` all return 403/404 |
| SH-10 | **`storage:link` symlink not permitted, or broken by deploy** | Med | Med | Re-create the symlink in the post-deploy step; fall back to a controller-served media route if symlinks are blocked |
| SH-11 | **Deployment leaves the site half-updated** (rsync mid-flight) | Med | High | Atomic release directories + a `current` symlink flip; `php artisan down --render` during migration only |
| SH-12 | **Migration fails on the live donation database** | Low | **Critical** | Always `migrate --force`, **never** `migrate:fresh`; a pre-deploy DB dump; every migration reversible; every migration run against a staging clone of production data first |
| SH-13 | **Disk quota exhausted** by logs + backups + media | ~~Med~~ **Low** | Med | **Downgraded.** 2.17 GB used of **200 GB**, and DB storage is effectively unlimited (§4.0). Still: daily log rotation; backups written **off-server** (Google Drive / B2 / Dropbox via `spatie/laravel-backup`) keeping only one local copy — note cPanel Backup Usage is capped at 10 GB, which is the real ceiling here, not disk |
| SH-14 | **Shared IP blacklisted** by another tenant's spam | Med | Med | Send transactional mail through an authenticated provider on our own reputation, not the shared IP (§10.3) |
| SH-15 | **No staging environment** → changes tested in production | Med | High | Staging subdomain, own DB, own `.env`, Paystack **test** keys, `X-Robots-Tag: noindex` + Directory Privacy password |
| SH-16 | **Filament admin is heavy** for shared-hosting CPU | Med | Med | Production asset build, `filament:optimize`, `config:cache`, `route:cache`, `view:cache`, `icons:cache`; no unbounded dashboard widgets |
| SH-17 | **PostgreSQL is visible in cPanel and gets used by mistake** | Low | Med | Documented: **MySQL/MariaDB only**; `.env.example` pins `DB_CONNECTION=mysql` |
| SH-18 | **The foundation shares a cPanel account with an unrelated business** (`prestigerocktravels.com` is the primary domain; ours is an addon) | Certain | **High** | §4.4.1. Shared fate on suspension, quota, IP reputation and credentials. Build and stage here; **move to a dedicated cPanel account in the foundation's name before taking real money.** Until then: never touch `public_html`, and treat the account password as jointly held. |
| SH-19 | **PHP version silently changes** because our domain is set to "Inherited" rather than pinned | Med | **High** | Pin `ea-php83` explicitly on `greaterhopefoundations.com` in MultiPHP Manager (§4.4.2). A deploy smoke test asserts the running PHP version. |
| SH-20 | **Wrong domain selected in MultiPHP Manager** takes a live site to PHP 5.6 | Low | **Critical** | The dropdown defaults to `PHP 5.6 (ea-php56)`. Always tick the domain checkbox first, verify the selection count, then Apply. Change PHP only on staging first. |

## 10.2 Payment risks

| # | Risk | L | I | Mitigation |
|---|---|---|---|---|
| PAY-1 | **Float rounding corrupts amounts** (`0.1 + 0.2`) | Med | **Critical** | Integer pesewas everywhere: DB `unsigned BIGINT`, a `Money` value object/cast, no float arithmetic anywhere in the money path; Pest tests asserting round-trips at boundary values |
| PAY-2 | **Trusting the browser redirect as proof of payment** | Med | **Critical** | Payment truth comes **only** from the signature-verified webhook. The callback route resolves a *display* state, never a financial one. Enforced by a test that hits the callback and asserts the donation is still `pending`. |
| PAY-3 | **Webhook signature not verified, or verified against the parsed body instead of the raw body** | Med | **Critical** | Read `$request->getContent()` **before** any middleware mutates it; `hash_hmac('sha512', $raw, $secret)`; `hash_equals()`; the route is excluded from CSRF but from nothing else; a test posts a tampered payload and asserts rejection |
| PAY-4 | **Replayed webhook double-counts a donation** | Med | **High** | Unique index on the Paystack event id; the processing job is idempotent and no-ops on a seen event; a test fires the same event 5× and asserts one donation and one receipt |
| PAY-5 | **Amount/currency mismatch between what we expected and what was paid** | Low | **Critical** | Re-verify `amount` **and** `currency === 'GHS'` from the webhook against the stored expectation; on mismatch → `needs_review` + Finance alert, **never** auto-complete |
| PAY-6 | **`currency` omitted → Paystack charges in NGN** | Med | **High** | `currency: 'GHS'` is set inside `PaystackService`, not by callers; a test asserts every outbound initialise payload contains it |
| PAY-7 | **Webhook arrives before the local transaction row is committed** (race) | Med | High | Create and commit the transaction row **before** redirecting to Paystack; the job retries with backoff if the reference is not yet found |
| PAY-8 | **Mobile Money charge stays pending for minutes**, the user thinks it failed and pays twice | **High** | High | The MoMo-specific waiting UI with a long timeout and clear instructions (§8.3); duplicate detection on (email/phone + amount + designation) within 10 minutes, with a "you may have already given" interstitial |
| PAY-9 | **Live secret key committed to Git** | Med | **Critical** | Secrets only in the server `.env`; `.env` git-ignored; `.env.example` placeholders only; a pre-commit secret scan and a CI secret-scanning step; **rotate immediately if ever exposed** |
| PAY-10 | **Test keys left in production** (or live keys in staging) | Med | High | An `APP_ENV`-aware startup assertion: production refuses to boot with an `sk_test_` key, staging refuses `sk_live_`; a visible environment badge in the admin bar |
| PAY-11 | **Recurring subscription cannot be cancelled by the donor** → chargebacks and complaints | Med | High | One-click cancel in `/account/recurring` using the stored subscription code + email token; a cancellation link in every recurring receipt |
| PAY-12 | **Ledger drifts from Paystack settlements** | Med | High | A daily reconciliation job comparing our completed donations/orders against Paystack transactions and settlements; a discrepancy report to Finance; a `payouts` table recording settlements received |
| PAY-13 | **Paystack API is down or slow at the moment of giving** | Low | Med | Timeouts + retry with backoff; a queued fallback; a plain "please try again, or use bank transfer / MoMo directly" message with the offline giving details |
| PAY-14 | **Card data touched or logged** → PCI scope explodes | Low | **Critical** | Hosted Paystack checkout only; never proxy card fields; scrub `card`, `cvv`, `pin`, `authorization` from logs and exception reports; store only the Paystack `authorization_code` + `last4` + brand |
| PAY-15 | **Refunds issued outside the app** → the ledger diverges | Med | Med | All refunds through the admin refund action; the reconciliation job flags any Paystack refund with no local counterpart |
| PAY-16 | **Fee-cover maths is wrong** (Paystack's Ghana fee is a percentage with a cap, and differs by channel) | Med | Med | The fee model is configurable in settings per channel with a cap; unit-tested against Paystack's published Ghana rates; the exact covered amount is shown to the donor before they pay |
| PAY-17 | **Donation with no valid designation** | Med | Med | A seeded, undeletable **General Fund** cause is the fallback; `designation_id` is never nullable |
| PAY-18 | **Webhook endpoint DoS'd or scraped** | Low | Med | Rate limit by IP, verify the signature before any DB write beyond the raw event, respond `200` fast, process off-request |

## 10.3 Deliverability risks (email & SMS)

| # | Risk | L | I | Mitigation |
|---|---|---|---|---|
| DEL-1 | **Receipts land in spam** — the donor thinks the gift failed | **High** | **High** | **SPF + DKIM via cPanel → Email Deliverability**, plus a **DMARC** record added by hand in Zone Editor (`p=none` → monitor → `p=quarantine` → `p=reject`); an aligned `From:` domain; a real reply-to mailbox |
| DEL-2 | **cPanel/MailChannels hourly send limits** exceeded by a newsletter blast | **High** | Med | Transactional mail (receipts, orders — never delayable) on a dedicated provider; bulk newsletters chunked and throttled through the cron queue with a per-hour cap; **never** send a campaign in one burst |
| DEL-3 | **Shared-IP reputation damage** from another tenant | Med | High | Authenticated sending via Resend/Postmark/Brevo/Mailgun rather than the shared cPanel IP (decision in §12c) |
| DEL-4 | **No bounce/complaint handling** → the list rots and reputation collapses | **High** | High | Provider webhooks → mark hard bounces and complaints, suppress automatically, never re-send; a suppression list respected by every send |
| DEL-5 | **Single opt-in newsletter** → spam traps | Med | High | Double opt-in, honeypot, consent records, 7-day/30-day pending purge (§8.6) |
| DEL-6 | **SMS sender ID not registered in Ghana** → messages silently dropped by MTN/Telecel/AT | **High** | **High** | **Register the alphanumeric sender ID with the provider before launch** (Arkesel/Hubtel/mNotify typically need 1–5 working days plus a business registration document). Until approved, fall back to the provider's shared sender ID. **This has a lead time — start it in Phase 2, not Phase 10.** |
| DEL-7 | **SMS cost overruns** — a message silently splits into 3+ segments | Med | Med | GSM-7 vs UCS-2 detection and a live segment counter in the SMS template editor; strip emoji and smart quotes from templates; a per-campaign cost estimate and a monthly spend cap with an alert |
| DEL-8 | **SMS provider balance hits zero** → receipts stop | Med | High | A scheduled balance check with a low-balance alert to Finance; failed sends queued and retried, never dropped |
| DEL-9 | **Ghanaian phone numbers stored inconsistently** (`024…`, `+23324…`, `23324…`) | **High** | Med | Normalise to E.164 `+233…` on write with a dedicated cast; validate against Ghanaian network prefixes; keep the raw input alongside for support |
| DEL-10 | **Emails sent to donors who never consented** | Med | High (legal) | Transactional and marketing separated at the code level; marketing requires a confirmed consent record; transactional receipts always send (legitimate interest) but carry no marketing content |
| DEL-11 | **Emails unreadable** on low-end Android mail clients / in dark mode | Med | Med | Table-based responsive templates, inline CSS, a plain-text alternative, no web fonts, dark-mode-safe colours, tested in Gmail Android, Outlook and Apple Mail |
| DEL-12 | **Queue not running → no email or SMS goes out at all, silently** | Med | **High** | A queue heartbeat: a scheduled job writes a timestamp; a health check alerts if the queue has been idle while jobs are pending; failed-job count surfaced on the admin dashboard |

## 10.4 Content, legal & operational risks

| # | Risk | L | I | Mitigation |
|---|---|---|---|---|
| OPS-1 | **Beneficiary photos published without consent** — especially children | Med | **Critical** | A `consents` record (type, scope, expiry, guardian signature) is **required** before any beneficiary image or story can be published; the CMS blocks publishing without one; consent is revocable and revocation unpublishes |
| OPS-2 | **GPS coordinates in uploaded photos** reveal a child's home or school | **High** | **High** | EXIF/GPS stripped on **every** upload, unconditionally, at the point of ingestion |
| OPS-3 | **Act 843 non-compliance** (no consent log, no data-subject rights, no retention policy) | Med | High | Consent records storing the exact text shown; `/account/privacy` export + deletion request; a documented retention schedule; **registration with Ghana's Data Protection Commission as a data controller** |
| OPS-4 | **No annual report or financials** on a site asking for money | High | Med | The transparency page is built now and populated as documents arrive; "reports coming for our first financial year" is honest and acceptable for a foundation registered in 2026 |
| OPS-5 | **Admin account compromise** → donor PII and payment settings exposed | Med | **Critical** | 2FA mandatory on all admin roles; strong password policy; session timeout; login-history alerts on new device/IP; rate-limited login; `/admin` path optionally restricted |
| OPS-6 | **Backups exist but restore has never been tested** | **High** | **Critical** | A quarterly restore drill into the staging environment, recorded in a restore-test log. A backup that has not been restored is not a backup. |
| OPS-7 | **Content edits break the layout** and there is no way back | Med | Med | Page revisions with restore; block-level validation; preview before publish |
| OPS-8 | **The site is slow on a 3G Android phone in Ghana** → donors leave | **High** | **High** | A performance budget enforced in CI (LCP < 2.5 s on simulated 3G, JS < 100 KB gzipped, hero < 60 KB); AVIF/WebP; no third-party embeds above the fold; self-hosted fonts; a Lighthouse CI gate |
| OPS-9 | **Domain, hosting or Paystack account is in the wrong entity's name** | **High** | High | Now concrete, not hypothetical: the hosting account's primary domain is a **for-profit travel business** (§4.4.1). **Confirm before launch** that `greaterhopefoundations.com`, the hosting account, and the Paystack merchant account are all held by the foundation — not by an individual and not by a sister company — with recovery access held by more than one trustee |
| OPS-11 | **Donor personal data sits on infrastructure controlled by an unrelated commercial entity** | **High** | High | An Act 843 and institutional-donor concern as much as a technical one. Either move to a dedicated account (§4.4.1) or document the arrangement, the data controller, and the access list in the privacy policy and the data-processing record |
| OPS-10 | **Single point of failure — one person holds every credential** | **High** | High | A documented credential inventory in a shared password manager, with at least two trustees holding recovery access |

---

# 11. Environment plan & deployment decision

## 11.1 The decision

> ## ✅ CONFIRMED
> **We deploy with GitHub Actions building on the runner and shipping to cPanel over SSH + rsync (port 2222), into atomic release directories.**
>
> SSH Access is enabled on the account with key management, so the fallback is no longer needed. cPanel Git Version Control with `.cpanel.yml` remains documented below for completeness, but we are not using it.

## 11.2 Why this, and not the alternatives

| Option | Verdict | Reasoning |
|---|---|---|
| **A. GitHub Actions → SSH/rsync** | ✅ **CHOSEN & CONFIRMED VIABLE** | The runner does `composer install --no-dev --optimize-autoloader` and `npm ci && npm run build`, then ships the finished tree. **The server never needs Composer, Node, or npm** — which neutralises risk SH-3 and the largest unknown in §4.3. Push to `main` → tested, built, deployed, migrated, cached, in one automated pass. The atomic symlink flip means no half-updated site. Rollback is one symlink change. Secrets live in GitHub Actions secrets, never in the repo. Full deploy logs and history. |
| **B. cPanel Git Version Control + `.cpanel.yml`** | **No longer needed** | It is present in your cPanel and it does work. But GitHub cannot push *to* it, so deployment becomes "SSH in and `git pull`" or a polling cron — which is not a pipeline. `.cpanel.yml` tasks run in a restricted environment where `composer install` is unreliable or absent, which would force us to **commit `vendor/` and `public/build/` into the repository** — 15k+ files, inode pressure, noisy diffs, and a security-update workflow that depends on a human remembering. Documented and ready, but plan B. |
| **C. FTP/SFTP deploy action** | Rejected | Slow, non-atomic, no reliable delete-sync, breaks on a partial transfer. |
| **D. Manual File Manager / zip upload** | Rejected | Not reproducible, not auditable, guaranteed to drift. |
| **E. Deployer / Envoyer** | Rejected | Envoyer targets VPS-style hosts; Deployer needs server-side PHP + Composer. Option A gives the same atomic-release benefit without either. |

~~Both A and B need SSH.~~ ✅ **SSH is enabled on this account with key-based authentication, so strategy A proceeds. No fallback required.**

## 11.3 Server layout

Paths are now concrete: home is **`/home/presti98`**.

**One important correction from v1.0:** `greaterhopefoundations.com` is an **addon domain**, so `public_html` belongs to `prestigerocktravels.com` — **we must not touch it.** Our document root is the addon domain's own directory, `{{ADDON_DOCROOT}}`, which on cPanel 134 is one of:

- `/home/presti98/greaterhopefoundations.com` *(newer cPanel "Domains" default)*, or
- `/home/presti98/public_html/greaterhopefoundations.com` *(classic addon-domain default)*

Confirm with `ls -la /home/presti98` before Phase 2 (§4.3). The layout is identical either way — only that one path differs.

```
/home/presti98/
├── public_html/                       ← prestigerocktravels.com — DO NOT TOUCH
│
├── {{ADDON_DOCROOT}}/                 ← greaterhopefoundations.com document root
│        replaced by a symlink to  /home/presti98/scghf/current/public
│        (fallback if symlinking the docroot is refused: a thin index.php
│         + .htaccess pointing at ../scghf/current)
│
├── scghf/                             ← application code, OUTSIDE any web root
│   ├── current  ─────────────────────→ symlink to releases/<git-sha>
│   ├── releases/
│   │   ├── 2026-09-01_a1b2c3d/        (keep the last 5, prune the rest)
│   │   └── …
│   ├── shared/
│   │   ├── .env                       (chmod 600 — never in Git, never inside a release dir)
│   │   ├── storage/                   (app/, framework/, logs/ — symlinked into each release)
│   │   └── queue.lock                 (flock guard for the cron queue worker)
│   └── backups/                       (transient; the real copies go off-server)
│
└── scghf-staging/                     ← staging.greaterhopefoundations.com
        (identical structure, own DB `presti98_scghf_stage`, own .env, Paystack test keys)
```

Each release gets `shared/.env` and `shared/storage` symlinked in, so uploads, logs and secrets survive every deploy.

**Database naming** (cPanel prefixes everything with the account username):

| | Production | Staging |
|---|---|---|
| Database | `presti98_scghf_prod` | `presti98_scghf_stage` |
| User | `presti98_scghf` | `presti98_scghfstg` |
| Host | `localhost` | `localhost` |

*Two databases are in use out of unlimited, so there is no constraint here.*

## 11.4 The pipeline

**Branches:** `main` → production · `develop` → staging · `feature/*` → PR into `develop`.

**On push to `develop` or `main`:**

1. Checkout · PHP 8.3 with the required extensions · Node 20
2. `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`
3. `npm ci && npm run build`
4. **Quality gates — these block the deploy:** `pint --test` · `phpstan` · `pest --coverage` · a `.env`/secret scan · a Lighthouse budget check on a preview build
5. Build the release tarball (excluding `.git`, `node_modules`, `tests`, `.env`)
6. `rsync -az --delete` over SSH into `releases/<sha>` (key from GitHub secrets, `known_hosts` pinned)
7. Over SSH: link `shared/.env` and `shared/storage` · `php artisan migrate --force` · `storage:link` · `config:cache route:cache view:cache event:cache` · `filament:optimize` · `icons:cache`
8. **Flip the `current` symlink** — this is the atomic moment
9. `php artisan queue:restart` · `php artisan up` · prune old releases
10. Post-deploy smoke test: `/` returns 200 · `/health` returns 200 · `/.env` returns 403/404
11. ⚠️ Any failure after step 6 → the symlink is not flipped, so the live site is untouched. Rollback = point `current` back one release and re-cache.

### ⚠️ Port 2222 — the detail that breaks CI pipelines

InMotion shared hosting runs SSH on **port 2222**, not 22. Every tool needs telling separately, and each one uses different syntax. Getting one of these wrong produces a confusing "connection refused" partway through a deploy:

| Tool | Correct form |
|---|---|
| `ssh` | `ssh -p 2222 presti98@{{SSH_HOST}}` |
| `rsync` | `rsync -az --delete -e "ssh -p 2222" ./release/ presti98@{{SSH_HOST}}:/home/presti98/scghf/releases/<sha>/` |
| `ssh-keyscan` (known_hosts pinning) | `ssh-keyscan -p 2222 {{SSH_HOST}} >> ~/.ssh/known_hosts` |
| `scp` | `scp -P 2222` *(capital P — different from ssh's lowercase)* |
| `~/.ssh/config` | `Host scghf` / `HostName {{SSH_HOST}}` / `User presti98` / `Port 2222` / `IdentityFile ~/.ssh/scghf_deploy` |

We will put the port in a `SSH_PORT` GitHub secret rather than hardcoding it, so a future host move is a settings change.

### The deploy keypair

**Generate a dedicated deploy key — do not reuse a personal key.** Run this on your own machine, not on the server:

```bash
ssh-keygen -t ed25519 -C "github-actions-deploy-scghf" -f ~/.ssh/scghf_deploy -N ""
```

Then:

1. **Public key** (`~/.ssh/scghf_deploy.pub`) → cPanel → SSH Access → Manage SSH Keys → **Import Key**.
2. ⚠️ **Then click "Manage" next to the imported key and set it to "Authorize."** An imported-but-unauthorised key silently fails to authenticate — this is the single most common cPanel SSH mistake.
3. **Private key** (`~/.ssh/scghf_deploy`) → GitHub repo → Settings → Secrets and variables → Actions → `SSH_PRIVATE_KEY`. **The private key never leaves your machine except into that secret, and never enters the repository.**
4. Test before wiring up CI: `ssh -i ~/.ssh/scghf_deploy -p 2222 presti98@{{SSH_HOST}}` should give you a shell.

**GitHub Actions secrets to create in Phase 2:**

| Secret | Value |
|---|---|
| `SSH_HOST` | `23.235.219.254` (or the server hostname) |
| `SSH_PORT` | `2222` |
| `SSH_USER` | `presti98` |
| `SSH_PRIVATE_KEY` | Contents of `~/.ssh/scghf_deploy` |
| `SSH_KNOWN_HOSTS` | Output of `ssh-keyscan -p 2222 {{SSH_HOST}}` |
| `DEPLOY_PATH` | `/home/presti98/scghf` |
| `PROD_APP_URL` | `https://greaterhopefoundations.com` |

*Paystack keys, DB credentials and mail credentials are **not** GitHub secrets — they live in `/home/presti98/scghf/shared/.env` on the server and are never transmitted through CI.*

**PHP binary in cron and CLI** — never bare `php`, always the versioned EasyApache binary. PHP 8.3 is confirmed as the system version, so:

```
/opt/cpanel/ea-php83/root/usr/bin/php
```

(confirm with `ls /opt/cpanel/ | grep ea-php` in Terminal; `ea-php84` also exists on this server if we move to 8.4 later.)

**Cron entries — real paths, ready to paste:**

```cron
# Laravel scheduler
* * * * * /opt/cpanel/ea-php83/root/usr/bin/php /home/presti98/scghf/current/artisan schedule:run >> /dev/null 2>&1
```

```cron
# Queue worker — bounded so CloudLinux LVE limits are never breached
* * * * * /usr/bin/flock -n /home/presti98/scghf/shared/queue.lock /opt/cpanel/ea-php83/root/usr/bin/php /home/presti98/scghf/current/artisan queue:work --stop-when-empty --max-time=55 --tries=3 --memory=96 >> /dev/null 2>&1
```

`flock` prevents overlapping workers stacking into an entry-process breach. With **80 entry processes and 200 processes available** (§4.0), a single bounded worker sits far inside the ceiling — this is now a comfortable design rather than a tight one. If per-minute cron turns out not to be permitted (risk SH-4), both drop to the allowed interval and the scheduler's task granularity adjusts to match.

## 11.5 ✅ Enable or request from InMotion **before Phase 2**

*Items struck through were open in v1.0 and are now resolved by your two screenshots.*

**You do (in cPanel) — roughly 30–45 minutes:**

| # | Action | Where |
|---|---|---|
| 1 | **Run the diagnostic command in §4.3** and send me the output | Advanced → Terminal |
| 2 | ~~Enable SSH access~~ ✅ **done** — now **generate the deploy keypair, import the public key, and click Authorize** (§11.4) | Security → SSH Access → Manage SSH Keys |
| 3 | **Pin the PHP version** for `greaterhopefoundations.com` — select the domain, apply **PHP 8.3 (ea-php83)** explicitly so it stops saying "Inherited" (§4.4.2) | Software → MultiPHP Manager |
| 4 | **Enable PHP-FPM** for `greaterhopefoundations.com` (§4.4.3) | Software → MultiPHP Manager |
| 5 | ~~Screenshot the statistics sidebar~~ ✅ **done** | — |
| 6 | **Screenshot the Cron Jobs page**, including any note about minimum interval | Advanced → Cron Jobs |
| 7 | **Create the production database + user** `presti98_scghf_prod` / `presti98_scghf`, grant ALL PRIVILEGES | Databases → Database Wizard |
| 8 | **Create `staging.greaterhopefoundations.com`** with its own document root, and a second DB `presti98_scghf_stage` | Domains → Domains; Database Wizard |
| 9 | **Confirm AutoSSL covers `greaterhopefoundations.com`, `www.`, and the staging subdomain** — the panel currently shows SSL active for the *primary* domain only | Security → SSL/TLS Status |
| 10 | **Enable SPF and DKIM for `greaterhopefoundations.com`** (not just the primary domain) | Email → Email Deliverability |
| 11 | **Add a DMARC TXT record**: `_dmarc.greaterhopefoundations.com` → `v=DMARC1; p=none; rua=mailto:{{EMAIL_DMARC}}` | Domains → Zone Editor |
| 12 | **Turn on Two-Factor Authentication** for the cPanel account | Security → Two-Factor Authentication |
| 13 | **Create the mailboxes** on `greaterhopefoundations.com`: `info@`, `donations@`, `volunteer@`, `shop@`, `media@`, `safeguarding@`, `noreply@`, `dmarc@` *(5 accounts exist; the limit is unlimited)* | Email → Email Accounts |
| 14 | **Password-protect the staging subdomain's directory** | Files → Directory Privacy |
| 15 | Set the **PHP INI values** for our domain: `memory_limit ≥ 256M`, `upload_max_filesize ≥ 32M`, `post_max_size ≥ 32M`, `max_execution_time ≥ 120`, OPcache enabled *(2 GB PMEM gives plenty of room)* | Software → MultiPHP INI Editor |
| 16 | **Confirm the addon domain's document root** — note the exact path for `greaterhopefoundations.com` | Domains → Domains |

**Ask InMotion support (open the ticket now — some of these have a turnaround):**

| # | Request | Why |
|---|---|---|
| 1 | ~~Confirm PHP 8.3 availability~~ ✅ **Resolved — PHP 8.3 is the system version, 8.4 is also installed** | Risk SH-1 closed |
| 2 | "Please confirm these PHP extensions are enabled for `ea-php83`: `bcmath, ctype, curl, dom, exif, fileinfo, gd, iconv, intl, mbstring, openssl, pdo_mysql, sodium, tokenizer, xml, zip`. Please enable any that are missing." | No PHP Selector on this account, so we cannot self-serve |
| 3 | ~~Confirm SSH is enabled~~ ✅ **Resolved — enabled, port 2222.** Residual: "What **hostname** should I use for SSH — the shared IP `23.235.219.254`, or a server hostname?" | Strategy A confirmed; just need the host string for CI |
| 4 | "Is **Composer** installed and on the shell PATH? Which version?" | Determines whether plan B is viable |
| 5 | "What is the **inode limit and current inode usage** for `presti98`? It is not shown in my statistics panel." | Sizes the media strategy (risk SH-6) — the last hidden quota |
| 6 | "Is there a **minimum cron interval** on this plan, and are per-minute crons permitted?" | Risk SH-4 — reshapes the queue design |
| 7 | "What are the **outbound email limits** per hour/day, and does MailChannels apply to mail sent via SMTP/PHP from my application?" | Risk DEL-2 — sizes the newsletter throttle |
| 8 | ~~Confirm CloudLinux LVE limits~~ ✅ **Resolved — 100% CPU, 80 entry processes, 2 GB PMEM, 200 processes** | Risk SH-5 downgraded |
| 9 | "Please confirm **`symlink()` is permitted** and not in `disable_functions`, and that I may replace an **addon domain's** document root with a symlink." | The whole release strategy depends on it |
| 10 | "Are `proc_open`, `exec` and `shell_exec` disabled?" | A common restriction that breaks Composer and some queue features |
| 11 | "If **ModSecurity** blocks a webhook POST to a specific URI, what is the process to whitelist that one rule for that one path?" | Risk SH-8 — know the process before it bites during a live payment |
| 12 | "Please confirm my account's **backup policy and retention**, and how I request a restore." | Risk OPS-6 |
| 13 | "What would it cost to move `greaterhopefoundations.com` onto **its own cPanel account**, and can you migrate it for me?" | §4.4.1 — governance and shared-fate risk |

**Not from InMotion — you'll need these too:**

| # | Action | Lead time |
|---|---|---|
| 1 | **Register the domain** (or confirm ownership/registrar/DNS host) — in the **foundation's** name | 1 day |
| 2 | **Create the Paystack merchant account** in the foundation's name with its registration documents; complete KYC; obtain **test and live** keys | **3–10 working days — start now** |
| 3 | **Open the SMS provider account** (Arkesel / Hubtel / mNotify) and **submit the alphanumeric sender ID for registration** — e.g. `GreaterHOPE` (11 chars max) | **1–5 working days — start now** (risk DEL-6) |
| 4 | Create the **private GitHub repository** and add me as a collaborator | 10 min |
| 5 | Open the **transactional email provider** account and verify the sending domain | 1 day |
| 6 | Set up a **shared password manager** for the credential inventory | 30 min |

---

# 12. Phase close-out

## (a) What you missed, forgot, or under-specified

1. **No contact details anywhere.** No address, phone, email, or region. The site cannot have a footer, a contact page, a receipt, or `Organization` structured data without them. *(Blocker.)*
2. **No registration number or tax status.** Required in the footer of a fundraising site, on every receipt, and by Paystack's merchant KYC. *(Blocker.)*
3. **No bank or Paystack account details.** No payouts, no bank-transfer giving option, no reconciliation. *(Blocker.)*
4. ~~**No domain name.**~~ ✅ **Resolved by the screenshots — `greaterhopefoundations.com` already exists.** But you did not mention that it is an **addon domain on a travel company's hosting account**, which is a material fact I had to discover rather than be told (§4.4.1). *(Now a decision, §12c #29.)*
5. **No leadership or board information.** A registered foundation asking the public for money with no named humans behind it will not convert. *(High.)*
6. **No social media handles.** *(High.)*
7. **No photography.** Not one image of the foundation, its work, its people, or Mrs Cecilia Anyatuik Adam. This is the biggest single gap for a site whose entire proposition is emotional and legacy-driven. *(High.)*
8. **No shop content at all** — no products, no prices, no photos. Phase 9 cannot start. And you have not said *what* the shop sells (branded merchandise? crafts made by beneficiaries? books? symbolic gift cards?). That choice changes the whole shop module. *(High.)*
9. **The logo pack is raster-only and internally inconsistent.** No SVG, no mono version, no dark-background version, and two different colourways across the files (§2.2). *(High — blocks Phase 6.)*
10. **The nine "Decisions to confirm before Phase 1"** in `FOUNDATION-WEBAPP-MASTER-PROMPT.md` (languages, donor accounts, shop fulfilment, P2P, volunteering/ticketing, SMS provider, email provider, SSH/Composer/Node/cron) have not been answered. They are restated in (c).
11. ~~The cPanel screenshot is cropped~~ ✅ **Resolved.** The full panel and MultiPHP Manager closed almost every environment unknown. **Six remain** (§4.3), of which **"is SSH enabled?"** is the only one that can still change the deployment strategy. Inode limit is the one quota cPanel does not display anywhere — it has to come from support.
12. **No geography.** "Ghana" is the only location given. Which regions, which districts, where is the office? Needed for project locations, shipping zones, and local SEO.
13. **No governance or safeguarding policy.** You work with orphans and children. A child-protection policy is not optional — it is a condition of credibility with institutional donors and, increasingly, of registration.
14. **No brand typography specified.** The wordmark's typeface is unidentified.
15. **Legal name ambiguity:** "Foundation**s**" (plural) in the profile vs "Greater**HOPE**" in the wordmark. It must match the registration certificate exactly.
16. **No budget or timeline stated** for the build, and no named client-side approver for sign-offs.

## (b) What I recommend adding to make this production-ready

**Fundraising & trust**

1. **Mobile Money as the primary payment method**, not an afterthought — it is how most Ghanaians pay. Listed first, with a purpose-built waiting experience (§8.3).
2. **A seeded, undeletable "General Fund" cause** so no donation can ever be undesignated.
3. **A "cover the transaction fee" toggle** — typically lifts net receipts 3–5% at zero cost.
4. **Tribute giving ("in memory of / in honour of")** with an optional notification card. Given that this foundation *is* a memorial, this is not a nice-to-have — it is the emotional centre of the product.
5. **Numbered PDF receipts** and **annual consolidated giving statements**.
6. **Offline/cash donation recording** so the ledger reflects reality, not just online giving.
7. **Daily Paystack reconciliation** with a discrepancy report.
8. **A public transparency page** — where the money goes, allocation by division, annual reports.
9. **A donor wall** (with an anonymous option) and **live progress bars** on causes.
10. **Recurring-giving retention:** a "your impact this month" email, a failed-payment recovery flow, and a genuinely one-click cancel.

**Trust, safety & compliance**

11. **Consent management for beneficiary photographs and stories**, with guardian consent for minors, expiry, and revocation that unpublishes.
12. **Unconditional EXIF/GPS stripping** on every upload.
13. **A safeguarding policy page and a separate, confidential safeguarding contact channel.**
14. **Registration with Ghana's Data Protection Commission** as a data controller, and an Act 843-compliant privacy policy with working data-export and deletion requests.
15. **Mandatory 2FA on every admin account**, plus login-history alerts.
16. **A quarterly backup-restore drill**, logged. Backups nobody has restored are decoration.
17. **A whistleblowing / concerns page** — expected of a registered NGO.

**Technical**

18. **Off-server backups** (Backblaze B2 / Google Drive) — a backup living on the same shared account is not a backup.
19. **A performance budget enforced in CI** — LCP < 2.5 s on simulated 3G, JS < 100 KB gzipped. A Ghana-specific requirement, not a vanity metric.
20. **A media strategy with an object-storage escape hatch** for when inodes run out (risk SH-6).
21. **Uptime and webhook-health monitoring** with alerts, plus a queue heartbeat.
22. **A staging environment** with test keys and noindex, used for every change before production.
23. **An error-tracking service** (Sentry free tier or `spatie/laravel-flare`).
24. **A PWA shell with an offline page** — meaningful on intermittent Ghanaian mobile data.
25. **A cookie-consent preference centre** if any analytics or embeds are used.
26. **Branded error pages** (404, 419, 429, 500, 503) that keep a donation path visible.
27. **Localisation scaffolding now**, even launching English-only, so Twi later is a content job rather than a rewrite.
28. **A contrast validator inside the Filament theme editor** so a well-meaning content editor cannot break WCAG compliance with a colour picker.

**Content & operations**

29. **A photography and story-collection brief** for the team, including consent forms.
30. **"Our Story" treated as a flagship page**, not a paragraph — including Mrs Cecilia Anyatuik Adam's photograph and story. It is the reason this foundation exists and the reason people will give.
31. **An admin user manual** written for non-technical staff, with screenshots.
32. **A credential inventory** in a shared password manager, with two trustees holding recovery access.
33. **A launch checklist and a tested rollback plan.**
34. **A dedicated cPanel account in the foundation's name** before the first real donation (§4.4.1) — the single highest-value governance change available, and cheap compared with explaining a shared-hosting arrangement to an auditor.
35. **PHP-FPM enabled and the PHP version pinned** (§4.4.2, §4.4.3) — one performance win and one stability win, both free, both taking about two minutes.

## (c) Decisions I need from you before Phase 2

**Blocking — Phase 2 cannot start**

| # | Decision |
|---|---|
| 1 | ~~Domain name~~ ✅ `greaterhopefoundations.com`. **Still needed: who is the registrar, and is DNS hosted at InMotion?** (decides where the DMARC record goes). |
| 2 | ~~Is SSH enabled?~~ ✅ **Resolved — enabled, port 2222. Deployment strategy is locked and nothing about the environment blocks Phase 2 any more.** Residual: confirm the SSH **hostname** to use for CI. |
| 3 | **Is the Paystack merchant account open?** If not, start it today — KYC takes 3–10 working days. |
| 4 | **Confirm the exact registered legal name**, registration number, and registering authority. → `{{LEGAL_NAME}}`, `{{REGISTRATION_NUMBER}}`, `{{REGISTERING_AUTHORITY}}` |
| 5 | **Full contact block:** address, region/district, phone(s), WhatsApp, and the email address for each department. → §0.2 |
| 6 | **Confirm the brand direction:** green `#0B4D3F` + orange `#FC6302` as the brand, with blue `#0068EC` demoted to the Every Soul Missions accent — and confirm the icon-only logo gets re-coloured to match (§2.2). |
| 29 | **§4.4.1 — the shared hosting account.** Build and launch on the existing account with the travel business, or move `greaterhopefoundations.com` to its own cPanel account in the foundation's name first? **My recommendation: build and stage here now, move before you accept the first real donation.** |

**Needed early — they change the architecture**

| # | Decision | Options / my recommendation |
|---|---|---|
| 7 | **Donor accounts?** | **Recommend: yes.** Guest checkout for one-off gifts; an account required for recurring so it can be self-cancelled. |
| 8 | **Languages at launch** | **Recommend: English only**, with localisation scaffolding built in. |
| 9 | **What does the shop sell?** | Merchandise / beneficiary-made crafts / books / symbolic gift cards? This decides whether we need physical inventory, shipping and stock at all. |
| 10 | **Shop fulfilment** | Delivery in Ghana / pickup / digital — or all three? Which regions do you deliver to? |
| 11 | **Peer-to-peer fundraising?** | **Recommend: defer to post-launch.** A large module that needs an existing donor base to be worth anything. |
| 12 | **Volunteer sign-up and event ticketing?** | **Recommend: volunteers yes** (it is in your profile's partnership list); **paid ticketing defer**, free event registration at launch. |
| 13 | **SMS provider** | **Recommend: Arkesel or Hubtel** (Ghana-local, good deliverability, reasonable rates). **Register the sender ID this week** regardless — it has a lead time. |
| 14 | **Email provider** | **Recommend: Resend or Brevo** for transactional mail on our own reputation, with cPanel SMTP as a fallback only. Do not send receipts through the shared IP. |
| 15 | **Payment channels to enable** | **Recommend: Mobile Money first, then card.** Also bank transfer / USSD? |
| 16 | **Donation preset amounts** | Suggested: `GH₵ 50 / 100 / 250 / 500 / 1,000` + custom. Confirm what is realistic for your donor base. |
| 17 | **Recurring intervals** | Monthly only, or also weekly / quarterly / annual? |
| 18 | **Are donations tax-deductible in Ghana for your donors?** | Changes the receipt wording materially. |

**Needed before Phase 6 (frontend)**

| # | Decision |
|---|---|
| 19 | **SVG logo files** + a mono/white version for dark backgrounds and green sections (§2.3). |
| 20 | **The wordmark's typeface**, or approval to use Plus Jakarta Sans / Poppins as the heading face. |
| 21 | **Photography** — real, consented images of the foundation, its work, its people, and Mrs Cecilia Anyatuik Adam. Or a decision to launch with a photo-light, typography-led design, which I can make work. |
| 22 | **Board / leadership** names, roles, photos, short bios. |
| 23 | **Social media handles.** |

**Needed before launch**

| # | Decision |
|---|---|
| 24 | Bank account and settlement details for `/donate/other-ways`. |
| 25 | Who signs off content? Who is the named client-side approver? |
| 26 | The safeguarding policy and the designated safeguarding contact. |
| 27 | Whether the foundation is registered with Ghana's Data Protection Commission. |
| 28 | Who owns the domain and the Paystack account — the foundation, or an individual? |

## (d) What is now testable, and how you verify it

Phase 1 produces a document, not code — so what is testable here is the **document's own correctness** and the **environment's readiness**. Each item below is checkable by you today.

| # | What to verify | How | Pass criterion |
|---|---|---|---|
| 1 | **The extracted facts are correct** | Read §1 against the profile document and against the registration certificate | Every field in §1.1 matches the certificate character-for-character; every gap in §1.8 is either supplied or accepted |
| 2 | **The sampled brand colours are right** | Open the PNGs in any editor and eyedropper the wordmark and the heart | Deep green reads `#0B4D3F`, orange reads `#FC6302`, teal reads `#2EC4A8`, icon blue reads in the `#0068EC`–`#00BEFA` range |
| 3 | **Every contrast ratio in §5.5 is real** | Paste any pair into the WebAIM Contrast Checker (`webaim.org/resources/contrastchecker/`) | The ratio matches to ±0.02 and shows "AA Pass". Spot-check the five that matter most: `#FFFFFF` on `#0B4D3F`, `#3A1200` on `#FC6302`, `#0F1A17` on `#FFFFFF`, `#2EC4A8` on `#071310`, `#ECF5F2` on `#122420` |
| 4 | **The banned combinations really do fail** | Same tool: white on `#FC6302` | Returns **3.03:1 — AA Fail** for normal text. This confirms why the donate button uses dark ink, and is the check that stops it being "fixed" later |
| 5 | ~~PHP 8.3+ is available~~ ✅ **verified** — system version is `ea-php83`, and `ea-php84` runs on the sibling domain | — | Residual check: after pinning, MultiPHP Manager shows `PHP 8.3 (ea-php83)` for `greaterhopefoundations.com` **without** the "Inherited" badge |
| 6 | **The required PHP extensions exist** | Terminal → `php -m` | `bcmath, ctype, curl, dom, exif, fileinfo, gd, iconv, intl, mbstring, openssl, pdo_mysql, sodium, tokenizer, xml, zip` all appear |
| 7 | **SSH works end-to-end with the deploy key** | Generate the keypair, import the public key, **click Authorize**, then `ssh -i ~/.ssh/scghf_deploy -p 2222 presti98@{{SSH_HOST}}` | You get a shell prompt without being asked for a password. ⚠️ If it asks for a password, the key was imported but **not authorized** — go back and authorize it. |
| 8 | **Composer / Node presence** | Terminal → `composer -V`, `node -v`, `npm -v` | Either they exist (nice to have) or they do not (fine — strategy A does not need them). Either way we now *know*. |
| 9 | ~~The real home directory~~ ✅ **verified — `/home/presti98`**, user `presti98` | — | All §11.3/§11.4 paths are now literal and ready to paste |
| 9b | **The addon domain's document root** | Terminal → `ls -la /home/presti98`, or Domains page | You get one of `/home/presti98/greaterhopefoundations.com` or `/home/presti98/public_html/greaterhopefoundations.com`. **Whichever it is, `public_html` itself belongs to the travel site — do not modify it.** |
| 9c | **PHP-FPM is on** | MultiPHP Manager → PHP-FPM column for our domain | The ⊘ becomes a toggle showing enabled. Then re-run a Lighthouse test and compare TTFB before/after. |
| 10 | **Inode headroom** | Not in the statistics panel — Terminal → `df -i /home/presti98`, or ask support | Note the limit and current usage. Under 50% used at project start is comfortable; over 70% means we plan object storage from Phase 6. **This is the only quota cPanel does not show you.** |
| 11 | **Per-minute cron is allowed** | Cron Jobs → create `* * * * * date >> $HOME/cron-test.log`, wait 5 minutes, then `cat $HOME/cron-test.log` | Five lines, one per minute. Fewer means an interval restriction (risk SH-4) and the queue design changes. **Delete the test cron afterwards.** |
| 12 | **MySQL/MariaDB version** | phpMyAdmin home page → "Server version" | MySQL 8.0+ or MariaDB 10.6+ |
| 13 | **The sitemap matches your mental model** | Read §6.1 aloud and try to place each thing you want to say on the site | Nothing you care about is homeless, and nothing listed is something you would never use |
| 14 | **The role matrix matches your team** | Read §7.1 against the actual people who will use the admin | Every person maps to exactly one role, and nobody needs a capability their role lacks |
| 15 | **The donation journey matches reality** | Walk §8.1 and §8.3 through as if you were a donor in Ghana with an MTN number | Every step is something a real donor would do, and the MoMo waiting experience matches how MoMo actually behaves |
| 16 | **The Paystack merchant account is live** | Log into the Paystack dashboard | GHS is enabled, KYC is complete, and both `pk_test_`/`sk_test_` and `pk_live_`/`sk_live_` keys are visible |
| 17 | **SMS sender ID registration is submitted** | Provider dashboard | The sender ID shows as "pending" or "approved". If it is not even submitted, launch will slip. |
| 18 | **SPF and DKIM are valid** | cPanel → Email Deliverability | Both show a green/valid status for the sending domain |
| 19 | **AutoSSL covers everything** | SSL/TLS Status | The domain, `www`, and the staging subdomain all show a valid certificate |

**Nothing is deployable yet — Phase 1 has no code.** The first thing that becomes verifiable in a browser is at the end of **Phase 2**: the Laravel welcome page served over HTTPS from `public_html`, deployed automatically by a push to `main`, with `/.env` returning 403.

---

*End of Phase 1 Blueprint · St. Cecilia's Greater Hope Foundations · v1.2*
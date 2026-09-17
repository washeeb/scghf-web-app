# SEO, content and speed: the plan, and what the site already does

Companion: `PHASE-13-ACCESSIBILITY-REPORT.md`.

## 1. What is built (Phase 13)

| Area | Where | Notes |
|---|---|---|
| Per-page search & sharing fields | every content type's form, *Search & sharing* | title/description with character counts, canonical, robots, OG title/text/image/type, X card; fallbacks to the record's own title, summary and image |
| Structured data | `App\Support\StructuredData` | NGO (logo, address, contact points, socials), WebSite + SearchAction, BreadcrumbList, Article, Product + Offer (GHS, availability), Event, FAQPage, DonateAction |
| Sitemaps | `/sitemap.xml` → `/sitemaps/{pages,posts,programmes,shop,events,indexes}.xml` | cached per type, forgotten on any publish |
| robots.txt | `/robots.txt` | from `seo.allow_indexing` + *Settings → Search engines → Extra robots.txt lines* |
| Canonicals | `PageMeta::selfCanonical()` | the page's own URL; `?page=` kept on lists, every other parameter dropped |
| Redirects & 404 log | Phase 5 | *Content → Redirects*; the 404 log suggests them |
| Attribution | `App\Support\Attribution` | first-touch `utm_*` kept for the visit, stamped on donations and orders; *Finance → Site analytics → Income by campaign* |
| Analytics | *Settings → Analytics* | none / Plausible / Umami / GA4 in consent mode; loads only after the cookie notice's Analytics category is allowed |

**Not built, and why:** *hreflang* — `FEATURE_MULTILINGUAL` is off; the
site is English-only and a `hreflang` set on one language is noise.
*Sitemap ping* — Google retired it (June 2023); Bing's IndexNow needs a
key file and a POST per URL and is worth doing only once the site
publishes several times a week. *Google Search Console* — needs the
domain, see §5.

## 2. Keyword and content plan

Ghana's search volume for charity terms is modest and mostly on mobile;
the phrasing people actually type is plainer than a marketer's. The plan
below is by **intent**, because a donor, a partner and a beneficiary's
relative search for different things and should land on different pages.

### Donation intent — the pages that must rank

| Query shape | Page | Title tag to set |
|---|---|---|
| donate to charity in Ghana · donate online Ghana · give to orphans Ghana · support widows Ghana | `/donate` | "Donate to children and families in Ghana — Greater Hope Foundations" |
| donate with MTN MoMo · mobile money donation Ghana | `/donate` (the MoMo paragraph) and `/give` | "Give by Mobile Money or bank transfer" |
| monthly giving Ghana · sponsor a child Ghana (careful: the foundation does not run child sponsorship; the page must say what monthly giving *does* fund) | `/donate` with monthly selected | "Give monthly — regular support for the work" |
| donation receipt tax Ghana · is a donation tax deductible in Ghana | FAQ | one FAQ entry, worded from `docs/PHASE-3-DATA-ARCHITECTURE.md` §Acknowledgements |
| [appeal name] Ghana · borehole fundraiser Northern Region | each appeal page | the appeal's own title + place |

### Programme intent — what the foundation does

| Query shape | Page |
|---|---|
| orphan care Ghana · orphanage support Bolgatanga · widow support Ghana · NGO Upper East Region · education support Northern Ghana · school kits Ghana · medical outreach Ghana | Areas of work (`/what-we-do`), each project page, the impact page |
| [town] NGO · charity in [town] | the offices on `/contact`, project locations, the NGO's address in JSON-LD |

### Partner and corporate intent

| Query shape | Page |
|---|---|
| CSR partnership Ghana NGO · corporate social responsibility Ghana partner · in-kind donation Ghana | `/get-involved/partner-with-us` and the corporate enquiry form |
| volunteer Ghana · volunteer opportunities Accra / Tamale / Bolgatanga | `/volunteer` and each role |

### Blog topics that attract donors and partners (one a month is enough)

1. "What GH₵ 50 actually pays for" — one gift, itemised, with a photo
   that has consent. The single best page a donor can land on.
2. "A day at [project]" — a field diary; names only with consent.
3. "How we choose who we help" — the beneficiary criteria, plainly. It
   answers the question every donor has and no NGO writes down.
4. "Where the money went in [quarter]" — the impact page's numbers, told
   as a story, with the expenditure log linked.
5. "Volunteering with us: what a Saturday looks like" — links to roles.
6. "How to give by MoMo, step by step" — screenshots; ranks for a query
   people type at the moment they want to give.
7. "What the Data Protection Act means for our donors" — trust content;
   links to the privacy policy.
8. "Partnering with a Ghanaian NGO: what companies ask us" — the
   corporate page's FAQ as a post.
9. Seasonal: back-to-school (August), Christmas (November), Eid, Ramadan
   — the appeal of the season, published three weeks before.
10. "Thank you, [year]" — every donor's year, with the totals.

Each post: a title under 60 characters with the place name in it, an
excerpt of about 155 characters written as a reason to click, a featured
image with alt text, one internal link to an appeal and one to the
donate page, a category. The *Search & sharing* fields are for the rare
post where the headline is not the best search title.

### Naming consistency (NAP)

The legal name, address, phone and email must be **identical** on: the
website footer and contact page, Google Business Profile, Facebook,
Instagram, the Ghana Business Directory / Registrar General listing,
Paystack's merchant profile, and the DPC registration. Pick one form of
the address (the Ghana Post GPS code included) and use it everywhere.
The Organisation JSON-LD emits what *Settings → Contact* holds, so that
is the master copy.

## 3. Local SEO for Ghana

1. **Google Business Profile** — claim "St. Cecilia's Greater Hope
   Foundations" as a *Non-profit organization* at the office address;
   verification is by postcard or phone. Add the logo, six photographs
   (with consent), opening hours (the Offices table has them), the
   website, the donate link, and a description that uses the programme
   terms above. Post once a month (the blog post) — profiles with posts
   rank higher in the local pack.
2. **Directories** — Ghana Business Directory, GhanaYello, the Department
   of Social Welfare NGO register, and any diocesan or denominational
   listing. Same NAP, same website URL.
3. **Backlinks that matter** — partner organisations' "partners" pages,
   the churches and schools the foundation works with, local news pieces
   about projects. Each is worth more than any on-page change.
4. **hreflang** — not applicable until a second language exists.

## 4. Core Web Vitals

Targets (Phase 1): LCP < 2.5 s on simulated 3G, CLS < 0.1, INP < 200 ms.
The budget in CI: 100 KB gzipped JavaScript, and the built bundle is
~2 KB gzipped.

| Measure | State | Where |
|---|---|---|
| Image sizing | ✔ `srcset` from the media conversions, intrinsic `width`/`height` so nothing shifts, `loading="lazy"` except the hero, `fetchpriority="high"` on the hero | `components/media/image.blade.php` |
| Modern formats | ✔ WebP conversions (AVIF optional, `MEDIA_AVIF`) | `config/media.php` |
| Font loading | ✔ self-hosted WOFF2, `font-display: swap`, two weights | `resources/css/app.css` (Phase 8) |
| Critical CSS | ✔ the theme tokens are inlined in `<head>`; the stylesheet is 58 KB (8 KB gzipped) and render-blocking by design — smaller than the cost of a flash of unstyled content on 3G | layout |
| Deferred JS | ✔ one 6 KB module, `type="module"` (deferred by definition); no Alpine, no Livewire on the public site; the analytics script only after consent | `resources/js/app.js` |
| Caching headers | ✔ `Cache-Control: public, max-age=31536000, immutable` on hashed assets; 6 months on images; 1 hour on sitemaps | `public/.htaccess` |
| Compression | ✔ `mod_deflate` (gzip) and `mod_brotli` when the host has it | `public/.htaccess` |
| Third-party scripts | ✔ none on the public site until consent; Paystack's inline script only on the pay page; Turnstile only on the donate form when keys are set | CSP names them |
| Server response | ◐ shared hosting; PHP-FPM + OPcache (Phase 2); page cache: the impact page caches an hour, the sitemaps an hour. **Cloudflare in front** (Phase 12 doc) caches every static file at the edge — the single biggest TTFB win available on this host | |
| Layout shift | ✔ images sized; fonts swap to metric-compatible fallbacks; the cookie notice is `position: fixed` (no shift); the announcement bar is server-rendered (no late insert) | |

**What to measure:** Lighthouse mobile on the home, donate and one
appeal page of *staging*, throttled "Slow 4G"; and PageSpeed Insights on
production once live (field data needs 28 days of real visits). Expect
Performance 85–95 on this host; the remaining points are server time,
which Cloudflare's edge cache and the host's PHP version move.

**What would move it further:** an object cache (none on shared
hosting), HTTP/2 push (deprecated), or a static build of the home page
(not worth the complexity while the CMS is live-rendered and cached
per block).

## 5. Setup checklist (a person, once)

1. Google Search Console: add the domain (DNS TXT), submit
   `/sitemap.xml`, confirm the four rich-result types show no errors.
2. Bing Webmaster Tools: import from Search Console.
3. Google Business Profile (§3).
4. *Settings → Search engines → Allow indexing* **on** for production only
   — staging stays off, and its `robots.txt` says so.
5. Fill *Search & sharing* on the ten pages that matter (home, donate,
   the areas of work, the top appeals); leave the rest to the fallbacks.
6. Choose an analytics provider or none (*Settings → Analytics*);
   Umami cloud's free tier or Plausible's smallest plan are the
   privacy-respecting choices; GA4 is free and runs in consent mode here.
7. Share links with `?utm_source=whatsapp&utm_campaign=<appeal>` and read
   *Income by campaign* a month later.

<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Media library
|--------------------------------------------------------------------------
|
| What may be uploaded, what is made from it, and where it is allowed to be
| used. config/media-library.php is spatie's own configuration; this is the
| foundation's policy on top of it.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | What may be uploaded
    |--------------------------------------------------------------------------
    |
    | Keyed by the MIME type SNIFFED FROM THE BYTES, with the extensions that
    | are allowed to carry it. Both halves are checked, in both directions.
    |
    | ⚠ THE BROWSER'S CONTENT-TYPE IS NOT CONSULTED. It is a claim made by the
    | thing uploading the file, and the thing uploading the file is sometimes
    | not a browser. A file called `photo.jpg`, announced as `image/jpeg`, whose
    | first bytes are `<?php` is the whole attack, and the only part of that
    | sentence which is evidence is the bytes.
    |
    | ⚠ SVG IS NOT HERE, AND MUST NOT BE ADDED. An SVG is an XML document that
    | can contain <script>, and it would be served from the same origin as the
    | admin panel — so an uploaded SVG is stored cross-site scripting against
    | whoever opens it, with the session of whoever opens it. Sanitising SVG
    | reliably is a losing game played against every new XML parser quirk;
    | refusing it is a game nobody has to keep playing. Logos go in as PNG.
    */
    'accept' => [

        'image' => [
            'image/jpeg' => ['jpg', 'jpeg'],
            'image/png' => ['png'],
            'image/webp' => ['webp'],
            // Read but never produced: GIF is accepted because people have
            // them, and converted to nothing — animation would be lost by a
            // conversion, so animated GIFs keep their original only.
            'image/gif' => ['gif'],
        ],

        'document' => [
            'application/pdf' => ['pdf'],
            'text/csv' => ['csv'],
            'text/plain' => ['txt', 'csv'],
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Size limits, in bytes
    |--------------------------------------------------------------------------
    |
    | Eight megabytes for an image is generous — a 12-megapixel phone photograph
    | is around four. The limit exists less to save disk than to stop one upload
    | exhausting the PHP memory limit while GD holds it uncompressed: a
    | 6000×4000 JPEG needs roughly 96MB of memory as a raw bitmap, and shared
    | hosting commonly caps PHP at 128 or 256.
    |
    | ⚠ These are the OUTER limit. PHP's own `upload_max_filesize` and
    | `post_max_size` still apply and are frequently smaller on shared hosting,
    | where they are the real ceiling and produce a much worse error. Check them
    | with `scghf:media-doctor`.
    */
    'max_bytes' => [
        'image' => (int) env('MEDIA_MAX_IMAGE_MB', 8) * 1024 * 1024,
        'document' => (int) env('MEDIA_MAX_DOCUMENT_MB', 20) * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | The smallest image worth keeping
    |--------------------------------------------------------------------------
    |
    | Under this on both axes it is an icon or a tracking pixel, not a
    | photograph, and putting it on a page produces a smear. Refused with an
    | explanation rather than accepted and stretched.
    */
    'min_dimension' => 200,

    /*
    |--------------------------------------------------------------------------
    | Which image driver to use
    |--------------------------------------------------------------------------
    |
    | `auto` asks the server rather than telling it: Imagick when the account
    | has it, GD otherwise. That is the whole of "detect and degrade gracefully"
    | — the answer is different on a developer's laptop from the answer on the
    | shared account the site actually runs on, and it changes when the host
    | upgrades a server without telling anybody.
    |
    | Imagick is preferred where present: better resampling, more formats, and
    | it does not hold the decompressed bitmap inside PHP's own memory_limit the
    | way GD does — which on a 128MB shared account is the difference between a
    | 6000px photograph converting and a white screen.
    |
    | Set IMAGE_DRIVER to `gd` or `imagick` to overrule the detection, which is
    | worth doing only to reproduce a production problem locally.
    */
    'driver' => env('IMAGE_DRIVER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Conversions
    |--------------------------------------------------------------------------
    |
    | Three widths, and no more. Each conversion is a FILE, and files are the
    | scarce resource on this host — the inode quota is what a media library
    | exhausts first, long before the disk quota. Three widths plus the original
    | is four inodes per image; adding a second output format would make it
    | seven, and 2,000 photographs would then be 14,000 inodes spent on
    | something a fourth of that size delivers.
    |
    | Widths chosen against the layout rather than against round numbers:
    |
    |   thumb  320  the admin grid and any list row
    |   card   800  a card in a two-or-three-column grid at typical DPR
    |   hero  1600  full-bleed, and the largest anything on this site renders
    |
    | An image is never UPSCALED to reach one of these. A 500px original gets a
    | thumb and nothing else — an 1600px conversion of it would be a bigger file
    | that looks worse.
    */
    'conversions' => [
        'thumb' => ['width' => 320, 'quality' => 70],
        'card' => ['width' => 800, 'quality' => 78],
        'hero' => ['width' => 1600, 'quality' => 82],
    ],

    /*
    |--------------------------------------------------------------------------
    | Output format
    |--------------------------------------------------------------------------
    |
    | WebP for every conversion: roughly a third smaller than JPEG at the same
    | perceived quality, and supported by every browser this site will meet. The
    | ORIGINAL is always kept in its own format, so there is a fallback that
    | needs no decoder.
    |
    | AVIF is smaller still and is deliberately OFF. Encoding it costs seconds
    | rather than milliseconds per image on a shared CPU, which on a bulk upload
    | is a request that times out halfway through — and the toolchain for it is
    | frequently absent on shared hosting. The code path is built and tested;
    | turn it on only after `scghf:media-doctor` says the account can encode it,
    | and only with conversions queued.
    */
    'formats' => [
        'webp' => (bool) env('MEDIA_WEBP', true),
        'avif' => (bool) env('MEDIA_AVIF', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filenames
    |--------------------------------------------------------------------------
    */
    'filename' => [

        /*
         * A stored filename is a path segment and, on a public disk, part of a
         * URL. `../`, a null byte, a right-to-left override character and a
         * second extension are all things a filename can contain and none of
         * them survive this.
         *
         * The most important rule is the one about dots: `invoice.php.jpg` is
         * a file some server configurations will happily execute, so
         * everything after the first dot is collapsed and a single extension
         * derived from the SNIFFED type is appended.
         */
        'max_length' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Where media is used
    |--------------------------------------------------------------------------
    |
    | ── Discovered, not declared, and this is the one place that is right ────
    |
    | Everywhere else in this project a registry is an explicit list, so that a
    | thing missing from it is visible by its absence. Here the opposite is
    | safer, and the schema says why.
    |
    | Thirty-four foreign keys across thirty tables point at `media`, under a
    | dozen different column names — `media_id`, `image_id`, `photo_id`,
    | `logo_id`, `cover_id`, `featured_image_id`, `og_image_id`, `photograph_id`,
    | `evidence_media_id`, `pdf_media_id`, `cv_media_id`, `id_document_id`,
    | `signature_id`, `document_id`. A hand-written list of those would be wrong
    | within a phase, and the failure mode of being wrong is not cosmetic:
    |
    |   32 of the 34 are ON DELETE SET NULL. Deleting a file that is in use does
    |   not fail and does not warn — it silently detaches. A donation receipt
    |   loses its PDF. A beneficiary loses their ID document. A CONSENT RECORD
    |   LOSES THE EVIDENCE IT IS EVIDENCE OF, and still reads as a consent with
    |   evidence to anybody auditing it.
    |
    |   The other 2 are ON DELETE CASCADE, which deletes the row outright.
    |
    | So `MediaUsage` reads the foreign keys from information_schema and covers
    | every column by construction, including ones added after this was written.
    | A column that nobody remembered is exactly the column this has to catch.
    |
    | The list below is only for LABELS — what a person is shown when they are
    | told they cannot delete something. A table with no entry falls back to a
    | humanised table name, which is worse prose and equally safe.
    */
    'usage_labels' => [
        'announcements' => ['label' => 'Announcement', 'title' => 'title'],
        'beneficiaries' => ['label' => 'Beneficiary record', 'title' => 'reference'],
        'beneficiary_documents' => ['label' => 'Beneficiary document', 'title' => 'title'],
        'cause_updates' => ['label' => 'Cause update', 'title' => 'title'],
        'causes' => ['label' => 'Cause', 'title' => 'title'],
        'consents' => ['label' => 'Consent evidence', 'title' => 'granted_by_name'],
        'digital_download_tokens' => ['label' => 'Digital download', 'title' => 'token'],
        'divisions' => ['label' => 'Division', 'title' => 'name'],
        'documents' => ['label' => 'Document', 'title' => 'title'],
        'donation_receipts' => ['label' => 'Donation receipt', 'title' => 'receipt_number'],
        'events' => ['label' => 'Event', 'title' => 'title'],
        'fundraisers' => ['label' => 'Fundraising page', 'title' => 'title'],
        'galleries' => ['label' => 'Gallery', 'title' => 'title'],
        'gallery_items' => ['label' => 'Gallery item', 'title' => 'caption'],
        'invoices' => ['label' => 'Invoice', 'title' => 'number'],
        'partners' => ['label' => 'Partner', 'title' => 'name'],
        'payouts' => ['label' => 'Payout evidence', 'title' => 'reference'],
        'posts' => ['label' => 'Blog post', 'title' => 'title'],
        'product_categories' => ['label' => 'Shop category', 'title' => 'name'],
        'product_images' => ['label' => 'Product image', 'title' => 'alt_text'],
        'products' => ['label' => 'Product', 'title' => 'name'],
        'project_updates' => ['label' => 'Project update', 'title' => 'title'],
        'projects' => ['label' => 'Project', 'title' => 'title'],
        'safeguarding_checks' => ['label' => 'Safeguarding check', 'title' => 'check_type'],
        'seo_meta' => ['label' => 'Share image', 'title' => 'title'],
        'sponsorship_updates' => ['label' => 'Sponsorship update', 'title' => 'title'],
        'stories' => ['label' => 'Story', 'title' => 'title'],
        'tax_approvals' => ['label' => 'GRA approval document', 'title' => 'reference'],
        'team_members' => ['label' => 'Team member', 'title' => 'name'],
        'testimonials' => ['label' => 'Testimonial', 'title' => 'author_name'],
        'volunteer_applications' => ['label' => 'Volunteer application', 'title' => 'full_name'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tables whose use of a file is CONFIDENTIAL
    |--------------------------------------------------------------------------
    |
    | "This file is used by 3 things" is safe to show anybody who can open the
    | media library. "This file is the evidence for Ama Mensah's consent" is
    | not — it names a beneficiary to whoever happens to be looking at a photo.
    |
    | For these tables the guard still refuses the deletion and still says how
    | many places, but the identifying title is withheld unless the person
    | holds `beneficiaries.view`. Refusing to delete does not require knowing
    | who; being told who does.
    */
    'confidential_usage' => [
        'beneficiaries',
        'beneficiary_documents',
        'consents',
        'safeguarding_checks',
        'volunteer_applications',
    ],

    /*
    |--------------------------------------------------------------------------
    | Inode budget
    |--------------------------------------------------------------------------
    |
    | Shared hosting counts FILES, not bytes, and the media library is what
    | exhausts that count. `scghf:media-doctor` compares the projection against
    | this so the wall is visible before it is hit rather than as a deploy that
    | fails to write.
    |
    | Set it to the account's actual quota once it is known; the default is a
    | conservative stand-in.
    */
    'inode_budget' => (int) env('MEDIA_INODE_BUDGET', 200_000),
];

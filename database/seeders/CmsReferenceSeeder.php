<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\BlogCategory;
use App\Models\ContactDepartment;
use App\Models\FaqCategory;
use App\Models\MediaFolder;
use Illuminate\Database\Seeder;

/**
 * Reference data the CMS needs before staff can use it: the categories,
 * departments and folders that content is filed under.
 *
 * Idempotent, and never overwrites an edited name or email.
 */
class CmsReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedContactDepartments();
        $this->seedFaqCategories();
        $this->seedBlogCategories();
        $this->seedMediaFolders();

        $this->command?->info(sprintf(
            'CMS reference: %d contact departments, %d FAQ categories, %d blog categories, %d media folders.',
            ContactDepartment::count(),
            FaqCategory::count(),
            BlogCategory::count(),
            MediaFolder::count(),
        ));
    }

    /**
     * The routing table for the contact form — Blueprint §8.7.
     *
     * Emails are seeded as {{PLACEHOLDER}} for the same reason as settings: an
     * unfilled token is greppable and reportable, where an empty string is
     * indistinguishable from one someone meant to clear.
     */
    private function seedContactDepartments(): void
    {
        $departments = [
            ['general', 'General enquiry', '{{EMAIL_GENERAL}}', false, 48],
            ['donations', 'Donations & finance', '{{EMAIL_DONATIONS}}', false, 24],
            ['volunteering', 'Volunteering', '{{EMAIL_VOLUNTEER}}', false, 72],
            ['shop', 'Shop & orders', '{{EMAIL_SHOP}}', false, 24],
            ['media', 'Media & partnerships', '{{EMAIL_MEDIA}}', false, 72],
            // Confidential. Never appears in the general admin inbox, and
            // routes only to the designated safeguarding contact.
            ['safeguarding', 'Safeguarding concern', '{{EMAIL_SAFEGUARDING}}', true, 4],
        ];

        foreach ($departments as $i => [$key, $name, $email, $confidential, $sla]) {
            $existing = ContactDepartment::where('key', $key)->first();

            ContactDepartment::updateOrCreate(
                ['key' => $key],
                [
                    'name' => $existing?->name ?? $name,
                    // A real email, once supplied, must survive a re-run.
                    'email' => $existing !== null && ! str_contains((string) $existing->email, '{{')
                        ? $existing->email
                        : $email,
                    'is_confidential' => $confidential,
                    'sla_hours' => $sla,
                    'sort_order' => $i,
                ],
            );
        }
    }

    private function seedFaqCategories(): void
    {
        $categories = [
            ['Giving', 'How donations work, receipts and recurring gifts.'],
            ['Our work', 'The four divisions and how we choose what to support.'],
            ['Volunteering', 'Applying, safeguarding checks and what to expect.'],
            ['Shop & orders', 'Delivery, pickup and returns.'],
            ['Your data', 'What we hold, why, and your rights under Act 843.'],
        ];

        foreach ($categories as $i => [$name, $description]) {
            FaqCategory::firstOrCreate(
                ['slug' => str($name)->slug()->toString()],
                ['name' => $name, 'description' => $description, 'sort_order' => $i],
            );
        }
    }

    private function seedBlogCategories(): void
    {
        // Four of these mirror the divisions, so a post about a health outreach
        // files naturally. The division relation itself arrives in Module 3.
        $categories = [
            ['Foundation news', '#0B4D3F'],
            ['Health', '#0F766E'],
            ['Education', '#B83E00'],
            ['Orphans, widows & widowers', '#0B4D3F'],
            ['Missions', '#0059C9'],
            ['Impact stories', '#FC6302'],
        ];

        foreach ($categories as $i => [$name, $colour]) {
            BlogCategory::firstOrCreate(
                ['slug' => str($name)->slug()->toString()],
                ['name' => $name, 'colour' => $colour, 'sort_order' => $i],
            );
        }
    }

    /**
     * Media folders.
     *
     * `Beneficiaries` is locked: consent rules are keyed to it, so it must not
     * be renamed out from under the code that checks them.
     */
    private function seedMediaFolders(): void
    {
        $folders = [
            ['Brand', 'Logos, wordmarks and brand assets.', true],
            ['Programmes', 'Photography from projects and outreach.', false],
            ['Beneficiaries', 'Photographs of people we serve. Consent required before publication.', true],
            ['People', 'Trustees, staff and volunteers.', false],
            ['Documents', 'Reports, policies and forms.', false],
            ['Shop', 'Product photography.', false],
        ];

        foreach ($folders as $i => [$name, $description, $locked]) {
            $folder = MediaFolder::firstOrNew(['path' => '/'.str($name)->slug()->toString()]);

            if (! $folder->exists) {
                $folder->fill(['name' => $name, 'description' => $description, 'sort_order' => $i]);
            }

            $folder->is_locked = $locked;
            $folder->save();
        }
    }
}

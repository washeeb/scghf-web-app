<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models;

/**
 * Which policy governs which model.
 *
 * ── Why a list rather than Laravel's convention ─────────────────────────────
 *
 * Laravel guesses `Donation` → `DonationPolicy` by name. That works until a
 * model has no policy of its own, at which point the guess returns nothing and
 * `$user->can()` quietly answers false everywhere — or, on a model reached
 * through a Filament resource, hides the whole thing with no error.
 *
 * Most models here are governed by a parent's permissions: a `DonationItem` is
 * part of a donation and is not separately authorised. Convention cannot
 * express that. This list can, and being explicit means a model added in a year
 * with no entry is caught by `PolicyCoverageTest` rather than discovered when
 * somebody cannot see it.
 *
 * ── The second list matters as much as the first ────────────────────────────
 *
 * `AUTHORISED_ELSEWHERE` is not a list of exemptions. Every entry states why
 * that model needs no policy of its own — because it is a pivot, because it is
 * framework-owned, or because reaching it at all requires already being
 * authorised for its parent. A model may only be there with a reason recorded.
 */
final class PolicyMap
{
    /**
     * Model class → policy class.
     *
     * @return array<class-string, class-string>
     */
    public static function policies(): array
    {
        return [
            // ── Content ──────────────────────────────────────────────────────
            Models\Page::class => PagePolicy::class,
            Models\PageSection::class => PagePolicy::class,
            Models\PageRevision::class => PagePolicy::class,
            Models\BlockType::class => PagePolicy::class,

            Models\Post::class => BlogPolicy::class,
            Models\BlogCategory::class => BlogPolicy::class,
            Models\Comment::class => BlogPolicy::class,
            Models\Tag::class => BlogPolicy::class,

            Models\Menu::class => MenuPolicy::class,
            Models\MenuItem::class => MenuPolicy::class,

            Models\Faq::class => FaqPolicy::class,
            Models\FaqCategory::class => FaqPolicy::class,
            Models\Testimonial::class => TestimonialPolicy::class,
            Models\Partner::class => PartnerPolicy::class,
            Models\Gallery::class => GalleryPolicy::class,
            Models\GalleryItem::class => GalleryPolicy::class,
            Models\Document::class => DocumentPolicy::class,
            Models\Announcement::class => AnnouncementPolicy::class,
            Models\Redirect::class => RedirectPolicy::class,
            Models\SeoMeta::class => SeoPolicy::class,
            Models\Media::class => MediaPolicy::class,
            Models\MediaFolder::class => MediaPolicy::class,

            // ── Programmes ───────────────────────────────────────────────────
            Models\Division::class => DivisionPolicy::class,
            Models\FocusArea::class => DivisionPolicy::class,
            Models\TeamMember::class => DivisionPolicy::class,
            Models\TeamDepartment::class => DivisionPolicy::class,
            // Contact details are settings; the same permission edits both.
            Models\Office::class => SettingPolicy::class,

            Models\Project::class => ProjectPolicy::class,
            Models\ProjectUpdate::class => ProjectPolicy::class,
            Models\ProjectMilestone::class => ProjectPolicy::class,
            Models\ProjectLocation::class => ProjectPolicy::class,

            Models\Cause::class => CausePolicy::class,
            Models\CauseUpdate::class => CausePolicy::class,

            Models\ImpactMetric::class => ImpactPolicy::class,
            Models\ImpactMetricValue::class => ImpactPolicy::class,
            Models\BeneficiaryImpactRecord::class => ImpactPolicy::class,

            // The sensitive ones. Their own policy, with hard deletion refused.
            Models\Beneficiary::class => BeneficiaryPolicy::class,
            Models\BeneficiaryDocument::class => BeneficiaryPolicy::class,

            Models\Consent::class => ConsentPolicy::class,
            Models\Story::class => StoryPolicy::class,

            // ── Fundraising — the ledger ─────────────────────────────────────
            Models\Donation::class => DonationPolicy::class,
            Models\DonationItem::class => DonationPolicy::class,
            Models\DonationReceipt::class => DonationPolicy::class,
            Models\DonationPlan::class => DonationPolicy::class,

            Models\PaymentTransaction::class => PaymentPolicy::class,
            Models\PaymentWebhookEvent::class => PaymentPolicy::class,
            Models\Refund::class => PaymentPolicy::class,

            Models\Payout::class => PayoutPolicy::class,
            Models\Donor::class => DonorPolicy::class,
            Models\Subscription::class => SubscriptionPolicy::class,
            Models\SubscriptionCharge::class => SubscriptionPolicy::class,
            Models\Fundraiser::class => FundraiserPolicy::class,
            Models\Pledge::class => PledgePolicy::class,

            // ── Shop ─────────────────────────────────────────────────────────
            Models\Product::class => ProductPolicy::class,
            Models\ProductVariant::class => ProductPolicy::class,
            Models\ProductImage::class => ProductPolicy::class,
            Models\ProductCategory::class => ProductPolicy::class,

            Models\InventoryMovement::class => InventoryPolicy::class,

            Models\Order::class => OrderPolicy::class,
            Models\OrderItem::class => OrderPolicy::class,
            Models\OrderStatusHistory::class => OrderPolicy::class,
            Models\Invoice::class => OrderPolicy::class,
            Models\DigitalDownloadToken::class => OrderPolicy::class,
            Models\Cart::class => OrderPolicy::class,
            Models\CartItem::class => OrderPolicy::class,

            Models\Coupon::class => CouponPolicy::class,
            Models\CouponRedemption::class => CouponPolicy::class,
            Models\ShippingZone::class => ShippingPolicy::class,
            Models\ShippingRate::class => ShippingPolicy::class,
            Models\ProductReview::class => ReviewPolicy::class,

            // ── Engagement ───────────────────────────────────────────────────
            Models\Volunteer::class => VolunteerPolicy::class,
            Models\VolunteerApplication::class => VolunteerPolicy::class,
            Models\VolunteerOpportunity::class => VolunteerPolicy::class,
            Models\VolunteerHour::class => VolunteerPolicy::class,
            Models\VolunteerShift::class => VolunteerPolicy::class,
            Models\SafeguardingCheck::class => VolunteerPolicy::class,

            Models\Event::class => EventPolicy::class,
            Models\EventRegistration::class => EventPolicy::class,
            Models\EventTicket::class => EventPolicy::class,
            Models\IssuedTicket::class => EventPolicy::class,

            Models\ContactMessage::class => ContactPolicy::class,
            Models\ContactDepartment::class => ContactPolicy::class,

            Models\Newsletter::class => NewsletterPolicy::class,
            Models\NewsletterCampaign::class => NewsletterPolicy::class,
            Models\CampaignRecipient::class => NewsletterPolicy::class,
            Models\Subscriber::class => NewsletterPolicy::class,
            Models\SmsBroadcast::class => NewsletterPolicy::class,

            Models\PrayerRequest::class => PrayerRequestPolicy::class,

            Models\Sponsorship::class => SponsorshipPolicy::class,
            Models\SponsorshipUpdate::class => SponsorshipPolicy::class,

            // ── Communications ───────────────────────────────────────────────
            Models\EmailTemplate::class => EmailTemplatePolicy::class,
            Models\SmsTemplate::class => SmsTemplatePolicy::class,
            Models\EmailLog::class => EmailLogPolicy::class,
            Models\SmsLog::class => SmsLogPolicy::class,
            Models\NotificationLog::class => EmailLogPolicy::class,
            Models\Suppression::class => SuppressionPolicy::class,
            Models\ScheduledMessage::class => ScheduledMessagePolicy::class,
            Models\FailedJob::class => FailedJobPolicy::class,
            Models\InboundWebhookEvent::class => PaymentPolicy::class,

            // ── System ───────────────────────────────────────────────────────
            Models\User::class => UserPolicy::class,
            Models\LoginHistory::class => UserPolicy::class,

            Models\Setting::class => SettingPolicy::class,
            Models\SettingHistoryEntry::class => SettingPolicy::class,
            Models\ThemeSetting::class => SettingPolicy::class,

            Models\FeatureFlag::class => FeatureFlagPolicy::class,
            Models\ApiToken::class => ApiTokenPolicy::class,

            Models\AuditLog::class => AuditPolicy::class,
            Models\AuditArchive::class => AuditPolicy::class,

            Models\BackupLogEntry::class => BackupPolicy::class,
            Models\ErrorReport::class => ErrorReportPolicy::class,
            Models\VisitorStat::class => VisitorStatPolicy::class,

            // Legal holds, the retention log and the GRA approval record.
            Models\LegalHold::class => CompliancePolicy::class,
            Models\RetentionLogEntry::class => CompliancePolicy::class,
            Models\TaxApproval::class => CompliancePolicy::class,
        ];
    }

    /**
     * Models that need no policy of their own, and why.
     *
     * Not a list of exemptions — a list of decisions. Each entry states the
     * reason, and `PolicyCoverageTest` fails on any model that is in neither
     * list, so a new model cannot quietly arrive without one.
     *
     * @return array<class-string, string>
     */
    public static function authorisedElsewhere(): array
    {
        return [];
    }
}

<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Permission;

/**
 * Maps the standard abilities onto the permission strings that already exist.
 *
 * ── Why a base class rather than 109 hand-written policies ──────────────────
 *
 * Almost every model here is authorised the same way: "may this person view
 * donations?" is `donations.view`, and the answer does not depend on which
 * donation. Writing that out 109 times would produce 109 opportunities to get
 * one of them subtly wrong, and no reader could tell the interesting policies
 * from the boilerplate.
 *
 * So the boilerplate lives here once, and a concrete policy is usually three
 * lines naming its permission prefix. The ones that override something are
 * therefore the ones worth reading — which is the point.
 *
 * A thin class per prefix is still needed rather than one shared policy,
 * because `viewAny` receives no model instance: only the class it was
 * registered against can say which prefix applies.
 *
 * ── Deny by default ─────────────────────────────────────────────────────────
 *
 * An ability with no matching permission resolves to FALSE, never to "nothing
 * said no, so yes". That matters most for the case this is likeliest to meet:
 * a permission renamed in the seeder and not renamed here. Failing closed makes
 * that a support ticket; failing open makes it a breach nobody notices.
 *
 * ── The `.manage` fallback ──────────────────────────────────────────────────
 *
 * The permission set is not uniform, and deliberately so: pages have
 * `pages.view`/`create`/`update`/`delete` because editing and publishing are
 * genuinely different jobs, while menus have a single `menus.manage` because
 * nobody has ever wanted to let somebody reorder a menu but not rename one.
 *
 * Rather than force one shape onto both, each ability tries its specific
 * permission first and falls back to `{prefix}.manage`.
 */
abstract class BasePolicy
{
    /**
     * Every permission name that exists, cached for the request.
     *
     * @var array<string, true>|null
     */
    private static ?array $known = null;

    /**
     * The permission prefix this policy authorises against — `donations`,
     * `pages`, `beneficiaries`.
     */
    abstract protected function prefix(): string;

    public function viewAny(User $user): bool
    {
        return $this->permits($user, 'view');
    }

    public function view(User $user, Model $model): bool
    {
        return $this->permits($user, 'view');
    }

    public function create(User $user): bool
    {
        return $this->permits($user, 'create', 'update');
    }

    public function update(User $user, Model $model): bool
    {
        return $this->permits($user, 'update');
    }

    public function delete(User $user, Model $model): bool
    {
        return $this->permits($user, 'delete');
    }

    /**
     * Restoring a soft-deleted record is an update, not a creation.
     *
     * Deliberately not tied to `delete`: the person who may remove something is
     * not automatically the person who may bring it back, and bringing back a
     * page that was withdrawn for a reason is closer to publishing it again.
     */
    public function restore(User $user, Model $model): bool
    {
        return $this->permits($user, 'update');
    }

    /**
     * Permanent deletion — genuinely gone, not soft-deleted.
     *
     * Held to the `delete` permission AND nothing else granting it by
     * fallback, because `.manage` is a broad grant given to people who need to
     * do a job, and irreversible destruction is not that job.
     */
    public function forceDelete(User $user, Model $model): bool
    {
        return $this->permitsExactly($user, 'delete');
    }

    /**
     * Whether the user holds any of the named permissions, in order, plus the
     * prefix's `.manage` as a last resort.
     */
    protected function permits(User $user, string ...$abilities): bool
    {
        foreach ([...$abilities, 'manage'] as $ability) {
            $name = $this->prefix().'.'.$ability;

            if (self::exists($name)) {
                return $user->can($name);
            }
        }

        // Nothing matched. Denied — see the note on this class about why the
        // answer to "no such permission" is never yes.
        return false;
    }

    /** As `permits()`, but with no `.manage` fallback. */
    protected function permitsExactly(User $user, string $ability): bool
    {
        $name = $this->prefix().'.'.$ability;

        return self::exists($name) && $user->can($name);
    }

    /**
     * Whether a permission is registered at all.
     *
     * Checked before asking, because `User::hasPermissionTo()` throws on an
     * unknown permission name — and a policy that throws is a 500 on a page
     * that should simply have hidden a button.
     *
     * Read once per request from the same cache spatie already maintains, so
     * this costs nothing per call.
     */
    private static function exists(string $name): bool
    {
        if (self::$known === null) {
            self::$known = Permission::query()
                ->pluck('name')
                ->flip()
                ->map(fn (): bool => true)
                ->all();
        }

        return isset(self::$known[$name]);
    }

    /** For tests, and after seeding new permissions mid-request. */
    public static function forgetKnownPermissions(): void
    {
        self::$known = null;
    }
}

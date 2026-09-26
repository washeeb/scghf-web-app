<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Navigation menus and their nested items.
 *
 * CLAUDE.md requires staff to edit the header, footer and menus without
 * touching code, so the layout renders whatever is here — there is no
 * hardcoded nav anywhere in Blade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table) {
            $table->id();

            // How Blade asks for it: menu('header'). Stable, so renaming the
            // menu for staff does not change what the layout resolves.
            $table->string('key', 64)->unique();

            $table->string('name', 191);
            $table->text('description')->nullable();

            // Menus the layout depends on existing. An admin may reorder and
            // relabel items freely, but deleting 'header' would leave the site
            // without navigation.
            $table->boolean('is_locked')->default(false);

            // How deep this menu may nest. The header supports one level of
            // dropdown; the footer is flat. Enforced by the model so an admin
            // cannot build a three-deep menu the layout cannot render.
            $table->unsignedTinyInteger('max_depth')->default(1);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();

            $table->foreignId('menu_id')->constrained()->cascadeOnDelete();

            // Deleting a parent takes its children with it — an orphaned
            // submenu item would silently vanish from the nav with no clue why.
            $table->foreignId('parent_id')->nullable()
                ->constrained('menu_items')->cascadeOnDelete();

            $table->string('label', 191);

            // page | route | entity | external | heading
            $table->string('link_type', 32)->default('page');

            /*
             * A page link resolves THROUGH this relation rather than storing a
             * URL, so renaming a page's slug updates every menu pointing at it.
             * A hardcoded URL is how navigation quietly rots.
             */
            $table->foreignId('page_id')->nullable()->constrained()->cascadeOnDelete();

            // For link_type = route, e.g. 'donate.index'.
            $table->string('route_name', 128)->nullable();

            // For link_type = external.
            $table->string('url', 500)->nullable();

            // For link_type = entity — a project, cause, product or post.
            $table->nullableMorphs('linkable');

            $table->string('icon', 64)->nullable();

            // A highlighted item — the Donate call to action in the header.
            $table->boolean('is_highlighted')->default(false);

            $table->boolean('opens_in_new_tab')->default(false);

            /*
             * Audience: 'all', 'guest', 'auth'.
             *
             * Lets the same menu carry "Sign in" and "My giving" without the
             * layout branching on auth state — the menu answers it.
             */
            $table->string('visible_to', 16)->default('all');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);

            $table->timestamps();

            // The render query: one menu's items, in tree order.
            $table->index(['menu_id', 'parent_id', 'sort_order'], 'menu_items_tree_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('menus');
    }
};

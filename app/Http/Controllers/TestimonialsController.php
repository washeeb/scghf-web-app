<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Testimonial;
use App\Support\PageMeta;
use Illuminate\View\View;

/**
 * What people say.
 *
 * ⚠ Consent is enforced by the model: a testimonial from a beneficiary, a
 * volunteer or a donor cannot be published without it, and the save throws.
 * Nothing extra is needed here — and nothing here may work around it. This page
 * renders `is_published` and trusts the gate that put it there.
 */
class TestimonialsController extends Controller
{
    public function __invoke(): View
    {
        return view('testimonials', [
            'testimonials' => Testimonial::query()
                ->where('is_published', true)
                ->with('photo')
                ->orderBy('sort_order')
                ->get(),

            'meta' => PageMeta::site(
                __('Testimonials'),
                __('In the words of the people we work with.'),
            ),
            'crumbs' => [
                ['label' => __('Home'), 'url' => url('/')],
                ['label' => __('Testimonials'), 'url' => null],
            ],
        ]);
    }
}

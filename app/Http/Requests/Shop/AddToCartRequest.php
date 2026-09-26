<?php

declare(strict_types=1);

namespace App\Http\Requests\Shop;

use App\Models\ProductVariant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Putting something in the basket.
 *
 * The variant is validated as BUYABLE, not merely as existing. A ULID for a
 * variant of an unpublished product — or of one pulled for regulatory review —
 * must not be accepted from a form somebody kept open, or from anybody who
 * guesses that the identifiers in the page source still work.
 */
class AddToCartRequest extends FormRequest
{
    private ?ProductVariant $variant = null;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'variant' => ['required', 'string', 'max:26'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $variant = $this->variant();

                if ($variant === null || ! $variant->is_active || ! ($variant->product?->isLive() ?? false)) {
                    $validator->errors()->add('variant', __('That item is not available.'));
                }
            },
        ];
    }

    public function variant(): ?ProductVariant
    {
        return $this->variant ??= ProductVariant::query()
            ->with('product')
            ->where('ulid', $this->string('variant')->toString())
            ->first();
    }
}

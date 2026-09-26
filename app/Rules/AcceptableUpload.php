<?php

declare(strict_types=1);

namespace App\Rules;

use App\Media\UploadPolicy;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * `UploadPolicy` as a validation rule, so a form gets the same answer the
 * library does.
 *
 * The point of the wrapper is that there is exactly one place that decides what
 * an acceptable upload is. Laravel's own `mimes:jpg,png` rule would be a second
 * one — and a weaker one, because it checks the guessed extension rather than
 * cross-checking the sniffed type against the name.
 */
class AcceptableUpload implements ValidationRule
{
    public function __construct(private readonly ?string $kind = null) {}

    /** Only images. */
    public static function image(): self
    {
        return new self('image');
    }

    /** Only documents. */
    public static function document(): self
    {
        return new self('document');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            $fail('The :attribute must be an uploaded file.');

            return;
        }

        $policy = app(UploadPolicy::class);

        if ($reason = $policy->reject($value)) {
            $fail($reason);

            return;
        }

        if ($this->kind === null) {
            return;
        }

        /*
         * The type is acceptable in general but not here — a PDF where a
         * photograph belongs. Worth its own message: "not accepted" would be
         * wrong, because it is accepted, just not in this field.
         */
        $mime = (string) $policy->sniff($value);

        if ($policy->kindFor($mime) !== $this->kind) {
            $fail($this->kind === 'image'
                ? 'This field needs an image. That file is a document.'
                : 'This field needs a document. That file is an image.');
        }
    }
}

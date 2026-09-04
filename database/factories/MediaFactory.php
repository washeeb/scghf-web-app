<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Media;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'model_type' => (new User)->getMorphClass(),
            'model_id' => $user,
            'uuid' => (string) Str::uuid(),
            'collection_name' => 'default',
            'name' => 'evidence',
            'file_name' => 'evidence.jpg',
            'mime_type' => 'image/jpeg',
            'disk' => 'public',
            'size' => 24_000,
            'manipulations' => [],
            'custom_properties' => [],
            'generated_conversions' => [],
            'responsive_images' => [],
            /*
             * Unsanitised by default, which is the state a real upload starts
             * in. A factory handing out pre-sanitised media would let the
             * publication gate rot untested.
             */
            'alt_text' => null,
        ];
    }

    /** Been through the sanitiser and safe to publish. */
    public function sanitised(): static
    {
        return $this->state(fn (): array => [
            'metadata_stripped_at' => now(),
            'alt_text' => 'A photograph of the reading club.',
        ]);
    }
}

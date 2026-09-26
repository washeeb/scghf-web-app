<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A place with a door.
 *
 * Hours are text per day rather than open/close times: "8–12, then 2–5",
 * "by appointment" and "closed" are all things an office actually says,
 * and a pair of time columns can say none of them.
 */
class Office extends Model
{
    use HasFactory;

    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    protected $fillable = [
        'name', 'address', 'gps_address', 'city', 'region', 'phone', 'whatsapp', 'email',
        'hours', 'directions_url', 'notes', 'is_primary', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'hours' => 'array',
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // One primary. Setting a second clears the first rather than refusing,
        // because "make this the main office" is what the person meant.
        static::saved(function (self $office): void {
            if ($office->is_primary) {
                static::query()->whereKeyNot($office->getKey())->where('is_primary', true)->update(['is_primary' => false]);
            }
        });
    }

    /** The wa.me link, from a number written however Ghanaians write them. */
    public function whatsappUrl(): ?string
    {
        if (blank($this->whatsapp)) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', (string) $this->whatsapp) ?? '';
        $international = str_starts_with($digits, '0') ? '233'.substr($digits, 1) : $digits;

        return $international === '' ? null : 'https://wa.me/'.$international;
    }

    /** Directions: the link given, else a maps search for the address. */
    public function directionsUrl(): ?string
    {
        if (filled($this->directions_url)) {
            return $this->directions_url;
        }

        $where = collect([$this->address, $this->city, $this->region])->filter();

        return $where->isEmpty()
            ? null
            : 'https://www.google.com/maps/search/?api=1&query='.urlencode($where->implode(', ').', Ghana');
    }

    /**
     * Hours, one row per day, in order — only the days that were filled in.
     *
     * @return array<int, array{day: string, hours: string}>
     */
    public function hoursRows(): array
    {
        $labels = ['mon' => __('Monday'), 'tue' => __('Tuesday'), 'wed' => __('Wednesday'), 'thu' => __('Thursday'), 'fri' => __('Friday'), 'sat' => __('Saturday'), 'sun' => __('Sunday')];
        $rows = [];

        foreach (self::DAYS as $day) {
            $value = trim((string) ($this->hours[$day] ?? ''));

            if ($value !== '') {
                $rows[] = ['day' => $labels[$day], 'hours' => $value];
            }
        }

        return $rows;
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true)->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('name');
    }
}

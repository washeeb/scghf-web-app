<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactDepartment extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'email',
        'is_confidential', 'sla_hours', 'sort_order', 'is_active',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'is_confidential' => false,
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return ['is_confidential' => 'boolean', 'is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }

    /** @return HasMany<ContactMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ContactMessage::class);
    }

    /**
     * Departments offered in the public contact form.
     *
     * Confidential departments ARE offered, because a safeguarding report needs
     * a route in. What differs is where the message goes afterwards.
     */
    #[Scope]
    protected function selectable(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order');
    }
}

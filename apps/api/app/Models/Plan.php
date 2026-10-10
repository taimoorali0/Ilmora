<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Plan extends Model
{
    use HasUuids;

    protected $fillable = ['name', 'max_students', 'max_staff', 'max_branches', 'monthly_message_credits',
        'modules', 'price_monthly', 'price_yearly', 'active'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'active' => 'boolean'];
    }
}

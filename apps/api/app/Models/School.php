<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class School extends Model
{
    use HasUuids;

    protected $table = 'schools';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function canWrite(): bool
    {
        return $this->status === 'active';
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Device extends Model
{
    use HasUuids;

    protected $table = 'devices';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['offline_valid_until' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime',
            'wipe_on_contact' => 'boolean'];
    }

    public function isUsable(): bool
    {
        return $this->revoked_at === null;
    }
}

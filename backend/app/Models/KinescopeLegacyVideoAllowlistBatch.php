<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KinescopeLegacyVideoAllowlistBatch extends Model
{
    protected $fillable = [
        'source_count',
        'source_sha256',
        'is_active',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'source_count' => 'integer',
            'is_active' => 'boolean',
            'activated_at' => 'immutable_datetime',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(KinescopeLegacyVideoAllowlistEntry::class, 'batch_id');
    }
}

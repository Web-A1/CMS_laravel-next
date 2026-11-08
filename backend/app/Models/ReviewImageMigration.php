<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewImageMigration extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['references' => 'array', 'switched_at' => 'datetime', 'pruned_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(ReviewImageAsset::class, 'asset_id');
    }
}

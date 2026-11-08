<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KinescopeLegacyVideoAllowlistEntry extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'embed_locator';

    protected $keyType = 'string';

    protected $fillable = [
        'batch_id',
        'embed_locator',
        'provider_video_id',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(KinescopeLegacyVideoAllowlistBatch::class, 'batch_id');
    }
}

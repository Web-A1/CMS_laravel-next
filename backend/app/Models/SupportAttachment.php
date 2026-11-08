<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAttachment extends Model
{
    protected $fillable = ['message_id', 'status', 'disk', 'source_key', 'preview_key', 'thumbnail_key', 'mime', 'bytes', 'width', 'height', 'error_code', 'media_reservation_id', 'media_deletion_id'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportMessage::class, 'message_id');
    }
}

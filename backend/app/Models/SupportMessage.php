<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportMessage extends Model
{
    protected $fillable = ['ticket_id', 'direction', 'body', 'author_id', 'sender', 'warnings', 'headers', 'logical_key', 'payload_hash', 'message_id', 'delivery_status', 'attempts', 'next_attempt_at', 'claimed_at', 'sent_at', 'error_code', 'ticket_revision', 'notify_failures'];

    protected function casts(): array
    {
        return ['warnings' => 'array', 'headers' => 'array', 'next_attempt_at' => 'datetime', 'claimed_at' => 'datetime', 'sent_at' => 'datetime', 'notify_failures' => 'boolean'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(AdminSupportTicket::class, 'ticket_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportAttachment::class, 'message_id');
    }
}

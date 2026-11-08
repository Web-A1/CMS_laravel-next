<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMailReceipt extends Model
{
    protected $fillable = ['mailbox', 'folder', 'uidvalidity', 'uid', 'message_id', 'fingerprint', 'identity_hash', 'disk', 'original_key', 'outcome', 'reason', 'support_message_id', 'received_at', 'intake_status', 'ticket_id', 'media_reservation_id', 'media_deletion_id'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }
}

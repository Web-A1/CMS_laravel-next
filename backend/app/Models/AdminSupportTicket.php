<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdminSupportTicket extends Model
{
    protected $fillable = ['user_id', 'assignee_id', 'subject', 'description', 'status', 'priority', 'metadata', 'contact_email', 'public_number', 'source', 'revision', 'last_message_at', 'submission_key', 'submission_hash', 'submission_result', 'media_protocol', 'birth_reservation_id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'submission_result' => 'array', 'revision' => 'integer', 'last_message_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class, 'ticket_id')->orderBy('id');
    }
}

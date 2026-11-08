<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminOperationEvent extends Model
{
    protected $fillable = ['actor_id', 'operation', 'resource', 'resource_id', 'idempotency_key', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}

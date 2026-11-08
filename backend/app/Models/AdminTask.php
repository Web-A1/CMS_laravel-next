<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminTask extends Model
{
    protected $fillable = ['assignee_id', 'created_by', 'title', 'description', 'status', 'priority', 'due_at', 'metadata'];

    protected function casts(): array
    {
        return ['assignee_id' => 'integer', 'due_at' => 'datetime', 'metadata' => 'array'];
    }
}

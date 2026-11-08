<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMediaDeletion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'manifest' => 'array', 'inventory_evidence' => 'array', 'completed_at' => 'datetime'];
    }
}

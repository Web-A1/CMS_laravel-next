<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewImageDeletion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['files' => 'array', 'directories' => 'array'];
    }
}

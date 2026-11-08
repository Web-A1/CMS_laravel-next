<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportMediaReservation extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['storage_identity' => 'array', 'files' => 'array', 'associated_at' => 'datetime'];
    }
}

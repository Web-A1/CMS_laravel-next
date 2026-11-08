<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SellableGrantRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'sellable_entity_id',
        'atom_kind',
        'atom_id',
        'grant_snapshot_version',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'atom_id' => 'integer',
            'grant_snapshot_version' => 'integer',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function sellableEntity(): BelongsTo
    {
        return $this->belongsTo(SellableEntity::class);
    }
}

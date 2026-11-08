<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentAuthor extends Model
{
    protected $fillable = ['slug', 'name', 'photo_path', 'avatar_path', 'home_sort_order', 'home_role_text', 'home_cutout_key', 'bio'];

    protected function casts(): array
    {
        return ['home_sort_order' => 'integer'];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(ContentEntry::class);
    }
}

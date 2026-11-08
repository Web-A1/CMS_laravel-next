<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class AdminShortLink extends Model { protected $fillable=['slug','target_path','created_by','is_active']; protected function casts(): array { return ['is_active'=>'boolean']; } }

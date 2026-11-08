<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserProfile extends Model
{
    public const FIELDS = [
        'birth_date', 'gender', 'school', 'study_year', 'enrollment_year',
        'study_direction', 'profession', 'interests', 'referral_source',
        'preferred_contact', 'contact_details', 'vk_profile',
    ];

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = self::FIELDS;

    protected function casts(): array
    {
        return ['revision' => 'integer', 'enrollment_year' => 'integer'];
    }
}

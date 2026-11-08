<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Rbac;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements FilamentUser
{
    public function hasStaffNameFields(): bool
    {
        return in_array($this->role, ['teacher', 'admin_full'], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $current = self::query()->find($this->getKey());

        return $panel->getId() === 'admin'
            && $current instanceof self
            && ! $current->blocksAuthenticationForMigration()
            && Rbac::hasPermission($current, 'admin.access');
    }

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const PASSWORD_STATE_MIGRATION_HOLD = 'migration_hold';

    public const STAFF_ROLES = ['admin_full', 'admin_limited', 'manager', 'teacher', 'polina'];

    protected $fillable = [
        'name',
        'email',
        'username',
        'phone',
        'phone_verified_at',
        'password',
        'password_state',
        'last_auth_method',
        'legacy_auto_verified',
        'role',
        'manager_id',
    ];

    protected $hidden = [
        'first_name',
        'last_name',
        'password',
        'remember_token',
        'migration_hold',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'legacy_auto_verified' => 'boolean',
            'migration_hold' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function isMigrationHeld(): bool
    {
        return (bool) $this->migration_hold;
    }

    public function hasDormantMigrationPassword(): bool
    {
        return $this->password_state === self::PASSWORD_STATE_MIGRATION_HOLD;
    }

    public function blocksAuthenticationForMigration(): bool
    {
        return $this->isMigrationHeld() || $this->hasDormantMigrationPassword();
    }

    public function requiresPhoneVerification(): bool
    {
        // Phone verification is collected at checkout, immediately before
        // creating a payment attempt or settling a zero-cash order.
        return false;
    }

    public function usesAdminWorkspace(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    public static function normalizeUsername(string $username): ?string
    {
        $username = strtolower(trim($username));

        return preg_match('/^[a-z][a-z0-9_.-]{2,63}$/D', $username) ? $username : null;
    }

    protected function username(): Attribute
    {
        return Attribute::make(set: function (?string $value): ?string {
            if ($value === null) {
                return null;
            }
            $username = self::normalizeUsername($value);
            if ($username === null) {
                throw ValidationException::withMessages(['username' => 'Недопустимый логин сотрудника.']);
            }

            return $username;
        });
    }

    public function identityStatus(): string
    {
        if (! $this->phone) {
            return 'phone_missing';
        }

        if (! self::isValidE164Phone($this->phone)) {
            return 'phone_invalid';
        }

        if (! $this->phone_verified_at) {
            return 'phone_unverified';
        }

        return 'phone_verified';
    }

    public static function normalizePhone(string $phone): ?string
    {
        $normalized = preg_replace('/\D+/', '', $phone);

        if (! $normalized) {
            return null;
        }

        if (strlen($normalized) === 11 && str_starts_with($normalized, '8')) {
            $normalized = '7'.substr($normalized, 1);
        }

        return '+'.$normalized;
    }

    public static function isValidE164Phone(?string $phone): bool
    {
        if (! $phone) {
            return false;
        }

        return (bool) preg_match('/^\+[1-9]\d{9,14}$/', $phone);
    }

    public function entitlementGrants(): HasMany
    {
        return $this->hasMany(UserEntitlementGrant::class);
    }

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(UserTag::class, 'user_tag')->orderBy('name');
    }

    public function protectedFileDeliveries(): HasMany
    {
        return $this->hasMany(ProtectedFileDelivery::class);
    }

    public function videoWatermarkSessions(): HasMany
    {
        return $this->hasMany(VideoWatermarkSession::class);
    }
}

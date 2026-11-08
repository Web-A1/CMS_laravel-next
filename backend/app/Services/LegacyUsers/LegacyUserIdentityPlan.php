<?php

namespace App\Services\LegacyUsers;

use App\Models\User;
use App\Models\UserProfile;
use Illuminate\Support\Facades\DB;

/** Source contacts establish eligibility, never an automatic merge with an existing account. */
final class LegacyUserIdentityPlan
{
    private array $emailCounts = [];

    private array $phoneCounts = [];

    private array $targetEmails = [];

    private array $targetPhones = [];

    private array $profiles = [];

    private array $tags = [];

    public array $sourceMemberIds = [];

    public array $questionPollIds = [];

    public function __construct(LegacyUserBundle $bundle, private readonly LegacyUserSourcePolicy $policy)
    {
        foreach ($bundle->rows('member') as $record) {
            $this->sourceMemberIds[$record['source_id']] = true;
            $row = $record['row'];
            $email = mb_strtolower(trim($row['email'] ?? ''));
            $phone = User::normalizePhone($row['phone'] ?? '');
            $this->emailCounts[$email] = ($this->emailCounts[$email] ?? 0) + 1;
            if (User::isValidE164Phone($phone)) {
                $this->phoneCounts[$phone] = ($this->phoneCounts[$phone] ?? 0) + 1;
            }
        }
        foreach (DB::table('users')->select(['id', 'email', 'phone'])->get() as $user) {
            $this->targetEmails[mb_strtolower(trim($user->email))][] = $user->id;
            if ($user->phone) {
                $this->targetPhones[$user->phone][] = $user->id;
            }
        }
        foreach ($bundle->rows('member_data_full') as $record) {
            if ($record['ownership_status'] === 'linked') {
                $this->profiles[$record['source_member_id']][] = $this->policy->sanitize('member_data_full', $record['row'])['payload'];
            }
        }
        foreach ($bundle->rows('tags') as $record) {
            if ($record['ownership_status'] === 'linked') {
                $this->tags[$record['source_member_id']][] = $this->policy->sanitize('tags', $record['row'])['payload']['tags'] ?? '';
            }
        }
        foreach ($bundle->rows('pollQuestion') as $record) {
            $this->questionPollIds[$record['source_id']] = $record['row']['poll_id'];
        }
    }

    public function decide(array $attributes, ?int $existingUserId): array
    {
        $source = json_decode($attributes['payload'], true, 64, JSON_THROW_ON_ERROR);
        $issues = json_decode($attributes['issues'], true, 64, JSON_THROW_ON_ERROR);
        $email = mb_strtolower(trim($source['email'] ?? ''));
        $phone = User::normalizePhone($source['phone'] ?? '');
        $eligible = filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 255 && ($this->emailCounts[$email] ?? 0) === 1;
        if (! $eligible) {
            $issues[] = ['field' => 'email', 'reason' => 'invalid_or_nonunique_email'];
        }
        if (array_diff($this->targetEmails[$email] ?? [], [$existingUserId])) {
            $eligible = false;
            $issues[] = ['field' => 'email', 'reason' => 'target_contact_conflict'];
        }
        if (! User::isValidE164Phone($phone) || ($this->phoneCounts[$phone] ?? 0) !== 1) {
            $issues[] = ['field' => 'phone', 'reason' => 'invalid_or_nonunique_phone'];
            $phone = null;
        } elseif (array_diff($this->targetPhones[$phone] ?? [], [$existingUserId])) {
            $eligible = false;
            $issues[] = ['field' => 'phone', 'reason' => 'target_contact_conflict'];
        }
        $profile = array_fill_keys(UserProfile::FIELDS, null);
        $profile['birth_date'] = $this->policy->birthDate($source['birth_date'] ?? null);
        $profile['gender'] = ($source['gender'] ?? '') ?: null;
        $full = $this->profiles[$attributes['source_member_id']] ?? [];
        if (count($full) > 1) {
            $issues[] = ['field' => 'profile', 'reason' => 'multiple_source_profiles'];
        } elseif ($full) {
            $row = $full[0];
            foreach (['school' => 'university', 'study_year' => 'study_course', 'study_direction' => 'study_direction',
                'profession' => 'profession', 'interests' => 'favorite_subjects', 'referral_source' => 'search_us_from',
                'preferred_contact' => 'communication_method', 'contact_details' => 'communication_contact'] as $target => $field) {
                $profile[$target] = ($row[$field] ?? '') !== '' ? ($row[$field] ?? null) : null;
            }
            if (preg_match('/^(19\d{2}|20\d{2})-/', $row['study_date_start'] ?? '', $year)) {
                $profile['enrollment_year'] = (int) $year[1];
            }
        }
        foreach ($profile as $field => $value) {
            $max = match ($field) {
                'gender' => 32, 'study_year', 'preferred_contact' => 80, 'interests' => null, default => 255,
            };
            if (is_string($value) && $max !== null && mb_strlen($value) > $max) {
                $profile[$field] = null;
                $issues[] = ['field' => $field, 'reason' => 'source_value_too_long'];
            }
        }
        $tags = [];
        foreach ($this->tags[$attributes['source_member_id']] ?? [] as $tag) {
            $tag = mb_strtolower(trim($tag));
            if ($tag === '') {
                continue;
            }
            if (mb_strlen($tag) > 80) {
                $issues[] = ['field' => 'tags', 'reason' => 'source_value_too_long'];
            } else {
                $tags[] = $tag;
            }
        }
        $tags = array_values(array_unique($tags));
        sort($tags);

        return [
            'eligible' => (bool) $eligible, 'email' => $email, 'phone' => $phone,
            'name' => $attributes['display_name'], 'profile' => $profile, 'tags' => $tags, 'issues' => $issues,
        ];
    }

    /** Preserve any value edited since the last import, including intentional removal. */
    public static function mergeFields(array $current, array $previous, array $incoming): array
    {
        $merged = $current;
        $conflicts = [];
        foreach ($incoming as $field => $value) {
            if (array_key_exists($field, $previous) && ($current[$field] ?? null) === $previous[$field]) {
                $merged[$field] = $value;
            } elseif (($current[$field] ?? null) !== $value) {
                $conflicts[] = $field;
            }
        }

        return ['values' => $merged, 'conflicts' => $conflicts];
    }
}

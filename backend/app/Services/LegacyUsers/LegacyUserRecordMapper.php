<?php

namespace App\Services\LegacyUsers;

use InvalidArgumentException;

final class LegacyUserRecordMapper
{
    public function __construct(private readonly LegacyUserSourcePolicy $policy) {}

    public function destination(string $table): array
    {
        return match ($this->policy->table($table)['kind']) {
            'identity' => ['legacy_user_profiles', 'source_member_id'],
            'order' => ['legacy_user_orders', 'source_order_id'],
            'order_item' => ['legacy_user_order_items', 'source_item_id'],
            'access' => ['legacy_user_accesses', 'source_access_id'],
            'history', 'reference', 'staff' => ['legacy_user_records', 'source_id'],
            default => throw new InvalidArgumentException('Excluded table cannot be imported.'),
        };
    }

    /** All source payloads pass the finite policy before they reach history. */
    public function attributes(string $table, array $record, array $profileIds, array $orderIds, array $lessonCandidates, array $questionPollIds = []): array
    {
        $this->policy->validateRecord($table, $record);
        $safe = $this->policy->sanitize($table, $record['row']);
        $payload = $safe['payload'];
        $issues = $safe['issues'];
        $definition = $this->policy->table($table);
        if ($table === 'pollAnswer' && isset($payload['arr_values'])) {
            $pollId = $payload['poll_id'] ?? null;
            $values = $payload['arr_values'];
            $valid = is_int($pollId) && (! isset($values['poll_id']) || (string) $values['poll_id'] === (string) $pollId);
            foreach (array_keys($values) as $field) {
                if (preg_match('/^q_([1-9]\d*)(?:_other)?$/D', (string) $field, $match)
                    && ($questionPollIds[(int) $match[1]] ?? null) !== $pollId) {
                    $valid = false;
                }
            }
            if (! $valid) {
                unset($payload['arr_values']);
                $issues[] = ['field' => 'arr_values', 'reason' => 'unknown_or_cross_poll_question'];
            }
        } elseif ($table === 'pollAnswerValue' && ($questionPollIds[$payload['question_id'] ?? 0] ?? null) !== ($payload['poll_id'] ?? null)) {
            $issues[] = ['field' => 'question_id', 'reason' => 'unknown_or_cross_poll_question'];
        }
        $id = $record['source_id'];
        $sourceMember = $record['source_member_id'];
        $state = $record['ownership_status'];
        if ($state === 'linked' && ! isset($profileIds[$sourceMember])) {
            throw new InvalidArgumentException('Linked history has no imported source identity.');
        }
        if (! in_array($state, ['linked', 'identity', 'reference'], true)) {
            $issues[] = ['field' => 'owner', 'reason' => $state];
        }
        $owner = [
            'legacy_user_profile_id' => $state === 'linked' ? $profileIds[$sourceMember] : null,
            'source_member_id' => $sourceMember,
            'ownership_status' => $state,
        ];
        $date = null;
        foreach (['dateCreated', 'created', 'created_at', 'date_created', 'dateSpin'] as $field) {
            if (isset($definition['fields'][$field])) {
                $date = $this->policy->timestamp($table, $field, $payload[$field] ?? null);
                if ($date === null && ! empty($payload[$field])) {
                    $issues[] = ['field' => $field, 'reason' => 'invalid_source_date'];
                }
                break;
            }
        }
        if ($definition['kind'] === 'identity') {
            $name = trim(implode(' ', array_filter([$payload['lastname'] ?? '', $payload['firstname'] ?? '', $payload['patronymic'] ?? ''])));
            $name = $name !== '' ? $name : trim($payload['username'] ?? '');
            $name = $name !== '' ? $name : 'Пользователь старого сайта #'.$id;
            if (mb_strlen($name) > 255) {
                $issues[] = ['field' => 'name', 'reason' => 'source_name_too_long'];
                $name = 'Пользователь старого сайта #'.$id;
            }
            $result = [
                'source_member_id' => $id,
                'display_name' => $name,
                'source_email' => $payload['email'] ?? '',
                'source_phone' => $payload['phone'] ?? null,
                'source_manager_id' => $this->reference($payload['managerId'] ?? null),
                'source_created_at' => $date,
            ];
        } elseif ($definition['kind'] === 'order') {
            if (! array_key_exists((string) $payload['status'], $this->policy->orderStatuses())) {
                $issues[] = ['field' => 'status', 'reason' => 'unknown_source_status'];
            }
            $result = $owner + [
                'source_order_id' => $id,
                'source_status' => $payload['status'],
                'amount_minor' => $this->money($payload, 'amount'),
                'amount_decimal' => $payload['amount'],
                'currency' => 'RUB',
                'currency_basis' => 'legacy_cart_ui_rub',
                'recorded_at' => $date,
            ];
        } elseif ($definition['kind'] === 'order_item') {
            $order = $this->reference($payload['order_id'] ?? null);
            if (! is_int($payload['quantity'] ?? null) || $payload['quantity'] < 0) {
                throw new InvalidArgumentException('Invalid source item quantity.');
            }
            $result = $owner + [
                'source_item_id' => $id,
                'source_order_id' => $order ?? 0,
                'legacy_user_order_id' => $orderIds[$order] ?? null,
                'source_product_id' => $this->reference($payload['product_id'] ?? null),
                'title' => $payload['name'] ?? '',
                'quantity' => $payload['quantity'],
                'unit_amount_minor' => $this->money($payload, 'priceSum'),
                'coupon_unit_amount_minor' => isset($payload['coupon_priceSum']) ? $this->money($payload, 'coupon_priceSum') : null,
                'cost_minor' => $this->money($payload, 'cost'),
            ];
            if ($result['legacy_user_order_id'] === null) {
                $issues[] = ['field' => 'order_id', 'reason' => 'missing_source_order'];
            }
        } elseif ($definition['kind'] === 'access') {
            $page = $this->reference($payload['pageId'] ?? null);
            $candidates = $lessonCandidates[$page] ?? [];
            $mapping = $page === 1195 ? 'blocked_page_1195' : (count($candidates) === 1 ? 'unique_lesson_candidate' : ($candidates ? 'ambiguous_scoped_mapping' : 'unmapped_page'));
            if ($mapping !== 'unique_lesson_candidate') {
                $issues[] = ['field' => 'pageId', 'reason' => $mapping];
            }
            $result = $owner + [
                'source_access_id' => $id,
                'source_page_id' => $page,
                'source_subject_id' => $this->reference($payload['subjectId'] ?? null),
                'candidate_lesson_id' => $mapping === 'unique_lesson_candidate' ? $candidates[0] : null,
                'mapping_status' => $mapping,
                'recorded_at' => $date,
            ];
        } else {
            $sourceOrder = null;
            if (str_starts_with($definition['owner'], 'order:')) {
                $sourceOrder = $this->reference($payload[explode(':', $definition['owner'])[1]] ?? null);
            } elseif ($table === 'lottery_ticket') {
                $sourceOrder = $this->reference($payload['orderId'] ?? null);
            } elseif ($table === 'roulette_prize') {
                $sourceOrder = $this->reference($payload['buyAfterId'] ?? null);
            }
            $actor = null;
            foreach (['adminId', 'userId', 'createdById', 'grantedById'] as $field) {
                if (isset($payload[$field]) && preg_match('/^\d+$/D', (string) $payload[$field])) {
                    $actor = $this->reference((int) $payload[$field]);
                    break;
                }
            }
            $result = $owner + [
                'source_table' => $table,
                'source_id' => $id,
                'source_order_id' => $sourceOrder,
                'source_actor_id' => $actor,
                'legacy_user_order_id' => $orderIds[$sourceOrder] ?? null,
                'category' => $definition['category'],
                'recorded_at' => $date,
            ];
        }
        $result['payload'] = self::json($payload);
        $result['issues'] = self::json($issues);
        // Local surrogate IDs must not change a source revision or make the second import dirty.
        $source = array_diff_key($result, ['legacy_user_profile_id' => true, 'legacy_user_order_id' => true]);
        $result['source_fingerprint'] = hash('sha256', self::json(['mapping_version' => config('legacy_users.mapping_version'), 'source' => $source]));

        return $result;
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function money(array $payload, string $field): int
    {
        if (! isset($payload[$field]) || ! is_string($payload[$field])) {
            throw new InvalidArgumentException('Missing exact source money field.');
        }

        return LegacyUserSourcePolicy::minorUnits($payload[$field]);
    }

    private function reference(mixed $value): ?int
    {
        if ($value === null || $value === 0 || $value === '') {
            return null;
        }
        if (! is_int($value) || $value < 0) {
            throw new InvalidArgumentException('Invalid numeric source reference.');
        }

        return $value;
    }
}

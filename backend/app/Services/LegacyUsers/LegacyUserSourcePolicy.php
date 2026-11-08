<?php

namespace App\Services\LegacyUsers;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;

/** Reviewed, finite source contracts; this class never executes legacy code or fetches URLs. */
final class LegacyUserSourcePolicy
{
    private array $policy;

    public function __construct()
    {
        $this->policy = json_decode(file_get_contents(resource_path('legacy-users/source-policy-v1.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    public function tables(): array
    {
        return $this->policy['tables'];
    }

    public function table(string $name): array
    {
        return $this->policy['tables'][$name] ?? throw new InvalidArgumentException('Unknown legacy source table.');
    }

    public function orderStatuses(): array
    {
        return $this->policy['order_statuses'];
    }

    public function validateRecord(string $table, array $record): void
    {
        $definition = $this->table($table);
        $expected = array_keys(array_filter($definition['fields'], fn (array $field): bool => $field['mode'] !== 'archive'));
        $actual = array_keys($record['row'] ?? []);
        sort($expected);
        sort($actual);
        $envelope = array_keys($record);
        sort($envelope);
        if ($actual !== $expected || $envelope !== ['ownership_status', 'row', 'source_id', 'source_member_id']) {
            throw new InvalidArgumentException('Incomplete or unexpected source record shape.');
        }
        $allowed = match (true) {
            $definition['kind'] === 'archive' => [],
            $definition['kind'] === 'identity' => ['identity'],
            in_array($definition['kind'], ['reference', 'staff'], true) => ['reference'],
            $definition['owner'] === 'unlinked' => ['unlinked'],
            $definition['owner'] === 'unique_fingerprint_owner' => ['linked', 'ambiguous'],
            $definition['owner'] === 'unique_email' => $table === 'coupon' ? ['linked', 'ambiguous', 'unlinked', 'reference'] : ['linked', 'ambiguous', 'unlinked'],
            in_array($definition['owner'], ['task_links', 'order_cart'], true) => ['linked', 'ambiguous', 'unlinked'],
            in_array($definition['owner'], ['task:taskId', 'member_or_order'], true) => ['linked', 'ambiguous', 'unlinked', 'orphan'],
            $definition['owner'] === 'typed_item_member' => ['linked', 'orphan', 'reference'],
            default => ['linked', 'unlinked', 'orphan'],
        };
        if (! in_array($record['ownership_status'], $allowed, true)
            || ($definition['kind'] === 'identity' && $record['source_member_id'] !== $record['source_id'])
            || ($definition['owner'] === 'memberId' && $record['source_member_id'] !== $record['row']['memberId'])) {
            throw new InvalidArgumentException('Source ownership violates the table contract.');
        }
    }

    /** @return array{payload: array, issues: list<array{field: string, reason: string}>} */
    public function sanitize(string $table, array $row): array
    {
        $definition = $this->table($table);
        if ($definition['kind'] === 'archive') {
            throw new InvalidArgumentException('Archive-only table cannot enter the historical API.');
        }
        if (array_diff(array_keys($row), array_keys($definition['fields'])) !== []) {
            throw new InvalidArgumentException('Unknown legacy source field.');
        }
        $payload = [];
        $issues = [];
        foreach ($row as $name => $value) {
            $field = $definition['fields'][$name];
            if ($field['mode'] === 'archive') {
                continue;
            }
            try {
                $payload[$name] = match ($field['mode']) {
                    'structured' => $this->structured($field['rule'], $value),
                    'file_inventory' => $this->fileMetadata($table, $name, $value),
                    'safe_url' => is_null($value) ? null : $this->safeUrl($this->string($value)),
                    'decimal_string' => is_null($value) ? null : $this->decimal($value),
                    'contact' => $this->contact($row['name'] ?? null, $value),
                    'provider_reference' => $this->providerReference($value),
                    default => $this->scalar($value),
                };
            } catch (InvalidArgumentException|JsonException) {
                $issues[] = ['field' => $name, 'reason' => 'source_value_quarantined'];
            }
        }

        return ['payload' => $payload, 'issues' => $issues];
    }

    public static function minorUnits(string $value): int
    {
        if (! preg_match('/^(-?)(\d{1,16})(?:\.(\d{1,2}))?$/D', $value, $parts)) {
            throw new InvalidArgumentException('Invalid exact decimal amount.');
        }
        $minor = ((int) $parts[2]) * 100 + (int) str_pad($parts[3] ?? '', 2, '0');

        return $parts[1] === '-' ? -$minor : $minor;
    }

    public function timestamp(string $table, string $field, mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === 0 || str_starts_with((string) $value, '0000-')) {
            return null;
        }
        $type = $this->table($table)['fields'][$field]['type'] ?? throw new InvalidArgumentException('Unknown date field.');
        if (str_contains($type, 'int')) {
            $epochField = in_array($field, ['created_at', 'updated_at'], true) || ($table === 'fingerprint_action' && $field === 'dateCreated');
            if (! $epochField || ! preg_match('/^\d{1,11}$/D', (string) $value)) {
                return null;
            }

            return (new DateTimeImmutable('@'.$value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
        }
        $zone = new DateTimeZone(str_starts_with($type, 'timestamp') ? 'UTC' : 'Europe/Moscow');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', (string) $value, $zone);
        $errors = DateTimeImmutable::getLastErrors();
        if (! $date || ($errors !== false && ($errors['warning_count'] || $errors['error_count']))) {
            return null;
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
    }

    public function birthDate(mixed $value): ?string
    {
        $timestamp = $this->timestamp('member', 'birth_date', $value);

        return $timestamp === null ? null : (new DateTimeImmutable($timestamp))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d');
    }

    private function decimal(mixed $value): string
    {
        // The extractor must CAST DECIMAL AS CHAR. A decoded float has already lost its source precision.
        if (! is_string($value)) {
            throw new InvalidArgumentException('Decimal must be an exact string.');
        }
        self::minorUnits($value);

        return $value;
    }

    private function string(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > 4 * 1024 * 1024 || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Invalid source text.');
        }

        return $value;
    }

    private function scalar(mixed $value): string|int|bool|null
    {
        if ($value === null || is_int($value) || is_bool($value)) {
            return $value;
        }
        $text = $this->string($value);
        if (in_array(substr(ltrim($text), 0, 1), ['{', '['], true)) {
            try {
                $decoded = json_decode($text, true, 32, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $decoded = null;
            }
            if (is_array($decoded)) {
                throw new InvalidArgumentException('Unexpected structured payload in a scalar field.');
            }
        }

        return $this->text($text);
    }

    public function text(string $text): string
    {
        $text = $this->string($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('~<(script|style)\b[^>]*>.*?</\1\s*>~is', '', $text) ?? '';
        $text = strip_tags($text);

        return preg_replace_callback('~https?://[^\s<>"\x27]+~u', fn (array $match): string => $this->safeUrl($match[0]), $text) ?? '';
    }

    private function safeUrl(string $value): string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($value === '') {
            return '';
        }
        if (! preg_match('~^https?://~i', $value)) {
            return '[ссылка сохранена в закрытом архиве]';
        }
        $parts = parse_url($value);
        if (! $parts || ! isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return '[ссылка сохранена в закрытом архиве]';
        }

        // A secret can occupy an arbitrary path segment, without a recognisable name or format.
        // Full historical locators remain only in the encrypted archive and the private file inventory.
        return strtolower($parts['scheme']).'://'.strtolower($parts['host']);
    }

    private function contact(mixed $name, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $email = strtolower(trim($this->string($value)));
        if ($name !== 'email' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Unsupported historical contact.');
        }

        return $email;
    }

    private function providerReference(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = $this->string($value);
        if (! preg_match('~^[A-Za-z0-9 _.:/-]{1,255}$~D', $value) || str_contains($value, '://')) {
            throw new InvalidArgumentException('Unsupported provider reference.');
        }

        return $value;
    }

    private function decoded(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? json_decode($this->quoteDecimalTokens($this->string($value)), true, 32, JSON_THROW_ON_ERROR) : $value;
    }

    private function quoteDecimalTokens(string $json): string
    {
        $result = '';
        $quoted = false;
        $escaped = false;
        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];
            if ($quoted) {
                $result .= $char;
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $quoted = false;
                }
            } elseif ($char === '"') {
                $quoted = true;
                $result .= $char;
            } elseif (preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $json, $match, 0, $i)) {
                $token = $match[0];
                $result .= strpbrk($token, '.eE') === false ? $token : '"'.$token.'"';
                $i += strlen($token) - 1;
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    private function structured(string $contract, mixed $value): mixed
    {
        $value = $this->decoded($value);
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            throw new InvalidArgumentException('Expected structured source value.');
        }
        if ($contract === 'integer_list') {
            if (! array_is_list($value)) {
                throw new InvalidArgumentException('Expected integer list.');
            }

            return array_map(function (mixed $item): int {
                if ((! is_int($item) && ! is_string($item)) || ! preg_match('/^[1-9]\d{0,9}$/D', (string) $item)) {
                    throw new InvalidArgumentException('Invalid source identifier.');
                }

                return (int) $item;
            }, $value);
        }
        if ($contract === 'poll_choices') {
            return array_map(fn (mixed $item) => $this->scalar($item), $value);
        }
        if ($contract === 'poll_values') {
            foreach ($value as $key => $item) {
                if (! in_array($key, ['poll_id', 'orderId'], true) && ! preg_match('/^q_[1-9]\d*(?:_other)?$/D', (string) $key)) {
                    throw new InvalidArgumentException('Unknown survey value.');
                }
                $value[$key] = is_array($item) && array_is_list($item)
                    ? array_map(fn (mixed $entry) => $this->scalar($entry), $item)
                    : $this->scalar($item);
            }

            return $value;
        }
        if (in_array($contract, ['dolyame_schedule', 'podeli_schedule', 'prize_list'], true)) {
            if (! array_is_list($value)) {
                throw new InvalidArgumentException('Expected structured list.');
            }

            return array_map(fn (mixed $item) => $this->object($contract, $item), $value);
        }
        if ($contract === 'cart_items') {
            foreach ($value as $key => $item) {
                if (! preg_match('/^[0-9]+(?:_[0-9]+)*$/D', (string) $key)) {
                    throw new InvalidArgumentException('Unknown cart item key.');
                }
                $value[$key] = $this->object($contract, $item);
            }

            return $value;
        }

        return $this->object($contract, $value);
    }

    private function object(string $contract, mixed $value): array
    {
        $allowed = $this->policy['payload_contracts'][$contract] ?? throw new InvalidArgumentException('Unknown payload contract.');
        if (! is_array($value) || array_diff(array_keys($value), $allowed) !== []) {
            throw new InvalidArgumentException('Unknown nested source field.');
        }
        foreach ($value as $key => $item) {
            if (in_array($key, ['includeProductIds', 'excludeProductIds'], true)) {
                $value[$key] = $this->structured('integer_list', $item);
            } elseif (in_array($key, ['image', 'link'], true)) {
                unset($value[$key]); // Resolved file IDs belong to the independently verified file inventory.
            } elseif (in_array($key, ['amount', 'paymentAmount', 'priceSum', 'coupon_priceSum', 'minOrderSum', 'maxOrderSum', 'maxDiscountAmount', 'discount_size', 'orderSumBefore', 'orderSumAfter'], true)) {
                $value[$key] = $item === null ? null : $this->decimal(is_int($item) ? (string) $item : $item);
            } else {
                $value[$key] = $this->scalar($item);
            }
        }

        return $value;
    }

    private function fileMetadata(string $table, string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($table === 'MemberHistory' && $field === 'files') {
            $value = $this->decoded($value);
            if (! is_array($value) || ! array_is_list($value)) {
                throw new InvalidArgumentException('Expected history file list.');
            }

            return array_map(fn (mixed $item) => $this->object('history_file', $item), $value);
        }

        return null;
    }
}

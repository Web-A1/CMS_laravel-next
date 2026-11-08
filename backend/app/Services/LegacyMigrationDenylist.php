<?php

namespace App\Services;

use InvalidArgumentException;

class LegacyMigrationDenylist
{
    private const BLOCKED_FIELD_FRAGMENTS = [
        'access_token',
        'api_key',
        'card',
        'cookie',
        'cvv',
        'file_hash',
        'hash',
        'password',
        'passwd',
        'refresh_token',
        'remember_token',
        'salt',
        'secret',
        'session',
        'signature',
        'token',
    ];

    private const BLOCKED_ROUTE_FRAGMENTS = [
        '/ajax/',
        '/callback',
        '/change-password',
        '/delete',
        '/destroy',
        '/payment/',
        '/refund',
        '/remove',
        '/revoke',
        '/set-status',
        '/webhook',
    ];

    private const BLOCKED_TABLE_FRAGMENTS = [
        'api_key',
        'auth_token',
        'card',
        'cookie',
        'password',
        'payment_token',
        'personal_access_token',
        'secret',
        'session',
        'token',
    ];

    /**
     * @param  array<int, string>  $fields
     */
    public function assertAllowedSource(string $sourceTable, array $fields = []): void
    {
        $reason = $this->blockedSourceReason($sourceTable, $fields);

        if ($reason !== null) {
            throw new InvalidArgumentException("Legacy source is denied: {$reason}");
        }
    }

    /**
     * @param  array<int, string>  $fields
     */
    public function isAllowedSource(string $sourceTable, array $fields = []): bool
    {
        return $this->blockedSourceReason($sourceTable, $fields) === null;
    }

    /**
     * @param  array<int, string>  $fields
     */
    public function blockedSourceReason(string $sourceTable, array $fields = []): ?string
    {
        $table = $this->normalizeName($sourceTable);

        foreach (self::BLOCKED_TABLE_FRAGMENTS as $fragment) {
            if (str_contains($table, $fragment)) {
                return "table [{$sourceTable}] matches blocked fragment [{$fragment}]";
            }
        }

        foreach ($fields as $field) {
            $fieldName = $this->normalizeName($field);

            foreach (self::BLOCKED_FIELD_FRAGMENTS as $fragment) {
                if (str_contains($fieldName, $fragment)) {
                    return "field [{$field}] matches blocked fragment [{$fragment}]";
                }
            }
        }

        return null;
    }

    public function assertAllowedRouteMapping(string $legacyPath): void
    {
        $reason = $this->blockedRouteReason($legacyPath);

        if ($reason !== null) {
            throw new InvalidArgumentException("Legacy route is denied: {$reason}");
        }
    }

    public function isAllowedRouteMapping(string $legacyPath): bool
    {
        return $this->blockedRouteReason($legacyPath) === null;
    }

    public function blockedRouteReason(string $legacyPath): ?string
    {
        $path = $this->normalizePath($legacyPath);

        foreach (self::BLOCKED_ROUTE_FRAGMENTS as $fragment) {
            if (str_contains($path, $fragment)) {
                return "path [{$legacyPath}] matches blocked fragment [{$fragment}]";
            }
        }

        return null;
    }

    private function normalizeName(string $value): string
    {
        return strtolower(trim(str_replace(['-', '.'], '_', $value)));
    }

    private function normalizePath(string $legacyPath): string
    {
        $path = parse_url($legacyPath, PHP_URL_PATH) ?: $legacyPath;

        return '/'.ltrim(strtolower(trim($path)), '/');
    }
}

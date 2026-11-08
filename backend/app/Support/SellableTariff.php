<?php

namespace App\Support;

use DomainException;

final class SellableTariff
{
    /** @return array{code:string,label:string,supportDescription:string}|null */
    public static function fromMetadata(?array $metadata): ?array
    {
        return self::validate($metadata['tariff'] ?? null, false);
    }

    /** Historical purchases never fall back to the current catalog. */
    public static function fromSnapshot(?array $catalogSnapshot): ?array
    {
        try {
            return self::validate($catalogSnapshot['tariff'] ?? null, true);
        } catch (DomainException) {
            return null;
        }
    }

    public static function snapshot(?array $metadata): ?array
    {
        $tariff = self::fromMetadata($metadata);

        return $tariff === null ? null : ['version' => 1, ...$tariff];
    }

    private static function validate(mixed $value, bool $snapshot): ?array
    {
        if ($value === null) {
            return null;
        }
        $expected = $snapshot ? ['version', 'code', 'label', 'supportDescription'] : ['code', 'label', 'supportDescription'];
        if (! is_array($value) || count($value) !== count($expected) || array_diff($expected, array_keys($value)) !== []) {
            throw new DomainException('SELLABLE_TARIFF_INVALID');
        }
        if (($snapshot && $value['version'] !== 1) || ! in_array($value['code'], ['ordinary', 'extended'], true)) {
            throw new DomainException('SELLABLE_TARIFF_INVALID');
        }
        foreach (['label' => 80, 'supportDescription' => 500] as $field => $limit) {
            $text = $value[$field];
            if (! is_string($text) || $text === '' || $text !== trim($text) || ! mb_check_encoding($text, 'UTF-8') || mb_strlen($text) > $limit || preg_match('/[\p{Cc}<>]/u', $text)) {
                throw new DomainException('SELLABLE_TARIFF_INVALID');
            }
        }

        return ['code' => $value['code'], 'label' => $value['label'], 'supportDescription' => $value['supportDescription']];
    }
}

<?php

namespace Tests\Unit;

use App\Support\SellableTariff;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SellableTariffTest extends TestCase
{
    public function test_projection_is_exact_nullable_and_versioned_only_for_snapshot(): void
    {
        $tariff = ['code' => 'ordinary', 'label' => 'Обычный', 'supportDescription' => 'Обычный объём личной работы преподавателя.'];
        $this->assertNull(SellableTariff::fromMetadata(null));
        $this->assertNull(SellableTariff::fromMetadata(['secret' => 'never projected']));
        $this->assertSame($tariff, SellableTariff::fromMetadata(['tariff' => $tariff]));
        $this->assertSame(['version' => 1, ...$tariff], SellableTariff::snapshot(['tariff' => $tariff]));
        $this->assertSame($tariff, SellableTariff::fromSnapshot(['tariff' => ['version' => 1, ...$tariff]]));
        $this->assertNull(SellableTariff::fromSnapshot(['sellable' => ['title' => 'Расширенный']]));
        $this->assertNull(SellableTariff::fromSnapshot(['tariff' => ['version' => 2, ...$tariff]]));
    }

    #[DataProvider('invalidTariffs')]
    public function test_malformed_terms_are_rejected(mixed $tariff): void
    {
        $this->expectException(DomainException::class);
        SellableTariff::fromMetadata(['tariff' => $tariff]);
    }

    public static function invalidTariffs(): array
    {
        $valid = ['code' => 'ordinary', 'label' => 'Обычный', 'supportDescription' => 'Поддержка'];

        return ['wrong type' => ['ordinary'], 'missing' => [['code' => 'ordinary']], 'unknown code' => [[...$valid, 'code' => 'premium']], 'extra keys' => [[...$valid, 'source' => 'private']], 'html' => [[...$valid, 'label' => '<b>Обычный</b>']], 'empty' => [[...$valid, 'label' => '']], 'untrimmed' => [[...$valid, 'label' => ' Обычный']], 'control' => [[...$valid, 'supportDescription' => "bad\nterms"]], 'label bound' => [[...$valid, 'label' => str_repeat('я', 81)]], 'description bound' => [[...$valid, 'supportDescription' => str_repeat('я', 501)]], 'wrong field type' => [[...$valid, 'label' => 12]]];
    }
}

<?php

namespace Tests\Unit;

use App\Services\LegacyUsers\LegacyUserSourcePolicy;
use InvalidArgumentException;
use Tests\TestCase;

class LegacyUserSourcePolicyTest extends TestCase
{
    public function test_secret_columns_and_unrecognised_nested_fields_cannot_reach_history(): void
    {
        $policy = new LegacyUserSourcePolicy;
        $member = $policy->sanitize('member', ['id' => 13, 'username' => 'Sample', 'auth_key' => 'do-not-copy', 'password_hash' => 'do-not-copy']);
        $this->assertSame(['id' => 13, 'username' => 'Sample'], $member['payload']);
        $schedule = $policy->sanitize('order_dolyame', ['id' => 4, 'client_info' => '{"email":"fixture@example.test","token":"secret"}']);
        $this->assertSame(['id' => 4], $schedule['payload']);
        $this->assertSame([['field' => 'client_info', 'reason' => 'source_value_quarantined']], $schedule['issues']);
        $opaque = $policy->sanitize('member_data', ['name' => 'profession', 'value' => '{"password":"secret"}']);
        $this->assertArrayNotHasKey('value', $opaque['payload']);
    }

    public function test_unknown_tables_and_fields_fail_instead_of_becoming_generic_payloads(): void
    {
        $policy = new LegacyUserSourcePolicy;
        foreach ([['new_table', []], ['member', ['unreviewed_column' => 'value']], ['action_confirm', ['id' => 1]], ['chat', ['id' => 1]], ['chat_msg', ['id' => 1]]] as [$table, $row]) {
            try {
                $policy->sanitize($table, $row);
                $this->fail('Unreviewed input was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_financial_decimals_keep_exact_cents_and_negative_source_amounts(): void
    {
        $this->assertSame(29, LegacyUserSourcePolicy::minorUnits('0.29'));
        $this->assertSame(-12345678901, LegacyUserSourcePolicy::minorUnits('-123456789.01'));
        $this->assertSame(100, LegacyUserSourcePolicy::minorUnits('1'));
        $policy = new LegacyUserSourcePolicy;
        $row = $policy->sanitize('order_dolyame', ['payment_schedule' => '[{"amount":0.29,"payment_date":"2025-01-01","status":"paid"}]']);
        $this->assertSame('0.29', $row['payload']['payment_schedule'][0]['amount']);
        $this->assertSame([], $row['issues']);
        $bad = $policy->sanitize('order', ['amount' => 0.29]);
        $this->assertArrayNotHasKey('amount', $bad['payload']);
        $this->expectException(InvalidArgumentException::class);
        LegacyUserSourcePolicy::minorUnits('0.001');
    }

    public function test_moscow_calendar_birth_date_and_source_instants_remain_distinct(): void
    {
        $policy = new LegacyUserSourcePolicy;
        $this->assertSame('1996-05-02', $policy->birthDate('1996-05-01 20:00:00'));
        $this->assertSame('2024-05-02T09:00:00+00:00', $policy->timestamp('order', 'created', '2024-05-02 12:00:00'));
        $this->assertSame('2024-05-02T12:00:00+00:00', $policy->timestamp('order', 'updated', '2024-05-02 12:00:00'));
        $this->assertNull($policy->birthDate('0000-00-00 00:00:00'));
        $this->assertNull($policy->timestamp('order', 'created', '2024-02-30 12:00:00'));
    }

    public function test_admin_note_attachment_metadata_never_exposes_source_links(): void
    {
        $policy = new LegacyUserSourcePolicy;
        $row = $policy->sanitize('MemberHistory', ['files' => json_encode([
            ['id' => 4, 'description' => 'A sample document', 'image' => '/private/document.pdf'],
        ], JSON_THROW_ON_ERROR)]);
        $this->assertSame('A sample document', $row['payload']['files'][0]['description']);
        $this->assertArrayNotHasKey('image', $row['payload']['files'][0]);
        $text = $policy->text('<script>bad()</script><b>Document</b> https://example.test/a/opaque-credential?secret=value');
        $this->assertSame('Document https://example.test', $text);
    }

    public function test_encoded_markup_and_non_http_locators_cannot_become_safe_urls(): void
    {
        $policy = new LegacyUserSourcePolicy;
        $this->assertSame('Visible', $policy->text('&lt;script&gt;bad()&lt;/script&gt;&lt;b onclick="bad()"&gt;Visible&lt;/b&gt;'));
        foreach (['javascript:alert(1)', 'java&#x73;cript:alert(1)', 'data:text/html,unsafe', '/private/file.pdf', '//example.test/private'] as $url) {
            $row = $policy->sanitize('page', ['url' => $url]);
            $this->assertSame('[ссылка сохранена в закрытом архиве]', $row['payload']['url']);
        }
    }

    public function test_nested_financial_values_require_exact_decimal_numbers(): void
    {
        $policy = new LegacyUserSourcePolicy;
        foreach (['0.001', '1e3', 'unknown', 0.29] as $amount) {
            $row = $policy->sanitize('order_dolyame', ['payment_schedule' => [['amount' => $amount, 'status' => 'paid']]]);
            $this->assertArrayNotHasKey('payment_schedule', $row['payload']);
            $this->assertSame([['field' => 'payment_schedule', 'reason' => 'source_value_quarantined']], $row['issues']);
        }
        $coupon = $policy->sanitize('order_coupon', ['data' => '{"amount":10,"minOrderSum":"0.29"}']);
        $this->assertSame(['amount' => '10', 'minOrderSum' => '0.29'], $coupon['payload']['data']);
    }
}

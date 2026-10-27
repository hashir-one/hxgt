<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KotakSmsPresetTest extends TestCase
{
    public static function credits(): array
    {
        $samples = json_decode(file_get_contents(__DIR__.'/../Fixtures/KotakSms/credits.json'), true, flags: JSON_THROW_ON_ERROR);

        return array_column(array_map(fn ($sample) => ['name' => $sample['name'], 'data' => [$sample]], $samples), 'data', 'name');
    }

    #[DataProvider('credits')]
    public function test_sample_templates_extract_exact_paise_and_reference(array $sample): void
    {
        $this->assertSame(['amount_minor' => $sample['amount_minor'], 'reference' => $sample['reference']], $this->extract($sample['body']));
    }

    public static function invalidMessages(): array
    {
        $valid = 'Received Rs.12.50 in your Kotak Bank AC X1234 from SAMPLE PERSON on 24-10-26.UPI Ref:012345678901';

        return [
            'debit' => [str_replace('Received', 'Debited', $valid)],
            'failed_credit' => [$valid.' Transaction failed.'],
            'refund' => [str_replace('SAMPLE PERSON', 'REFUNDED', $valid)],
            'otp' => ['Your OTP is 123456. '.$valid],
            'collect_request' => ['UPI collect request: '.$valid],
            'two_messages' => [$valid."\n".$valid],
            'extra_reference' => [$valid.'. UPI Ref:123456789012'],
            'short_reference' => [str_replace('012345678901', '12345678901', $valid)],
            'long_reference' => [str_replace('012345678901', '0123456789012', $valid)],
            'unicode_reference' => [str_replace('012345678901', '०१२३४५६७८९०१', $valid)],
            'three_decimals' => [str_replace('12.50', '12.500', $valid)],
            'negative_amount' => [str_replace('12.50', '-12.50', $valid)],
            'zero_amount' => [str_replace('12.50', '0.00', $valid)],
            'exponent_amount' => [str_replace('12.50', '1e3', $valid)],
            'wrong_bank' => [str_replace('Kotak Bank', 'Other Bank', $valid)],
            'unexpected_suffix' => [$valid.' click an unrelated link'],
        ];
    }

    #[DataProvider('invalidMessages')]
    public function test_non_credit_or_ambiguous_messages_are_rejected(string $body): void
    {
        $this->assertNull($this->extract($body));
    }

    /** Exercises the documented client extraction contract; no raw-SMS server endpoint. */
    private function extract(string $body): ?array
    {
        $rules = (require __DIR__.'/../../config/bank_parsers.php')['kotak_sms'];
        $match = fn ($key) => preg_match('~'.$rules[$key].'~', $body);
        if (strlen($body) > 2048 || $match('reject_pattern') !== 0 || $match('credit_pattern') !== 1) {
            return null;
        }
        if (preg_match_all('~'.$rules['amount_pattern'].'~', $body, $amounts) !== 1
            || preg_match_all('~'.$rules['reference_pattern'].'~', $body, $references) !== 1) {
            return null;
        }
        [$rupees, $paise] = explode('.', $amounts[1][0]);
        $minor = (int) $rupees * 100 + (int) $paise;

        return $minor > 0 ? ['amount_minor' => $minor, 'reference' => $references[1][0]] : null;
    }
}

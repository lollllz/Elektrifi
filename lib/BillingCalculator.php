<?php
declare(strict_types=1);

final class BillingCalculator
{
    public const MAX_TIERS = 10;

    /** @return array<string, mixed> */
    public static function defaultProfile(): array
    {
        return [
            'currency_code' => 'MYR',
            'currency_symbol' => 'RM',
            'minimum_charge' => 300.0,
            'sst_rate' => 6.0,
            'sst_threshold_kwh' => 600.0,
            'tariff_tiers' => [
                ['label' => 'First 200 kWh', 'limit_kwh' => 200.0, 'rate' => 0.218],
                ['label' => 'Next 100 kWh', 'limit_kwh' => 100.0, 'rate' => 0.344],
                ['label' => 'Next 300 kWh', 'limit_kwh' => 300.0, 'rate' => 0.516],
                ['label' => 'Next 300 kWh', 'limit_kwh' => 300.0, 'rate' => 0.546],
                ['label' => 'Additional usage', 'limit_kwh' => null, 'rate' => 0.571],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array{profile: array<string, mixed>, errors: array<string, string>}
     */
    public static function validateProfile(array $source): array
    {
        $errors = [];
        $currencyCode = strtoupper(trim((string) ($source['currency_code'] ?? '')));
        $currencySymbol = trim((string) ($source['currency_symbol'] ?? ''));

        if (!preg_match('/^[A-Z]{3}$/', $currencyCode)) {
            $errors['currency_code'] = 'Use a 3-letter currency code, for example MYR.';
        }
        if ($currencySymbol === '' || mb_strlen($currencySymbol) > 8) {
            $errors['currency_symbol'] = 'Enter a currency symbol or short prefix (maximum 8 characters).';
        }

        $minimumCharge = self::decimal($source['minimum_charge'] ?? null, 'minimum_charge', $errors, 0, 1000000);
        $sstRate = self::decimal($source['sst_rate'] ?? null, 'sst_rate', $errors, 0, 100);
        $sstThreshold = self::decimal($source['sst_threshold_kwh'] ?? null, 'sst_threshold_kwh', $errors, 0, 1000000);

        $labels = is_array($source['tier_label'] ?? null) ? array_values($source['tier_label']) : [];
        $limits = is_array($source['tier_limit'] ?? null) ? array_values($source['tier_limit']) : [];
        $rates = is_array($source['tier_rate'] ?? null) ? array_values($source['tier_rate']) : [];
        $tierCount = max(count($labels), count($limits), count($rates));

        if ($tierCount < 1 || $tierCount > self::MAX_TIERS) {
            $errors['tiers'] = 'Provide between 1 and ' . self::MAX_TIERS . ' tariff tiers.';
        }

        $tiers = [];
        for ($index = 0; $index < min($tierCount, self::MAX_TIERS); $index++) {
            $label = trim((string) ($labels[$index] ?? ''));
            $rawLimit = trim((string) ($limits[$index] ?? ''));
            $rawRate = $rates[$index] ?? null;

            if ($label === '' || mb_strlen($label) > 60) {
                $errors['tier_label_' . $index] = 'Each tier needs a label of 60 characters or fewer.';
            }

            $limit = null;
            if ($rawLimit !== '') {
                $limit = self::decimal($rawLimit, 'tier_limit_' . $index, $errors, 0.01, 1000000);
            } elseif ($index !== $tierCount - 1) {
                $errors['tier_limit_' . $index] = 'Only the final tier may have no limit.';
            }

            $rate = self::decimal($rawRate, 'tier_rate_' . $index, $errors, 0, 1000000);
            $tiers[] = ['label' => $label, 'limit_kwh' => $limit, 'rate' => $rate];
        }

        return [
            'profile' => [
                'currency_code' => $currencyCode,
                'currency_symbol' => $currencySymbol,
                'minimum_charge' => $minimumCharge,
                'sst_rate' => $sstRate,
                'sst_threshold_kwh' => $sstThreshold,
                'tariff_tiers' => $tiers,
            ],
            'errors' => $errors,
        ];
    }

    /**
     * @param array<int, mixed> $source
     * @param array<int, array<string, mixed>> $tiers
     * @return array{usage: array<int, float>, errors: array<string, string>}
     */
    public static function validateUsage(array $source, array $tiers): array
    {
        $usage = [];
        $errors = [];

        foreach ($tiers as $index => $tier) {
            $raw = trim((string) ($source[$index] ?? ''));
            if ($raw === '') {
                $usage[$index] = 0.0;
                continue;
            }
            if (!preg_match('/^\d+(?:\.\d{1,3})?$/', $raw)) {
                $usage[$index] = 0.0;
                $errors['usage_' . $index] = 'Enter a non-negative number with up to 3 decimal places.';
                continue;
            }

            $usage[$index] = (float) $raw;
            $limit = $tier['limit_kwh'];
            if ($limit !== null && $usage[$index] > (float) $limit) {
                $errors['usage_' . $index] = 'This block accepts at most ' . self::formatNumber((float) $limit) . ' kWh.';
            }
        }

        for ($index = 1, $count = count($tiers); $index < $count; $index++) {
            $previousLimit = $tiers[$index - 1]['limit_kwh'];
            if (($usage[$index] ?? 0) > 0 && $previousLimit !== null && ($usage[$index - 1] ?? 0) < (float) $previousLimit) {
                $errors['usage_' . $index] = 'Complete the previous ' . self::formatNumber((float) $previousLimit) . ' kWh block first.';
            }
        }

        return ['usage' => $usage, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $profile
     * @param array<int, float> $usage
     * @return array<string, mixed>
     */
    public static function calculate(array $profile, array $usage): array
    {
        $lines = [];
        $totalKwh = 0.0;
        $tariffSubtotal = 0.0;

        foreach ($profile['tariff_tiers'] as $index => $tier) {
            $tierUsage = $usage[$index] ?? 0.0;
            $amount = $tierUsage * (float) $tier['rate'];
            $lines[] = [
                'label' => $tier['label'],
                'range' => self::tierRange($profile['tariff_tiers'], $index),
                'usage' => $tierUsage,
                'rate' => (float) $tier['rate'],
                'amount' => $amount,
            ];
            $totalKwh += $tierUsage;
            $tariffSubtotal += $amount;
        }

        $beforeSst = max($tariffSubtotal, (float) $profile['minimum_charge']);
        $sstApplies = $totalKwh > (float) $profile['sst_threshold_kwh'];
        $sstAmount = $sstApplies ? $beforeSst * ((float) $profile['sst_rate'] / 100) : 0.0;

        return [
            'lines' => $lines,
            'total_kwh' => $totalKwh,
            'tariff_subtotal' => $tariffSubtotal,
            'minimum_adjustment' => $beforeSst - $tariffSubtotal,
            'before_sst' => $beforeSst,
            'sst_applies' => $sstApplies,
            'sst_amount' => $sstAmount,
            'final_total' => $beforeSst + $sstAmount,
        ];
    }

    /** @param array<int, array<string, mixed>> $tiers */
    public static function tierRange(array $tiers, int $targetIndex): string
    {
        $start = 1.0;
        foreach ($tiers as $index => $tier) {
            $limit = $tier['limit_kwh'];
            if ($index === $targetIndex) {
                if ($limit === null) {
                    return self::formatNumber($start) . ' kWh onwards';
                }
                $end = $start + (float) $limit - 1;
                return self::formatNumber($start) . '–' . self::formatNumber($end) . ' kWh';
            }
            if ($limit !== null) {
                $start += (float) $limit;
            }
        }
        return '';
    }

    public static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ','), '0'), '.');
    }

    /** @param array<string, string> $errors */
    private static function decimal(mixed $raw, string $key, array &$errors, float $minimum, float $maximum): float
    {
        $value = trim((string) $raw);
        if (!preg_match('/^\d+(?:\.\d{1,6})?$/', $value)) {
            $errors[$key] = 'Enter a valid number.';
            return 0.0;
        }
        $number = (float) $value;
        if ($number < $minimum || $number > $maximum) {
            $errors[$key] = 'Enter a value between ' . self::formatNumber($minimum) . ' and ' . self::formatNumber($maximum) . '.';
        }
        return $number;
    }
}

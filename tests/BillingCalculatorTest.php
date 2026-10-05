<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/BillingCalculator.php';

function expectNear(float $actual, float $expected, string $message): void
{
    if (abs($actual - $expected) > 0.00001) {
        throw new RuntimeException(sprintf('%s: expected %.5f, received %.5f', $message, $expected, $actual));
    }
}

$profile = BillingCalculator::defaultProfile();

$example = BillingCalculator::calculate($profile, [200, 100, 300, 180, 0]);
expectNear($example['total_kwh'], 780.0, 'Example consumption');
expectNear($example['tariff_subtotal'], 331.08, 'Example tariff subtotal');
expectNear($example['sst_amount'], 19.8648, 'Example SST');
expectNear($example['final_total'], 350.9448, 'Example final bill');

$minimum = BillingCalculator::calculate($profile, [200, 0, 0, 0, 0]);
expectNear($minimum['tariff_subtotal'], 43.60, 'First block subtotal');
expectNear($minimum['minimum_adjustment'], 256.40, 'Minimum charge adjustment');
expectNear($minimum['final_total'], 300.0, 'Minimum final bill');

$threshold = BillingCalculator::calculate($profile, [200, 100, 300, 0, 0]);
expectNear($threshold['total_kwh'], 600.0, 'SST threshold consumption');
expectNear($threshold['sst_amount'], 0.0, 'SST must not apply at exactly 600 kWh');

$invalidSequence = BillingCalculator::validateUsage(['100', '20', '0', '0', '0'], $profile['tariff_tiers']);
if (!isset($invalidSequence['errors']['usage_1'])) {
    throw new RuntimeException('Later usage must require the previous block to be complete.');
}

$customProfile = $profile;
$customProfile['currency_code'] = 'USD';
$customProfile['currency_symbol'] = '$';
$customProfile['minimum_charge'] = 0;
$customProfile['sst_rate'] = 0;
$customProfile['tariff_tiers'] = [['label' => 'Flat rate', 'limit_kwh' => null, 'rate' => 0.25]];
$custom = BillingCalculator::calculate($customProfile, [100]);
expectNear($custom['final_total'], 25.0, 'Custom flat-rate profile');

echo "BillingCalculator: 5 scenarios passed\n";

<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/BillingCalculator.php';
require_once __DIR__ . '/lib/SupabaseStore.php';

function e(string|int|float $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function uuidV4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

function setPrivateCookie(string $name, string $value, int $expires): void
{
    setcookie($name, $value, [
        'expires' => $expires,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function postProfileSource(array $profile): array
{
    $source = [
        'currency_code' => $_POST['currency_code'] ?? $profile['currency_code'],
        'currency_symbol' => $_POST['currency_symbol'] ?? $profile['currency_symbol'],
        'minimum_charge' => $_POST['minimum_charge'] ?? $profile['minimum_charge'],
        'sst_rate' => $_POST['sst_rate'] ?? $profile['sst_rate'],
        'sst_threshold_kwh' => $_POST['sst_threshold_kwh'] ?? $profile['sst_threshold_kwh'],
    ];
    $source['tier_label'] = $_POST['tier_label'] ?? array_column($profile['tariff_tiers'], 'label');
    $source['tier_limit'] = $_POST['tier_limit'] ?? array_map(
        static fn (array $tier): string|float => $tier['limit_kwh'] ?? '',
        $profile['tariff_tiers']
    );
    $source['tier_rate'] = $_POST['tier_rate'] ?? array_column($profile['tariff_tiers'], 'rate');
    return $source;
}

$clientId = (string) ($_COOKIE['elektrifi_client_id'] ?? '');
if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $clientId)) {
    $clientId = uuidV4();
    setPrivateCookie('elektrifi_client_id', $clientId, time() + 31536000);
}

$csrfToken = (string) ($_COOKIE['elektrifi_csrf'] ?? '');
if (!preg_match('/^[0-9a-f]{64}$/', $csrfToken)) {
    $csrfToken = bin2hex(random_bytes(32));
    setPrivateCookie('elektrifi_csrf', $csrfToken, time() + 86400);
}

$store = new SupabaseStore(getenv('SUPABASE_URL') ?: null, getenv('SUPABASE_SECRET_KEY') ?: null);
$profile = BillingCalculator::defaultProfile();
$profileRow = null;
$history = [];
$databaseError = null;
$notice = null;
$profileErrors = [];
$usageErrors = [];
$usage = array_fill(0, count($profile['tariff_tiers']), 0.0);
$result = null;

if ($store->isConfigured()) {
    try {
        $profileRow = $store->getProfile($clientId);
        if ($profileRow !== null) {
            $profile = SupabaseStore::profileFromRow($profileRow);
            $history = $store->getHistory((string) $profileRow['id']);
        }
    } catch (RuntimeException $exception) {
        $databaseError = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals($csrfToken, $submittedCsrf)) {
        http_response_code(403);
        $profileErrors['request'] = 'This form expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? 'calculate');

        if ($action === 'reset_profile') {
            $profile = BillingCalculator::defaultProfile();
            try {
                if ($store->isConfigured()) {
                    $profileRow = $store->saveProfile($clientId, $profile);
                    $notice = 'The published Malaysian tariff has been restored and saved.';
                } else {
                    $notice = 'The published Malaysian tariff has been restored for this page.';
                }
            } catch (RuntimeException $exception) {
                $databaseError = $exception->getMessage();
            }
        } else {
            $profileValidation = BillingCalculator::validateProfile(postProfileSource($profile));
            $profile = $profileValidation['profile'];
            $profileErrors = $profileValidation['errors'];
            $usage = array_map('floatval', is_array($_POST['usage'] ?? null) ? $_POST['usage'] : []);

            if ($profileErrors === []) {
                try {
                    if ($store->isConfigured()) {
                        $profileRow = $store->saveProfile($clientId, $profile);
                    }
                } catch (RuntimeException $exception) {
                    $databaseError = $exception->getMessage();
                }

                if ($action === 'save_profile') {
                    $notice = $store->isConfigured()
                        ? 'Your custom tariff profile has been saved to Supabase.'
                        : 'Your profile is valid. Connect Supabase to persist it online.';
                } else {
                    $usageValidation = BillingCalculator::validateUsage(
                        is_array($_POST['usage'] ?? null) ? $_POST['usage'] : [],
                        $profile['tariff_tiers']
                    );
                    $usage = $usageValidation['usage'];
                    $usageErrors = $usageValidation['errors'];

                    if ($usageErrors === []) {
                        $result = BillingCalculator::calculate($profile, $usage);
                        if ($store->isConfigured() && is_array($profileRow) && isset($profileRow['id'])) {
                            try {
                                $store->saveCalculation((string) $profileRow['id'], $profile, $usage, $result);
                                $history = $store->getHistory((string) $profileRow['id']);
                            } catch (RuntimeException $exception) {
                                $databaseError = $exception->getMessage();
                            }
                        }
                    }
                }
            }
        }
    }
}

$currency = $profile['currency_symbol'];
$hasErrors = $profileErrors !== [] || $usageErrors !== [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Interactive domestic electricity tariff calculator with custom rates and Supabase history.">
    <title>Elektrifi — Electricity Bill Calculator</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="<?= $result !== null ? 'has-result' : '' ?>">
    <div class="ambient ambient-one" aria-hidden="true"></div>
    <div class="ambient ambient-two" aria-hidden="true"></div>

    <header class="site-header">
        <a class="brand" href="./" aria-label="Elektrifi home">
            <span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M13.2 2 5 13.2h6.1L10.7 22 19 10.7h-6.1L13.2 2Z"/></svg></span>
            <span>Elektrifi</span>
        </a>
        <div class="header-meta">
            <span>CRM 1963</span><i></i><span>Domestic tariff</span>
        </div>
    </header>

    <main>
        <section class="hero" aria-labelledby="page-title">
            <div class="hero-copy">
                <div class="eyebrow-pill"><span></span> Smart household billing</div>
                <h1 id="page-title">Know what every<br><em>kilowatt costs.</em></h1>
                <p>Use the published Malaysian tariff or shape the calculator around your own currency and energy rates. Every valid bill can be saved to your private Supabase history.</p>
            </div>

            <aside class="rate-card" aria-label="Current billing rules">
                <div class="rate-card-top"><span>Active profile</span><span><?= e($profile['currency_code']) ?></span></div>
                <div class="rate-stat"><strong><?= e(BillingCalculator::formatNumber((float) $profile['sst_rate'])) ?>%</strong><span>SST above<br><?= e(BillingCalculator::formatNumber((float) $profile['sst_threshold_kwh'])) ?> kWh</span></div>
                <div class="rate-card-bottom"><span>Minimum monthly charge</span><strong><?= e($currency) ?> <?= number_format((float) $profile['minimum_charge'], 2) ?></strong></div>
            </aside>
        </section>

        <?php if (!$store->isConfigured() || $databaseError !== null): ?>
            <div class="system-banner <?= $databaseError !== null ? 'error' : '' ?>" role="status">
                <span class="status-dot"></span>
                <div>
                    <strong><?= $databaseError !== null ? 'Supabase needs attention' : 'Supabase setup required' ?></strong>
                    <p><?= $databaseError !== null
                        ? e($databaseError)
                        : 'The calculator works locally. Add SUPABASE_URL and SUPABASE_SECRET_KEY after running the included migration to save profiles and bills.' ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="system-banner connected" role="status"><span class="status-dot"></span><div><strong>Supabase connected</strong><p>Your tariff settings and recent calculations are stored online.</p></div></div>
        <?php endif; ?>

        <?php if ($notice !== null): ?>
            <div class="notice" role="status"><?= e($notice) ?></div>
        <?php endif; ?>

        <form method="post" action="#calculator" id="tariff-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">

            <section class="calculator-shell" id="calculator" aria-labelledby="calculator-title">
                <div class="calculator-heading">
                    <div><p class="section-kicker">Monthly consumption</p><h2 id="calculator-title">Build your usage profile</h2></div>
                    <button class="ghost-button" type="button" id="load-example">
                        <svg viewBox="0 0 24 24"><path d="M12 3v3m0 12v3M3 12h3m12 0h3M5.6 5.6l2.2 2.2m8.4 8.4 2.2 2.2m0-12.8-2.2 2.2M7.8 16.2l-2.2 2.2"/></svg>
                        Load 780 kWh example
                    </button>
                </div>

                <?php if ($hasErrors): ?>
                    <div class="alert" role="alert" id="validation-summary" tabindex="-1"><strong>Please correct the highlighted entries and calculate again.</strong></div>
                <?php endif; ?>

                <div class="tier-grid" id="usage-fields">
                    <?php foreach ($profile['tariff_tiers'] as $index => $tier): ?>
                        <?php $error = $usageErrors['usage_' . $index] ?? null; ?>
                        <div class="tier-field <?= $error ? 'field-error' : '' ?>" data-usage-row>
                            <span class="tier-number"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <div class="tier-copy">
                                <label for="usage_<?= $index ?>"><?= e((string) $tier['label']) ?></label>
                                <span><?= e(BillingCalculator::tierRange($profile['tariff_tiers'], $index)) ?> · <?= e($currency) ?> <?= number_format((float) $tier['rate'], 3) ?>/kWh</span>
                            </div>
                            <div class="input-wrap">
                                <input type="text" inputmode="decimal" id="usage_<?= $index ?>" name="usage[]" value="<?= ($usage[$index] ?? 0) > 0 ? e(BillingCalculator::formatNumber((float) $usage[$index])) : '' ?>" placeholder="0" autocomplete="off" <?= $error ? 'aria-invalid="true" aria-describedby="usage-error-' . $index . '"' : '' ?>>
                                <span>kWh</span>
                            </div>
                            <?php if ($error): ?><p class="field-message" id="usage-error-<?= $index ?>"><?= e($error) ?></p><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="form-footer">
                    <p><span class="info-icon">i</span> Complete each block before adding usage to the next.</p>
                    <button class="primary-button" type="submit" name="action" value="calculate">Calculate current bill <svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button>
                </div>
            </section>

            <section class="customizer-shell" aria-labelledby="customizer-title">
                <details <?= $profileErrors !== [] ? 'open' : '' ?>>
                    <summary>
                        <span class="customizer-icon"><svg viewBox="0 0 24 24"><path d="M4 7h10m4 0h2M4 17h2m4 0h10M14 4v6M6 14v6"/></svg></span>
                        <span><strong id="customizer-title">Customize tariff profile</strong><small>Currency, block sizes, energy rates, minimum charge and SST</small></span>
                        <span class="summary-action">Edit settings <svg viewBox="0 0 24 24"><path d="m7 10 5 5 5-5"/></svg></span>
                    </summary>

                    <div class="customizer-content">
                        <div class="settings-grid">
                            <label>Currency code<input name="currency_code" maxlength="3" value="<?= e((string) $profile['currency_code']) ?>" class="<?= isset($profileErrors['currency_code']) ? 'invalid' : '' ?>"><small><?= e($profileErrors['currency_code'] ?? 'Example: MYR, USD, SGD') ?></small></label>
                            <label>Currency symbol<input name="currency_symbol" maxlength="8" value="<?= e((string) $profile['currency_symbol']) ?>" class="<?= isset($profileErrors['currency_symbol']) ? 'invalid' : '' ?>"><small><?= e($profileErrors['currency_symbol'] ?? 'Example: RM, $, S$') ?></small></label>
                            <label>Minimum charge<input name="minimum_charge" inputmode="decimal" value="<?= e(BillingCalculator::formatNumber((float) $profile['minimum_charge'])) ?>" class="<?= isset($profileErrors['minimum_charge']) ? 'invalid' : '' ?>"><small><?= e($profileErrors['minimum_charge'] ?? 'Applied before SST') ?></small></label>
                            <label>SST threshold (kWh)<input name="sst_threshold_kwh" inputmode="decimal" value="<?= e(BillingCalculator::formatNumber((float) $profile['sst_threshold_kwh'])) ?>" class="<?= isset($profileErrors['sst_threshold_kwh']) ? 'invalid' : '' ?>"><small><?= e($profileErrors['sst_threshold_kwh'] ?? 'Default: above 600 kWh') ?></small></label>
                            <label>SST rate (%)<input name="sst_rate" inputmode="decimal" value="<?= e(BillingCalculator::formatNumber((float) $profile['sst_rate'])) ?>" class="<?= isset($profileErrors['sst_rate']) ? 'invalid' : '' ?>"><small><?= e($profileErrors['sst_rate'] ?? 'Default: 6%') ?></small></label>
                        </div>

                        <div class="editor-heading"><div><strong>Tariff blocks</strong><span>The final block may have no limit.</span></div><button type="button" class="add-tier" id="add-tier">+ Add a tier</button></div>
                        <div class="tier-editor" id="tier-editor">
                            <div class="editor-head"><span>Label</span><span>Block size (kWh)</span><span>Rate / kWh</span><span></span></div>
                            <?php foreach ($profile['tariff_tiers'] as $index => $tier): ?>
                                <div class="editor-row" data-editor-row>
                                    <input name="tier_label[]" aria-label="Tier <?= $index + 1 ?> label" value="<?= e((string) $tier['label']) ?>" class="<?= isset($profileErrors['tier_label_' . $index]) ? 'invalid' : '' ?>">
                                    <input name="tier_limit[]" inputmode="decimal" aria-label="Tier <?= $index + 1 ?> block size" value="<?= $tier['limit_kwh'] !== null ? e(BillingCalculator::formatNumber((float) $tier['limit_kwh'])) : '' ?>" placeholder="No limit" class="<?= isset($profileErrors['tier_limit_' . $index]) ? 'invalid' : '' ?>">
                                    <div class="rate-input"><span><?= e($currency) ?></span><input name="tier_rate[]" inputmode="decimal" aria-label="Tier <?= $index + 1 ?> rate" value="<?= e(BillingCalculator::formatNumber((float) $tier['rate'])) ?>" class="<?= isset($profileErrors['tier_rate_' . $index]) ? 'invalid' : '' ?>"></div>
                                    <button type="button" class="remove-tier" aria-label="Remove tier" title="Remove tier">×</button>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if (isset($profileErrors['tiers']) || isset($profileErrors['request'])): ?><p class="editor-error"><?= e($profileErrors['tiers'] ?? $profileErrors['request']) ?></p><?php endif; ?>

                        <div class="customizer-actions">
                            <button type="submit" class="reset-button" name="action" value="reset_profile">Restore published tariff</button>
                            <button type="submit" class="save-button" name="action" value="save_profile">Save tariff profile</button>
                        </div>
                    </div>
                </details>
            </section>
        </form>

        <?php if ($result !== null): ?>
            <section class="result-section" id="bill-result" aria-labelledby="result-title" aria-live="polite">
                <div class="result-header">
                    <div><p class="section-kicker light">Your estimate</p><h2 id="result-title">Current bill breakdown</h2></div>
                    <div class="usage-total"><span>Total consumption</span><strong><?= e(BillingCalculator::formatNumber((float) $result['total_kwh'])) ?> <small>kWh</small></strong></div>
                </div>
                <div class="result-layout">
                    <div class="bill-lines">
                        <div class="bill-table-head"><span>Tariff block</span><span>Usage × rate</span><span>Amount</span></div>
                        <?php foreach ($result['lines'] as $line): ?>
                            <div class="bill-line">
                                <i></i><div><strong><?= e($line['range']) ?></strong><span><?= e($line['label']) ?></span></div>
                                <span><?= e(BillingCalculator::formatNumber((float) $line['usage'])) ?> × <?= e($currency) ?> <?= number_format((float) $line['rate'], 3) ?></span>
                                <strong><?= e($currency) ?> <?= number_format((float) $line['amount'], 2) ?></strong>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <aside class="total-card">
                        <p>Final current bill</p>
                        <div class="grand-total"><span><?= e($currency) ?></span><strong><?= number_format((float) $result['final_total'], 2) ?></strong></div>
                        <div class="total-rule"></div>
                        <dl>
                            <div><dt>Tariff consumption</dt><dd><?= e($currency) ?> <?= number_format((float) $result['tariff_subtotal'], 2) ?></dd></div>
                            <?php if ($result['minimum_adjustment'] > 0): ?><div><dt>Minimum adjustment</dt><dd><?= e($currency) ?> <?= number_format((float) $result['minimum_adjustment'], 2) ?></dd></div><?php endif; ?>
                            <div><dt>Bill before SST</dt><dd><?= e($currency) ?> <?= number_format((float) $result['before_sst'], 2) ?></dd></div>
                            <div><dt>SST (<?= e(BillingCalculator::formatNumber((float) $profile['sst_rate'])) ?>%)</dt><dd><?= $result['sst_applies'] ? e($currency) . ' ' . number_format((float) $result['sst_amount'], 2) : 'Not applicable' ?></dd></div>
                        </dl>
                        <div class="sst-status"><span><?= $result['sst_applies'] ? '✓' : '–' ?></span><?= $result['sst_applies'] ? 'SST applied because usage exceeds the threshold.' : 'Usage does not exceed the SST threshold.' ?></div>
                    </aside>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($history !== []): ?>
            <section class="history-section" aria-labelledby="history-title">
                <div><p class="section-kicker">Stored in Supabase</p><h2 id="history-title">Recent calculations</h2></div>
                <div class="history-list">
                    <?php foreach ($history as $item): ?>
                        <article><div><strong><?= e(BillingCalculator::formatNumber((float) $item['total_kwh'])) ?> kWh</strong><span><?= e(date('d M Y, H:i', strtotime((string) $item['created_at']))) ?></span></div><strong><?= e((string) $item['currency_code']) ?> <?= number_format((float) $item['final_total'], 2) ?></strong></article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <footer class="site-footer"><div><strong>Elektrifi</strong><span>Domestic electricity estimator</span></div><p>GMI · DCRM/Full Level · CRM 1963 · July 2022</p><p>Examiner: Nurul Rafidza Muhamad Rashid</p></footer>

    <template id="tier-row-template">
        <div class="editor-row" data-editor-row>
            <input name="tier_label[]" aria-label="New tier label" value="Additional tier">
            <input name="tier_limit[]" inputmode="decimal" aria-label="New tier block size" value="" placeholder="No limit">
            <div class="rate-input"><span><?= e($currency) ?></span><input name="tier_rate[]" inputmode="decimal" aria-label="New tier rate" value="0.600"></div>
            <button type="button" class="remove-tier" aria-label="Remove tier" title="Remove tier">×</button>
        </div>
    </template>
    <script src="script.js"></script>
</body>
</html>

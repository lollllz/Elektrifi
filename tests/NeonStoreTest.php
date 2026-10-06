<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/NeonStore.php';
require_once __DIR__ . '/../lib/BillingCalculator.php';

final class RecordingNeonStatement extends PDOStatement
{
    public function __construct(private RecordingNeonPDO $database, private string $sql) {}
    public function execute(?array $params = null): bool
    {
        $this->database->calls[] = ['sql' => $this->sql, 'params' => $params];
        if ($this->database->fail) throw new PDOException('Internal credentials must not reach the UI');
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->database->row;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->database->rows;
    }
}

final class RecordingNeonPDO extends PDO
{
    public array $calls = [];
    public array|false $row = false;
    public array $rows = [];
    public bool $fail = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new RecordingNeonStatement($this, $query);
    }
}

function expectNeon(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$database = new RecordingNeonPDO();
$store = new NeonStore('postgresql://user:password@ep-example.neon.tech/neondb');
$connection = new ReflectionProperty(NeonStore::class, 'connection');
$connection->setValue($store, $database);
$store->testConnection();
expectNeon(count($database->calls) === 2, 'Both tables must be checked.');
expectNeon($store->getProfile('private-client') === null, 'Missing profile must remain null.');
expectNeon(end($database->calls)['params']['client_id'] === 'private-client', 'Profile lookup must bind the owner.');
$profile = BillingCalculator::defaultProfile();
$database->row = ['id' => 'profile-one', 'tariff_tiers' => json_encode($profile['tariff_tiers'])];
$saved = $store->saveProfile('private-client', $profile);
expectNeon(is_array($saved['tariff_tiers']), 'Postgres JSON must be decoded.');
expectNeon(abs(end($database->calls)['params']['sst_rate'] - 0.06) < 0.00001, 'SST must be stored as a fraction.');
expectNeon(str_contains(end($database->calls)['sql'], 'ON CONFLICT (client_id)'), 'Saving must upsert the owner profile.');
$usage = [200, 100, 300, 180, 0];
$result = BillingCalculator::calculate($profile, $usage);
$store->saveCalculation('profile-one', $profile, $usage, $result);
$parameters = end($database->calls)['params'];
expectNeon($parameters['profile_id'] === 'profile-one', 'Bill must belong to its profile.');
expectNeon(json_decode($parameters['usage_by_tier'], true) === $usage, 'Usage blocks must round-trip as JSON.');
expectNeon(round($parameters['final_total'], 2) === 350.94, 'Reference bill must be saved correctly.');
$database->rows = [['final_total' => '350.9400']];
expectNeon($store->getHistory('profile-one') === $database->rows, 'History rows must return unchanged.');
expectNeon(str_contains(end($database->calls)['sql'], 'LIMIT 5'), 'History must remain bounded.');
$database->fail = true;
try {
    $store->getHistory('profile-one');
    throw new LogicException('Database failure was ignored.');
} catch (RuntimeException $exception) {
    expectNeon(!str_contains($exception->getMessage(), 'Internal credentials'), 'Driver errors must be sanitized.');
}
echo "NeonStore: adapter contract, owner bindings, JSON, reference bill and sanitized errors passed (mock PDO)\n";

<?php
declare(strict_types=1);

require_once __DIR__ . '/DatabaseConnection.php';

final class NeonStore
{
    private ?PDO $connection = null;

    public function __construct(private string $connectionString) {}

    public function isConfigured(): bool
    {
        return $this->connectionString !== '';
    }

    private function database(): PDO
    {
        if ($this->connection !== null) return $this->connection;
        if (!extension_loaded('pdo_pgsql')) throw new RuntimeException('Neon requires the PHP pdo_pgsql extension. Enable it and restart PHP.');
        DatabaseConnection::validate('', $this->connectionString, 'neon');
        $parts = parse_url($this->connectionString);
        $caFile = null;
        foreach ([getenv('NEON_SSL_ROOT_CERT') ?: '', ini_get('openssl.cafile') ?: '', '/etc/ssl/cert.pem', '/etc/ssl/certs/ca-certificates.crt', '/opt/homebrew/etc/openssl@3/cert.pem'] as $candidate) {
            if ($candidate !== '' && is_file($candidate) && preg_match('~^[A-Za-z0-9_/@.+-]+$~D', $candidate)) {
                $caFile = $candidate;
                break;
            }
        }
        if ($caFile === null) throw new RuntimeException('Set NEON_SSL_ROOT_CERT to a trusted CA certificate bundle on this PHP server.');
        $dsn = 'pgsql:host=' . $parts['host'] . ';port=5432;dbname=' . substr($parts['path'], 1)
            . ';sslmode=verify-full;sslrootcert=' . $caFile . ';connect_timeout=8';
        try {
            $this->connection = new PDO($dsn, rawurldecode($parts['user']), rawurldecode($parts['pass']), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->connection->exec("SET statement_timeout = '12s'");
        } catch (PDOException $exception) {
            throw new RuntimeException('Could not connect to Neon. Check the connection string, database password, network access and server CA certificates.');
        }
        return $this->connection;
    }

    private function query(string $sql, array $parameters = []): PDOStatement
    {
        try {
            $statement = $this->database()->prepare($sql);
            $statement->execute($parameters);
            return $statement;
        } catch (PDOException $exception) {
            $code = (string) $exception->getCode();
            if ($code === '42P01' || $code === '42703') throw new RuntimeException('Neon table setup is missing or incompatible. Run the Neon setup SQL below before connecting.');
            if ($code === '42501') throw new RuntimeException('Your Neon database role needs access to the electricity profiles, bills and identity sequence.');
            throw new RuntimeException('Neon could not complete this database request. Check your database permissions and table setup.');
        }
    }

    public function testConnection(): void
    {
        $this->query('SELECT id, client_id, currency_code, currency_symbol, minimum_charge, sst_rate, sst_threshold_kwh, tariff_tiers FROM public.electricity_profiles LIMIT 0');
        $this->query('SELECT id, profile_id, currency_code, usage_by_tier, total_kwh, tariff_subtotal, minimum_adjustment, sst_amount, final_total, created_at FROM public.bill_calculations LIMIT 0');
    }

    private static function hydrate(array $row): array
    {
        if (isset($row['tariff_tiers']) && is_string($row['tariff_tiers'])) $row['tariff_tiers'] = json_decode($row['tariff_tiers'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }

    public function getProfile(string $clientId): ?array
    {
        $row = $this->query('SELECT * FROM public.electricity_profiles WHERE client_id = :client_id LIMIT 1', ['client_id' => $clientId])->fetch();
        return $row === false ? null : self::hydrate($row);
    }

    public function saveProfile(string $clientId, array $profile): array
    {
        $row = $this->query('INSERT INTO public.electricity_profiles
            (client_id, currency_code, currency_symbol, minimum_charge, sst_rate, sst_threshold_kwh, tariff_tiers)
            VALUES (:client_id, :currency_code, :currency_symbol, :minimum_charge, :sst_rate, :sst_threshold_kwh, CAST(:tariff_tiers AS jsonb))
            ON CONFLICT (client_id) DO UPDATE SET currency_code = EXCLUDED.currency_code,
            currency_symbol = EXCLUDED.currency_symbol, minimum_charge = EXCLUDED.minimum_charge,
            sst_rate = EXCLUDED.sst_rate, sst_threshold_kwh = EXCLUDED.sst_threshold_kwh,
            tariff_tiers = EXCLUDED.tariff_tiers, updated_at = now() RETURNING *', [
                'client_id' => $clientId, 'currency_code' => $profile['currency_code'],
                'currency_symbol' => $profile['currency_symbol'], 'minimum_charge' => $profile['minimum_charge'],
                'sst_rate' => $profile['sst_rate'] / 100, 'sst_threshold_kwh' => $profile['sst_threshold_kwh'],
                'tariff_tiers' => json_encode($profile['tariff_tiers'], JSON_THROW_ON_ERROR),
            ])->fetch();
        return self::hydrate($row);
    }

    public function saveCalculation(string $profileId, array $profile, array $usage, array $result): void
    {
        $this->query('INSERT INTO public.bill_calculations
            (profile_id, currency_code, usage_by_tier, total_kwh, tariff_subtotal, minimum_adjustment, sst_amount, final_total)
            VALUES (:profile_id, :currency_code, CAST(:usage_by_tier AS jsonb), :total_kwh, :tariff_subtotal, :minimum_adjustment, :sst_amount, :final_total)', [
                'profile_id' => $profileId, 'currency_code' => $profile['currency_code'],
                'usage_by_tier' => json_encode($usage, JSON_THROW_ON_ERROR),
                'total_kwh' => $result['total_kwh'], 'tariff_subtotal' => $result['tariff_subtotal'],
                'minimum_adjustment' => $result['minimum_adjustment'], 'sst_amount' => $result['sst_amount'],
                'final_total' => $result['final_total'],
            ]);
    }

    public function getHistory(string $profileId): array
    {
        return $this->query('SELECT id, total_kwh, tariff_subtotal, sst_amount, final_total, currency_code, created_at
            FROM public.bill_calculations WHERE profile_id = :profile_id ORDER BY created_at DESC, id DESC LIMIT 5', ['profile_id' => $profileId])->fetchAll();
    }
}

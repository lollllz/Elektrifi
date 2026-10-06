<?php
declare(strict_types=1);

final class DatabaseConnection
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? sys_get_temp_dir() . '/elektrifi-connections-' . substr(hash('sha256', __DIR__), 0, 16);
    }

    public static function validate(string $url, string $key, string $provider = 'supabase'): array
    {
        if ($provider === 'neon') {
            $connectionString = trim($key);
            $parts = parse_url($connectionString);
            if (strlen($connectionString) > 2048 || !is_array($parts)
                || !in_array($parts['scheme'] ?? '', ['postgres', 'postgresql'], true)
                || !preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.neon\.tech$/D', $parts['host'] ?? '')
                || ($parts['port'] ?? 5432) !== 5432
                || empty($parts['user']) || empty($parts['pass'])
                || !preg_match('~^/[A-Za-z0-9_-]+$~D', $parts['path'] ?? '')
                || isset($parts['fragment'])) {
                throw new RuntimeException('Enter a Neon PostgreSQL connection string with a neon.tech hostname, database, username and password.');
            }
            return ['provider' => 'neon', 'connection_string' => $connectionString];
        }
        if ($provider !== 'supabase') throw new RuntimeException('Choose Supabase or Neon.');
        $url = rtrim(trim($url), '/');
        $key = trim($key);
        if (!preg_match('~^https://[a-z0-9-]+\.supabase\.co$~D', $url)) {
            throw new RuntimeException('Enter your Supabase project URL, such as https://your-project.supabase.co.');
        }
        if (!preg_match('/^sb_secret_[A-Za-z0-9_-]{10,200}$/D', $key)) {
            throw new RuntimeException('Enter a server secret key beginning with sb_secret_. Publishable keys cannot save these private tables.');
        }
        return ['provider' => 'supabase', 'url' => $url, 'key' => $key];
    }

    public function read(string $token, string $clientId): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
        $path = $this->directory . '/' . hash('sha256', $token) . '.json';
        if (!is_file($path)) return null;
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || ($data['expires'] ?? 0) < time() || !hash_equals($clientId, (string) ($data['client_id'] ?? ''))) return null;
        $provider = (string) ($data['provider'] ?? 'supabase');
        return self::validate((string) ($data['url'] ?? ''), (string) ($provider === 'neon' ? ($data['connection_string'] ?? '') : ($data['key'] ?? '')), $provider);
    }

    public function save(array $credentials, string $clientId): string
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException('The server cannot create private connection storage.');
        }
        $resolved = realpath($this->directory);
        $webRoot = realpath(dirname(__DIR__));
        if ($resolved === false || $resolved === $webRoot || str_starts_with($resolved . '/', $webRoot . '/')) {
            throw new RuntimeException('Connection storage must be outside the public project directory.');
        }
        if (!chmod($this->directory, 0700)) throw new RuntimeException('Cannot protect connection storage.');
        $token = bin2hex(random_bytes(32));
        $path = $this->directory . '/' . hash('sha256', $token) . '.json';
        $previousMask = umask(0077);
        try {
            $written = file_put_contents($path, json_encode($credentials + ['client_id' => $clientId, 'expires' => time() + 86400], JSON_THROW_ON_ERROR), LOCK_EX);
            if ($written === false) throw new RuntimeException('The server cannot save this connection.');
        } finally {
            umask($previousMask);
        }
        return $token;
    }

    public function forget(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return;
        $path = $this->directory . '/' . hash('sha256', $token) . '.json';
        if (is_file($path)) unlink($path);
    }
}

<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/DatabaseConnection.php';

function checkConnection(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$directory = sys_get_temp_dir() . '/elektrifi-test-' . bin2hex(random_bytes(8));
$store = new DatabaseConnection($directory);
$credentials = DatabaseConnection::validate('https://exampleproject.supabase.co/', 'sb_secret_test_key_123456');
$token = $store->save($credentials, 'client-one');
try {
    checkConnection($store->read($token, 'client-one') === $credentials, 'Owner must recover their connection.');
    checkConnection($store->read($token, 'client-two') === null, 'Other clients must not recover the connection.');
    checkConnection($store->read('../../etc/passwd', 'client-one') === null, 'Invalid tokens must be rejected.');
    checkConnection((fileperms($directory) & 0777) === 0700, 'Directory must be private.');
    $path = $directory . '/' . hash('sha256', $token) . '.json';
    checkConnection((fileperms($path) & 0777) === 0600, 'Credentials must be private.');
    foreach (['http://exampleproject.supabase.co', 'https://127.0.0.1', 'https://exampleproject.supabase.co.evil.com', 'https://exampleproject.supabase.co/path'] as $url) {
        try {
            DatabaseConnection::validate($url, $credentials['key']);
            throw new LogicException('Unsafe URL accepted.');
        } catch (RuntimeException $expected) {}
    }
    try {
        DatabaseConnection::validate($credentials['url'], 'sb_publishable_test_key');
        throw new LogicException('Publishable key accepted.');
    } catch (RuntimeException $expected) {}
    $expired = $credentials + ['client_id' => 'client-one', 'expires' => time() - 1];
    file_put_contents($path, json_encode($expired));
    checkConnection($store->read($token, 'client-one') === null, 'Expired credentials must be rejected.');
    $store->forget($token);
    checkConnection(!is_file($path), 'Disconnect must remove credentials.');
    $neonUrl = 'postgresql://neondb_owner:example%40password@ep-example-pooler.us-east-2.aws.neon.tech/neondb?sslmode=require&channel_binding=require';
    $neon = DatabaseConnection::validate('', $neonUrl, 'neon');
    checkConnection($neon['connection_string'] === $neonUrl, 'Neon URL must be preserved privately.');
    $neonToken = $store->save($neon, 'client-one');
    checkConnection($store->read($neonToken, 'client-one') === $neon, 'Neon provider must survive storage.');
    $store->forget($neonToken);
    foreach ([
        'postgresql://user:password@localhost/neondb',
        'postgresql://user:password@ep-example.neon.tech.evil.com/neondb',
        'postgresql://user:password@ep-example.neon.tech:9999/neondb',
        'postgresql://user:password@ep-example.neon.tech/neondb%3Bhost=localhost',
        'postgresql://user@ep-example.neon.tech/neondb',
    ] as $url) {
        try {
            DatabaseConnection::validate('', $url, 'neon');
            throw new LogicException('Unsafe Neon URL accepted.');
        } catch (RuntimeException $expected) {}
    }
    echo "DatabaseConnection: isolation, validation, permissions, expiry and disconnect passed\n";
} finally {
    $store->forget($token);
    rmdir($directory);
}

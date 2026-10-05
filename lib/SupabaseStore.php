<?php
declare(strict_types=1);

final class SupabaseStore
{
    private string $url;
    private string $key;

    public function __construct(?string $url, ?string $key)
    {
        $this->url = rtrim((string) $url, '/');
        $this->key = (string) $key;
    }

    public function isConfigured(): bool
    {
        return $this->url !== '' && $this->key !== '';
    }

    /** @return array<string, mixed>|null */
    public function getProfile(string $clientId): ?array
    {
        $rows = $this->request('GET', '/rest/v1/electricity_profiles?client_id=eq.' . rawurlencode($clientId) . '&select=*&limit=1');
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : null;
    }

    /** @param array<string, mixed> $profile @return array<string, mixed> */
    public function saveProfile(string $clientId, array $profile): array
    {
        $payload = [
            'client_id' => $clientId,
            'currency_code' => $profile['currency_code'],
            'currency_symbol' => $profile['currency_symbol'],
            'minimum_charge' => $profile['minimum_charge'],
            'sst_rate' => $profile['sst_rate'] / 100,
            'sst_threshold_kwh' => $profile['sst_threshold_kwh'],
            'tariff_tiers' => $profile['tariff_tiers'],
            'updated_at' => gmdate('c'),
        ];
        $rows = $this->request('POST', '/rest/v1/electricity_profiles?on_conflict=client_id', $payload, [
            'Prefer: resolution=merge-duplicates,return=representation',
        ]);
        return isset($rows[0]) && is_array($rows[0]) ? $rows[0] : [];
    }

    /** @param array<string, mixed> $result @param array<int, float> $usage */
    public function saveCalculation(string $profileId, array $profile, array $usage, array $result): void
    {
        $this->request('POST', '/rest/v1/bill_calculations', [
            'profile_id' => $profileId,
            'currency_code' => $profile['currency_code'],
            'usage_by_tier' => $usage,
            'total_kwh' => $result['total_kwh'],
            'tariff_subtotal' => $result['tariff_subtotal'],
            'minimum_adjustment' => $result['minimum_adjustment'],
            'sst_amount' => $result['sst_amount'],
            'final_total' => $result['final_total'],
        ], ['Prefer: return=minimal']);
    }

    /** @return array<int, array<string, mixed>> */
    public function getHistory(string $profileId): array
    {
        $path = '/rest/v1/bill_calculations?profile_id=eq.' . rawurlencode($profileId)
            . '&select=id,total_kwh,tariff_subtotal,sst_amount,final_total,currency_code,created_at'
            . '&order=created_at.desc&limit=5';
        $rows = $this->request('GET', $path);
        return is_array($rows) ? $rows : [];
    }

    /** @return array<string, mixed> */
    public static function profileFromRow(array $row): array
    {
        return [
            'currency_code' => (string) $row['currency_code'],
            'currency_symbol' => (string) $row['currency_symbol'],
            'minimum_charge' => (float) $row['minimum_charge'],
            'sst_rate' => (float) $row['sst_rate'] * 100,
            'sst_threshold_kwh' => (float) $row['sst_threshold_kwh'],
            'tariff_tiers' => is_array($row['tariff_tiers']) ? $row['tariff_tiers'] : [],
        ];
    }

    /** @return mixed */
    private function request(string $method, string $path, ?array $payload = null, array $extraHeaders = []): mixed
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Supabase is not configured.');
        }

        $handle = curl_init($this->url . $path);
        $headers = array_merge([
            'apikey: ' . $this->key,
            'Accept: application/json',
            'Content-Type: application/json',
        ], $extraHeaders);

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 12,
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
        }

        $body = curl_exec($handle);
        if ($body === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new RuntimeException('Could not reach Supabase: ' . $message);
        }
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $decoded = $body === '' ? [] : json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? ($decoded['message'] ?? $decoded['hint'] ?? 'Request failed') : 'Request failed';
            throw new RuntimeException('Supabase request failed (' . $status . '): ' . $message);
        }

        return $decoded;
    }
}

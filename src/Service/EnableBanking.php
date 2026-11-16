<?php
namespace Budgetcontrol\Sync\Service;

class EnableBanking
{
    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function request(string $method, string $path, array $data = []): array
    {
        $appId = (string) env('ENABLE_BANKING_APP_ID', '');
        $keyPath = (string) env('ENABLE_BANKING_PRIVATE_KEY_PATH', '');
        $key = $keyPath !== '' && is_readable($keyPath) ? file_get_contents($keyPath) : false;
        if ($appId === '' || !$key) {
            throw new \RuntimeException('Enable Banking is not configured', 503);
        }
        $now = time();
        $header = self::base64url(json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $appId]));
        $claims = self::base64url(json_encode(['iss' => 'enablebanking.com', 'aud' => 'api.enablebanking.com', 'iat' => $now, 'exp' => $now + 3600]));
        $signingInput = $header . '.' . $claims;
        if (!openssl_sign($signingInput, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Enable Banking private key is invalid', 503);
        }
        $url = 'https://api.enablebanking.com' . $path;
        if ($method === 'GET' && $data) {
            $url .= '?' . http_build_query($data);
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $signingInput . '.' . self::base64url($signature)],
            CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false]);
        if ($method === 'POST') curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
        $result = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if ($result === false || $status < 200 || $status >= 300) {
            throw new \RuntimeException(in_array($status, [401, 403, 404], true) ? 'Bank connection unavailable or expired; reconnect and try again' : 'Bank service unavailable; try again later', 503);
        }
        $decoded = json_decode($result, true);
        if (!is_array($decoded)) throw new \RuntimeException('Invalid bank service response', 503);
        return $decoded;
    }
}

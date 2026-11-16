<?php
namespace Budgetcontrol\Sync\Service;

use Illuminate\Database\Capsule\Manager as DB;

class SyncService
{
    private EnableBanking $bank;
    public function __construct(?EnableBanking $bank = null) { $this->bank = $bank ?? new EnableBanking(); }

    private function encryptionKey(): string
    {
        $key = base64_decode((string) env('SYNC_ENCRYPTION_KEY', ''), true);
        if ($key === false || strlen($key) !== 32) throw new \RuntimeException('Sync encryption key is not configured', 503);
        return $key;
    }

    private function encrypt(string $value): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($value, 'aes-256-gcm', $this->encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new \RuntimeException('Unable to save bank connection', 503);
        return base64_encode($iv . $tag . $cipher);
    }

    public function banks(): array
    {
        $data = $this->bank->request('GET', '/aspsps', ['country' => 'IT', 'service' => 'AIS']);
        return ['banks' => array_values(array_map(fn($bank) => ['name' => $bank['name'], 'country' => $bank['country'], 'maximum_consent_validity' => $bank['maximum_consent_validity'] ?? null],
            array_filter($data['aspsps'] ?? [], fn($bank) => isset($bank['name']) && preg_match('/credem|credito emiliano|bbva/i', $bank['name']))))];
    }

    public function start(array $context, array $input): array
    {
        $name = $input['bank'] ?? '';
        $available = $this->banks()['banks'];
        $selected = null;
        foreach ($available as $bank) if ($bank['name'] === $name) $selected = $bank;
        if (!is_string($name) || !$selected) throw new \InvalidArgumentException('Select a supported bank', 422);
        $maximum = (int) ($selected['maximum_consent_validity'] ?? 0);
        $duration = $maximum > 0 ? min(90 * 86400, $maximum) : 90 * 86400;
        $redirect = (string) env('ENABLE_BANKING_REDIRECT_URL', '');
        if (!filter_var($redirect, FILTER_VALIDATE_URL) || parse_url($redirect, PHP_URL_SCHEME) !== 'https') throw new \RuntimeException('Bank callback URL is not configured', 503);
        $state = bin2hex(random_bytes(32));
        $result = $this->bank->request('POST', '/auth', [
            'access' => ['valid_until' => gmdate('Y-m-d\\TH:i:s\\Z', time() + $duration)],
            'aspsp' => ['name' => $name, 'country' => 'IT'], 'state' => $state,
            'redirect_url' => $redirect, 'psu_type' => 'personal']);
        if (empty($result['url']) || parse_url($result['url'], PHP_URL_SCHEME) !== 'https') throw new \RuntimeException('Invalid bank authorization URL', 503);
        DB::table('sync_authorizations')->insert(['state_hash' => hash('sha256', $state), 'user_uuid' => $context['user_uuid'],
            'workspace_id' => $context['workspace_id'], 'expires_at' => gmdate('Y-m-d H:i:s', time() + 900)]);
        return ['url' => $result['url']];
    }

    public function complete(array $context, array $input): array
    {
        $state = $input['state'] ?? ''; $code = $input['code'] ?? '';
        if (!is_string($state) || !preg_match('/^[a-f0-9]{64}$/', $state) || !is_string($code) || $code === '' || strlen($code) > 2048) throw new \InvalidArgumentException('Invalid bank authorization', 400);
        // Consume state before exchanging the one-time code to prevent replay.
        $count = DB::table('sync_authorizations')->where('state_hash', hash('sha256', $state))
            ->where('user_uuid', $context['user_uuid'])->where('workspace_id', $context['workspace_id'])
            ->where('expires_at', '>', gmdate('Y-m-d H:i:s'))->delete();
        if ($count !== 1) throw new \InvalidArgumentException('Bank authorization expired; start again', 400);
        $session = $this->bank->request('POST', '/sessions', ['code' => $code]);
        if (empty($session['session_id']) || empty($session['accounts'])) throw new \RuntimeException('No accounts returned by the bank', 503);
        $accounts = [];
        foreach ($session['accounts'] as $account) {
            // Identification hash is stable across authorizations and required for safe deduplication.
            if (empty($account['uid']) || empty($account['identification_hash'])) continue;
            $accounts[] = ['uid' => $account['uid'], 'identification_hash' => $account['identification_hash'],
                'name' => $account['name'] ?? $account['product'] ?? 'Account', 'currency' => $account['currency'] ?? ''];
        }
        if (!$accounts) throw new \RuntimeException('Bank accounts lack a stable identifier', 503);
        DB::table('sync_connections')->insert(['user_uuid' => $context['user_uuid'], 'workspace_id' => $context['workspace_id'],
            'session_ciphertext' => $this->encrypt($session['session_id']), 'accounts_json' => json_encode($accounts),
            'valid_until' => empty($session['access']['valid_until']) ? null : gmdate('Y-m-d H:i:s', strtotime($session['access']['valid_until'])),
            'created_at' => gmdate('Y-m-d H:i:s')]);
        return ['connected' => true];
    }

    public function connections(array $context): array
    {
        $connections = DB::table('sync_connections')->where('user_uuid', $context['user_uuid'])->where('workspace_id', $context['workspace_id'])->orderBy('id', 'desc')->get();
        return ['connections' => $connections->map(function ($connection) {
            $accounts = json_decode($connection->accounts_json, true) ?: [];
            return ['id' => $connection->id, 'valid_until' => $connection->valid_until,
                'accounts' => array_map(fn($a) => ['uid' => $a['uid'], 'name' => $a['name'], 'currency' => $a['currency']], $accounts)];
        })->all()];
    }

    public function sync(array $context, array $input): array
    {
        $connection = DB::table('sync_connections')->where('user_uuid', $context['user_uuid'])->where('workspace_id', $context['workspace_id'])->where('id', $input['connection_id'] ?? 0)->first();
        if (!$connection) throw new \InvalidArgumentException('Connection not found', 404);
        if ($connection->valid_until && strtotime($connection->valid_until . ' UTC') <= time()) throw new \RuntimeException('Bank consent expired; reconnect', 409);
        $accounts = json_decode($connection->accounts_json, true) ?: [];
        $account = null;
        foreach ($accounts as $candidate) if ($candidate['uid'] === ($input['account_uid'] ?? null)) $account = $candidate;
        if (!$account) throw new \InvalidArgumentException('Account not found', 404);
        foreach (['account_id', 'category_id', 'currency_id', 'payment_type'] as $field) {
            if (filter_var($input[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new \InvalidArgumentException('Select all expense settings', 422);
        }
        $selectedCurrency = DB::table('currencies')->where('id', (int) $input['currency_id'])->value('label');
        if (!$selectedCurrency || strtoupper($selectedCurrency) !== strtoupper($account['currency'])) throw new \InvalidArgumentException('Select the bank account currency', 422);
        $walletCurrency = DB::table('wallets')->where('id', (int) $input['account_id'])->where('workspace_id', $context['workspace_id'])->whereNull('deleted_at')->value('currency');
        if ($walletCurrency === null || ((string) $walletCurrency !== (string) $input['currency_id'] && strtoupper((string) $walletCurrency) !== strtoupper($selectedCurrency))) throw new \InvalidArgumentException('Select a wallet in the bank account currency', 422);
        $from = $input['date_from'] ?? '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if (!$date || $date->format('Y-m-d') !== $from || $from > gmdate('Y-m-d')) throw new \InvalidArgumentException('Select a valid starting date', 422);
        $params = ['date_from' => $from, 'transaction_status' => 'BOOK'];
        $key = null; $seenKeys = []; $imported = 0; $duplicates = 0; $skipped = 0;
        do {
            $query = $params;
            if ($key !== null) $query['continuation_key'] = $key;
            $page = $this->bank->request('GET', '/accounts/' . rawurlencode($account['uid']) . '/transactions', $query);
            foreach (($page['transactions'] ?? []) as $tx) {
                if (($tx['status'] ?? '') !== 'BOOK' || ($tx['credit_debit_indicator'] ?? '') !== 'DBIT') continue;
                $reference = $tx['entry_reference'] ?? '';
                $amount = $tx['transaction_amount']['amount'] ?? '';
                $currency = $tx['transaction_amount']['currency'] ?? '';
                $booking = $tx['booking_date'] ?? '';
                if (!$reference || !is_numeric($amount) || (float)$amount == 0 || !$booking || !$account['currency'] || $currency !== $account['currency']) { $skipped++; continue; }
                $sourceHash = hash('sha256', json_encode(['enablebanking', $account['identification_hash'], (string) $reference]));
                $note = implode(' ', array_filter($tx['remittance_information'] ?? []));
                if ($note === '') $note = $tx['creditor']['name'] ?? 'Bank transaction';
                $expense = ['source_hash' => $sourceHash, 'date_time' => $booking . ' 12:00:00', 'amount' => '-' . ltrim((string)$amount, '-+'),
                    'note' => mb_substr($note, 0, 255), 'account_id' => (int)$input['account_id'], 'category_id' => (int)$input['category_id'],
                    'currency_id' => (int)$input['currency_id'], 'payment_type' => (int)$input['payment_type']];
                $result = $this->importExpense($context['workspace_id'], $expense);
                if ($result === 'created') $imported++; else $duplicates++;
            }
            $key = $page['continuation_key'] ?? null;
            if ($key !== null && (isset($seenKeys[$key]) || count($seenKeys) >= 500)) throw new \RuntimeException('Bank pagination could not be completed', 503);
            if ($key !== null) $seenKeys[$key] = true;
        } while ($key !== null);
        return compact('imported', 'duplicates', 'skipped');
    }

    protected function importExpense(int $workspaceId, array $expense): string
    {
        $base = rtrim((string) env('ENTRY_API_BASE_URL', ''), '/');
        $secret = (string) env('ENTRY_SYNC_SECRET', '');
        if (!$base || !$secret) throw new \RuntimeException('Entries integration is not configured', 503);
        $curl = curl_init($base . '/' . $workspaceId . '/sync-expense');
        curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Sync-Secret: ' . $secret], CURLOPT_POSTFIELDS => json_encode($expense)]);
        $result = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        if ($result === false || !in_array($status, [200, 201], true)) throw new \RuntimeException('Expense import failed; retry synchronization', 503);
        $body = json_decode($result, true);
        if (!in_array($body['status'] ?? '', ['created', 'duplicate'], true)) throw new \RuntimeException('Invalid expense import response', 503);
        return $body['status'];
    }
}

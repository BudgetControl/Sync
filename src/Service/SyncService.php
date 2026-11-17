<?php
namespace Budgetcontrol\Sync\Service;

use Illuminate\Database\Capsule\Manager as DB;

class SyncService
{
    private EnableBanking $bank;
    private array $categoryKeywords = [];
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
            array_filter($data['aspsps'] ?? [], fn($bank) => isset($bank['name']) && preg_match('/credem|credito emiliano|bbva|revolut/i', $bank['name']))))];
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
        $walletId = filter_var($input['account_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($walletId === false) throw new \InvalidArgumentException('Select a wallet', 422);
        $locked = false;
        if (DB::connection()->getDriverName() === 'pgsql') {
            $value = DB::selectOne('SELECT pg_try_advisory_lock(193687, ?) AS locked', [$walletId])->locked;
            $locked = in_array($value, [true, 1, '1', 't'], true);
            if (!$locked) throw new \RuntimeException('A synchronization is already running for this wallet', 409);
        }
        try {
            return $this->syncAccount($context, $input);
        } finally {
            if ($locked) DB::selectOne('SELECT pg_advisory_unlock(193687, ?)', [$walletId]);
        }
    }

    private function syncAccount(array $context, array $input): array
    {
        $connection = DB::table('sync_connections')->where('user_uuid', $context['user_uuid'])->where('workspace_id', $context['workspace_id'])->where('id', $input['connection_id'] ?? 0)->first();
        if (!$connection) throw new \InvalidArgumentException('Connection not found', 404);
        if ($connection->valid_until && strtotime($connection->valid_until . ' UTC') <= time()) throw new \RuntimeException('Bank consent expired; reconnect', 409);
        $accounts = json_decode($connection->accounts_json, true) ?: [];
        $account = null;
        foreach ($accounts as $candidate) if ($candidate['uid'] === ($input['account_uid'] ?? null)) $account = $candidate;
        if (!$account) throw new \InvalidArgumentException('Account not found', 404);
        $input['income_category_id'] = $input['income_category_id'] ?? $input['category_id'] ?? null;
        foreach (['account_id', 'category_id', 'income_category_id', 'currency_id', 'payment_type'] as $field) {
            if (filter_var($input[$field] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) throw new \InvalidArgumentException('Select all expense settings', 422);
        }
        $selectedCurrency = DB::table('currencies')->where('id', (int) $input['currency_id'])->value('label');
        if (!$selectedCurrency || strtoupper($selectedCurrency) !== strtoupper($account['currency'])) throw new \InvalidArgumentException('Select the bank account currency', 422);
        $walletCurrency = DB::table('wallets')->where('id', (int) $input['account_id'])->where('workspace_id', $context['workspace_id'])->whereNull('deleted_at')->value('currency');
        if ($walletCurrency === null || ((string) $walletCurrency !== (string) $input['currency_id'] && strtoupper((string) $walletCurrency) !== strtoupper($selectedCurrency))) throw new \InvalidArgumentException('Select a wallet in the bank account currency', 422);
        $from = $input['date_from'] ?? '';
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        if (!$date || $date->format('Y-m-d') !== $from || $from > gmdate('Y-m-d')) throw new \InvalidArgumentException('Select a valid starting date', 422);
        foreach (['category_id', 'income_category_id'] as $field) {
            if (!DB::table('sub_categories')->where('id', (int) $input[$field])->whereNull('deleted_at')->exists()) {
                throw new \InvalidArgumentException('Select a valid category', 422);
            }
        }
        DB::connection()->transaction(function () use ($context, $input, $connection, $account, $from) {
        $other = DB::table('sync_account_settings')->where('workspace_id', $context['workspace_id'])
            ->where('account_id', $input['account_id'])
            ->where(function ($q) use ($connection, $account) {
                $q->where('connection_id', '!=', $connection->id)->orWhere('account_uid', '!=', $account['uid']);
            })->first();
        if ($other) {
            $oldConnection = DB::table('sync_connections')->where('id', $other->connection_id)
                ->where('user_uuid', $context['user_uuid'])->where('workspace_id', $context['workspace_id'])->first();
            $oldAccounts = $oldConnection ? (json_decode($oldConnection->accounts_json, true) ?: []) : [];
            $sameAccount = false;
            foreach ($oldAccounts as $oldAccount) {
                if (($oldAccount['uid'] ?? null) === $other->account_uid
                    && ($oldAccount['identification_hash'] ?? null) === $account['identification_hash']) $sameAccount = true;
            }
            if (!$sameAccount) throw new \InvalidArgumentException('Wallet is already linked to another bank account', 409);
            DB::table('sync_account_settings')->where('id', $other->id)->delete();
        }
        DB::table('sync_account_settings')->updateOrInsert(
            ['connection_id' => $connection->id, 'account_uid' => $account['uid']],
            ['user_uuid' => $context['user_uuid'], 'workspace_id' => $context['workspace_id'],
             'account_id' => (int) $input['account_id'], 'category_id' => (int) $input['category_id'],
             'income_category_id' => (int) $input['income_category_id'], 'currency_id' => (int) $input['currency_id'],
             'payment_type' => (int) $input['payment_type'], 'date_from' => $from, 'updated_at' => gmdate('Y-m-d H:i:s')]
        );
        });
        $params = ['date_from' => $from, 'transaction_status' => 'BOOK'];
        $key = null; $seenKeys = []; $imported = 0; $duplicates = 0; $skipped = 0;
        do {
            $query = $params;
            if ($key !== null) $query['continuation_key'] = $key;
            $page = $this->bank->request('GET', '/accounts/' . rawurlencode($account['uid']) . '/transactions', $query);
            foreach (($page['transactions'] ?? []) as $tx) {
                $direction = $tx['credit_debit_indicator'] ?? '';
                if (($tx['status'] ?? '') !== 'BOOK' || !in_array($direction, ['DBIT', 'CRDT'], true)) continue;
                $reference = $tx['entry_reference'] ?? '';
                $amount = $tx['transaction_amount']['amount'] ?? '';
                $currency = $tx['transaction_amount']['currency'] ?? '';
                $booking = $tx['booking_date'] ?? '';
                if (!$reference || !is_numeric($amount) || (float)$amount == 0 || !$booking || !$account['currency'] || $currency !== $account['currency']) { $skipped++; continue; }
                $sourceHash = hash('sha256', json_encode(['enablebanking', $account['identification_hash'], (string) $reference]));
                $note = implode(' ', array_filter($tx['remittance_information'] ?? []));
                if ($note === '') $note = $tx['creditor']['name'] ?? $tx['debtor']['name'] ?? $tx['bank_transaction_code']['description'] ?? 'Bank transaction';
                $defaultCategory = (int) $input[$direction === 'CRDT' ? 'income_category_id' : 'category_id'];
                $category = $this->recognizeCategory($context['workspace_id'], $note, $direction, $defaultCategory);
                $expense = ['source_hash' => $sourceHash, 'date_time' => $booking . ' 12:00:00', 'amount' => ($direction === 'DBIT' ? '-' : '') . ltrim((string)$amount, '-+'),
                    'note' => mb_substr($note, 0, 255), 'account_id' => (int)$input['account_id'], 'category_id' => $category,
                    'currency_id' => (int)$input['currency_id'], 'payment_type' => (int)$input['payment_type']];
                $result = $this->importExpense($context['workspace_id'], $expense);
                if ($result === 'created') $imported++; else $duplicates++;
            }
            $key = $page['continuation_key'] ?? null;
            if ($key !== null && (isset($seenKeys[$key]) || count($seenKeys) >= 500)) throw new \RuntimeException('Bank pagination could not be completed', 503);
            if ($key !== null) $seenKeys[$key] = true;
        } while ($key !== null);
        $this->alignBalance($context, $account, (int) $input['account_id']);
        return compact('imported', 'duplicates', 'skipped');
    }

    private function recognizeCategory(int $workspaceId, string $note, string $direction, int $fallback): int
    {
        // Learn only from manually created entries; never amplify guesses made by sync itself.
        $cacheKey = $workspaceId . ':' . $direction;
        if (!isset($this->categoryKeywords[$cacheKey])) $this->categoryKeywords[$cacheKey] = DB::table('entries_keywords as k')
            ->join('entries as e', 'e.id', '=', 'k.entry_id')
            ->leftJoin('sync_imported_expenses as imported', function ($join) {
                $join->on('imported.entry_uuid', '=', 'e.uuid')->on('imported.workspace_id', '=', 'e.workspace_id');
            })
            ->where('e.workspace_id', $workspaceId)->where('e.type', $direction === 'CRDT' ? 'incoming' : 'expenses')
            ->whereNull('e.deleted_at')->whereNull('imported.entry_uuid')
            ->orderByDesc('e.id')->limit(1000)->get(['k.keyword', 'e.category_id']);
        $matches = []; $longest = 0;
        foreach ($this->categoryKeywords[$cacheKey] as $keyword) {
            $phrase = trim((string) $keyword->keyword);
            $length = mb_strlen($phrase);
            if ($length < 5 || !preg_match('/(?<![\pL\pN])' . preg_quote($phrase, '/') . '(?![\pL\pN])/iu', $note)) continue;
            if ($length > $longest) { $matches = []; $longest = $length; }
            if ($length === $longest) $matches[(int) $keyword->category_id] = true;
        }
        return count($matches) === 1 ? (int) array_key_first($matches) : $fallback;
    }

    private function alignBalance(array $context, array $account, int $walletId): void
    {
        $result = $this->bank->request('GET', '/accounts/' . rawurlencode($account['uid']) . '/balances');
        $selected = null;
        foreach (['ITBD', 'CLBD', 'ITAV', 'CLAV'] as $type) {
            foreach ($result['balances'] ?? [] as $balance) {
                $amount = $balance['balance_amount']['amount'] ?? '';
                $currency = $balance['balance_amount']['currency'] ?? '';
                if (($balance['balance_type'] ?? '') === $type && $currency === $account['currency']
                    && is_string($amount) && preg_match('/^-?\d+(?:\.\d{1,2})?$/', $amount)
                    && (empty($balance['reference_date']) || $balance['reference_date'] >= gmdate('Y-m-d'))) {
                    $selected = $amount;
                    break 2;
                }
            }
        }
        if ($selected === null) throw new \RuntimeException('A current booked bank balance is unavailable', 503);
        $updated = DB::table('wallets')->where('id', $walletId)->where('workspace_id', $context['workspace_id'])
            ->whereNull('deleted_at')->update(['balance' => $selected]);
        if (!$updated) throw new \RuntimeException('Unable to update wallet balance', 503);
    }

    public function runScheduled(): array
    {
        $results = ['processed' => 0, 'failed' => 0, 'workspace_ids' => []];
        foreach (DB::table('sync_account_settings')->orderBy('id')->get() as $setting) {
            $results['workspace_ids'][(int) $setting->workspace_id] = (int) $setting->workspace_id;
            try {
                $this->sync(['user_uuid' => $setting->user_uuid, 'workspace_id' => $setting->workspace_id], (array) $setting);
                $results['processed']++;
            } catch (\Throwable $e) {
                $results['failed']++;
                error_log('Scheduled sync failed for setting ' . (int) $setting->id . ': ' . get_class($e));
            }
        }
        $results['workspace_ids'] = array_values($results['workspace_ids']);
        return $results;
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

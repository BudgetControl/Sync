<?php
// Run with: php Test/ManualSyncCheck.php
require __DIR__ . '/../vendor/autoload.php';

use Budgetcontrol\Sync\Http\Controller\SyncController;
use Budgetcontrol\Sync\Service\EnableBanking;
use Budgetcontrol\Sync\Service\SyncService;
use Illuminate\Database\Capsule\Manager as DB;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

function check(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
final class FakeBank extends EnableBanking
{
    public string $state = '';
    public array $queries = [];
    public array $balances = [['balance_type'=>'ITBD', 'balance_amount'=>['amount'=>'0.17', 'currency'=>'EUR']]];
    public function request(string $method, string $path, array $data = []): array
    {
        if ($path === '/aspsps') return ['aspsps' => [['name' => 'BBVA', 'country' => 'IT', 'maximum_consent_validity' => 86400], ['name' => 'Another Bank', 'country' => 'IT']]];
        if ($path === '/auth') { $this->state = $data['state']; check(strtotime($data['access']['valid_until']) <= time() + 86400, 'consent duration'); return ['url' => 'https://example.com/authorize']; }
        if ($path === '/sessions') return ['session_id' => 'session-secret', 'access' => ['valid_until' => gmdate('c', time()+86400)], 'accounts' => [['uid' => 'account-1', 'identification_hash' => 'stable-account', 'name' => 'BBVA', 'currency' => 'EUR']]];
        if ($path === '/accounts/account-1/balances') return ['balances' => $this->balances];
        if ($path === '/accounts/account-1/transactions') {
            $this->queries[] = $data;
            if (empty($data['continuation_key'])) return ['transactions' => [], 'continuation_key' => 'next'];
            $base = ['status'=>'BOOK', 'credit_debit_indicator'=>'DBIT', 'transaction_amount'=>['amount'=>'12.50', 'currency'=>'EUR'], 'booking_date'=>'2026-01-01'];
            return ['transactions' => [$base + ['entry_reference'=>'a'], $base, array_replace($base, ['entry_reference'=>'credit', 'credit_debit_indicator'=>'CRDT'])]];
        }
        throw new RuntimeException('Unexpected provider request');
    }
}
final class TestSync extends SyncService
{
    public array $imported = [];
    protected function importExpense(int $workspaceId, array $expense): string
    {
        check($workspaceId === 7 && in_array($expense['amount'], ['-12.50', '12.50'], true), 'transaction mapping');
        if (isset($this->imported[$expense['source_hash']])) return 'duplicate';
        $this->imported[$expense['source_hash']] = true;
        return 'created';
    }
}
$_ENV['SYNC_ENCRYPTION_KEY'] = base64_encode(str_repeat('k', 32));
$_ENV['ENABLE_BANKING_REDIRECT_URL'] = 'https://example.com/app/settings/open-banking';
$db = new DB();
if (getenv('SYNC_TEST_DB_DRIVER') === 'pgsql') {
    $database = getenv('SYNC_TEST_DB_DATABASE');
    if (!$database) throw new RuntimeException('SYNC_TEST_DB_DATABASE is required');
    $db->addConnection([
        'driver' => 'pgsql',
        'host' => getenv('SYNC_TEST_DB_HOST') ?: '127.0.0.1',
        'port' => getenv('SYNC_TEST_DB_PORT') ?: '5432',
        'database' => $database,
        'username' => getenv('SYNC_TEST_DB_USERNAME'),
        'password' => getenv('SYNC_TEST_DB_PASSWORD'),
        'charset' => 'utf8',
        'schema' => 'public',
        'prefix' => '',
    ]);
} else {
    $db->addConnection(['driver'=>'sqlite', 'database'=>':memory:', 'prefix'=>'']);
}
$db->setAsGlobal(); $db->bootEloquent();
$schema = $db->schema();
$schema->create('sync_authorizations', function($t) { $t->string('state_hash')->primary(); $t->string('user_uuid'); $t->integer('workspace_id'); $t->string('expires_at'); });
$schema->create('sync_connections', function($t) { $t->increments('id'); $t->string('user_uuid'); $t->integer('workspace_id'); $t->text('session_ciphertext'); $t->text('accounts_json'); $t->string('valid_until')->nullable(); $t->string('created_at'); });
$schema->create('wallets', function($t) { $t->increments('id'); $t->integer('workspace_id'); $t->string('currency'); $t->decimal('balance', 10, 2)->default(0); $t->softDeletes(); });
DB::table('wallets')->insert(['id'=>1, 'workspace_id'=>7, 'currency'=>'2']);
$schema->create('sub_categories', function($t) { $t->increments('id'); $t->softDeletes(); });
DB::table('sub_categories')->insert(['id'=>1]);
$schema->create('sync_account_settings', function($t) { $t->increments('id'); $t->integer('connection_id'); $t->string('account_uid'); $t->string('user_uuid'); $t->integer('workspace_id'); $t->integer('account_id'); $t->integer('category_id'); $t->integer('income_category_id'); $t->integer('currency_id'); $t->integer('payment_type'); $t->string('date_from'); $t->string('updated_at'); });
$schema->create('entries', function($t) { $t->increments('id'); $t->string('uuid'); $t->integer('workspace_id'); $t->integer('category_id'); $t->string('type'); $t->softDeletes(); });
$schema->create('entries_keywords', function($t) { $t->increments('id'); $t->integer('entry_id'); $t->text('keyword'); });
$schema->create('sync_imported_expenses', function($t) { $t->integer('workspace_id'); $t->string('entry_uuid'); });
$schema->create('currencies', function($t) { $t->increments('id'); $t->string('label'); });
DB::table('currencies')->insert(['id'=>2, 'label'=>'EUR']);
$controller = new SyncController(); $factory = new ServerRequestFactory();
$other = $factory->createServerRequest('POST','/')->withParsedBody(['identity'=>['email'=>'other@example.com']]);
$eligible = $controller->handle($other,new Response(),['action'=>'eligibility']);
check(json_decode((string)$eligible->getBody(),true)['eligible'] === false, 'other user eligibility');
check($controller->handle($other,new Response(),['action'=>'sync'])->getStatusCode() === 403, 'other user access');
$owner = $other->withParsedBody(['identity'=>['email'=>'marco.defelice890@gmail.com']]);
check(json_decode((string)$controller->handle($owner,new Response(),['action'=>'eligibility'])->getBody(),true)['eligible'] === true, 'owner eligibility');
$bank = new FakeBank(); $service = new TestSync($bank); $context = ['user_uuid'=>'user-1','workspace_id'=>7];
check(count($service->banks()['banks']) === 1, 'bank filtering');
$service->start($context, ['bank'=>'BBVA']);
$service->complete($context, ['state'=>$bank->state, 'code'=>'authorization-code']);
try { $service->complete($context, ['state'=>$bank->state, 'code'=>'authorization-code']); throw new RuntimeException('state replay accepted'); } catch (InvalidArgumentException $e) {}
check(!str_contains(DB::table('sync_connections')->first()->session_ciphertext, 'session-secret'), 'session is encrypted');
$input = ['connection_id'=>1, 'account_uid'=>'account-1', 'account_id'=>1, 'category_id'=>1, 'currency_id'=>2, 'payment_type'=>1, 'date_from'=>'2026-01-01'];
$first = $service->sync($context,$input); $second = $service->sync($context,$input);
check($first === ['imported'=>2,'duplicates'=>0,'skipped'=>1,'balance_updated'=>true], 'first sync');
check($second === ['imported'=>0,'duplicates'=>2,'skipped'=>1,'balance_updated'=>true], 'repeat sync');
check((float) DB::table('wallets')->where('id', 1)->value('balance') === 0.17, 'bank balance aligned');
$bank->balances[0]['reference_date'] = '2020-01-01';
$bank->balances[0]['balance_amount']['amount'] = '3.50';
$partial = $service->sync($context, $input);
check($partial['balance_updated'] === false, 'stale balance reported');
check((float) DB::table('wallets')->where('id', 1)->value('balance') === 0.17, 'stale balance did not overwrite wallet');
check(DB::table('sync_account_settings')->count() === 1, 'sync remains configured');
check($service->connections($context)['wallet_ids'] === [1], 'configured wallet appears in connections');
check($service->connections(['user_uuid'=>'someone-else','workspace_id'=>7])['wallet_ids'] === [], 'wallets scoped to user');
check($service->connections(['user_uuid'=>'user-1','workspace_id'=>8])['wallet_ids'] === [], 'wallets scoped to workspace');
$scheduled = $service->runScheduled();
check($scheduled['failed'] === 1 && $scheduled['processed'] === 0, 'scheduled partial sync reports failure');
check($bank->queries[1]['date_from'] === $bank->queries[0]['date_from'] && $bank->queries[1]['continuation_key'] === 'next', 'pagination');
try { $service->sync(['user_uuid'=>'someone-else','workspace_id'=>7],$input); throw new RuntimeException('cross-user connection accepted'); } catch (InvalidArgumentException $e) {}
echo "Eligibility, consent, replay, encryption, pagination, sync and scope checks passed\n";

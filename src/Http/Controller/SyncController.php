<?php
namespace Budgetcontrol\Sync\Http\Controller;

use Budgetcontrol\Sync\Service\SyncService;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;

final class SyncController
{
    public function scheduled(Request $request, Response $response): Response
    {
        $secret = (string) env('SYNC_JOB_SECRET', '');
        if ($secret === '' || !hash_equals($secret, $request->getHeaderLine('X-Sync-Job-Secret'))) {
            return response(['error' => 'Forbidden'], 403);
        }
        $result = (new SyncService())->runScheduled();
        return response($result, $result['failed'] ? 503 : 200);
    }

    public function monitor(Request $request, Response $response): Response
    {
        return response(['success' => true, 'message' => 'Sync service is running']);
    }

    public function handle(Request $request, Response $response, array $args): Response
    {
        $body = $request->getParsedBody() ?: [];
        $identity = $body['identity'] ?? [];
        $eligible = strtolower((string)($identity['email'] ?? '')) === 'marco.defelice890@gmail.com';
        if (($args['action'] ?? '') === 'eligibility') return response(['eligible' => $eligible]);
        if (!$eligible) return response(['error' => 'Open Banking access is not enabled'], 403);
        if (empty($identity['user_uuid']) || empty($identity['workspace_id']) || !is_numeric($identity['workspace_id'])) return response(['error' => 'Invalid authenticated context'], 403);
        $service = new SyncService();
        $data = $body['data'] ?? [];
        $result = match ($args['action']) {
            'banks' => $service->banks(),
            'start' => $service->start($identity, $data),
            'complete' => $service->complete($identity, $data),
            'connections' => $service->connections($identity),
            'sync' => $service->sync($identity, $data),
            default => throw new \InvalidArgumentException('Not found', 404),
        };
        return response($result);
    }
}

<?php
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();
$errorMiddleware = $app->addErrorMiddleware(false, true, true, $logger);
$errorMiddleware->setDefaultErrorHandler(function ($request, Throwable $e) use ($logger) {
    // Do not log request bodies, bank data, authorization codes or provider responses.
    $logger->error('Sync request failed', ['type' => get_class($e)]);
    $status = in_array($e->getCode(), [400, 403, 404, 409, 422, 503], true) ? $e->getCode() : 500;
    return response(['error' => $status === 500 ? 'Synchronization failed' : $e->getMessage()], $status);
});

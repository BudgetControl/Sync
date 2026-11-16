<?php
$app->get('/monitor', \Budgetcontrol\Sync\Http\Controller\SyncController::class . ':monitor');
$app->post('/internal/scheduled-sync', \Budgetcontrol\Sync\Http\Controller\SyncController::class . ':scheduled');
$app->post('/v1/{action}', \Budgetcontrol\Sync\Http\Controller\SyncController::class . ':handle');

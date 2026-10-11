<?php

declare(strict_types=1);

use App\Http\Api;
use App\Http\ApiError;
use App\Http\Response;

require __DIR__ . '/../vendor/autoload.php';

try {
    $api = Api::boot(dirname(__DIR__));
} catch (Throwable $e) {
    error_log((string) $e);
    Response::error(new ApiError(500, 'internal_error', 'Service failed to start.'))->send();

    return;
}

$api->handle($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], (string) file_get_contents('php://input'))->send();

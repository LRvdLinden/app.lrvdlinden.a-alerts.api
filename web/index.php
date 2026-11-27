<?php
// A-Alerts API (api.a-alerts.nl): the own server (PHP + MariaDB). Every route is answered here; nothing goes to Cloudflare.
declare(strict_types=1);
// PHP and Apache would add "charset=UTF-8" to text answers; the answer must stay exactly as the real API gives it.
ini_set('default_charset', '');

$uri = $_SERVER['REQUEST_URI'] ?? '/';
// Not parse_url: it fails on a path such as /v1/waarschuwingen/vnog:2418 (a colon with digits looks like a port).
$path = explode('?', $uri, 2)[0] ?: '/';
// Parallel private API takes precedence on its explicit routes only.
// Without a valid Bearer token, or before cPanel database setup, it fails closed.
require_once __DIR__.'/_core/private-api.php';
// Every /v1 answer may be read from a web page, also an error (404, 401): like the Worker.
if (str_starts_with($path, '/v1/')) header('Access-Control-Allow-Origin: *');
{
    require_once __DIR__.'/_core/docs.php';
    docsRoute($path);
    require_once __DIR__.'/_core/aa/overdracht.php';
    aaOverdrachtRoute($path);
    require_once __DIR__.'/_core/aa/toegang.php';
    aaToegang($path);
    require_once __DIR__.'/_core/aa/routes.php';
    aaRoute($path);
    require_once __DIR__.'/_core/compat.php';
    require_once __DIR__.'/_core/install.php';
    setupSchemaRoute($path);
    compatRoute($path);
    apiRoute($path);
}
// No route answered: unknown address.
http_response_code(404);
header('content-type: application/json; charset=utf-8');
header('cache-control: no-store');
echo json_encode(['error' => 'Niet gevonden']);

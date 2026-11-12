<?php
// Fake A-Alerts API for the tests: echoes what it received.
$p = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($p === '/v1/etag') { if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"abc"') { http_response_code(304); header('etag: "abc"'); exit; } header('etag: "abc"'); }
if ($p === '/v1/fout') http_response_code(404);
if ($p === '/v1/groot') { header('content-type: application/json'); echo json_encode(['x' => str_repeat('abc ', 5000), 'uri' => $_SERVER['REQUEST_URI']]); exit; }
if ($p === '/v1/traag') sleep(40);
header('content-type: application/json');
header('access-control-allow-origin: *');
header('x-opgehaald: 2026-11-11T20:00:00Z');
echo json_encode(['method' => $_SERVER['REQUEST_METHOD'], 'uri' => $_SERVER['REQUEST_URI'], 'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
  'xff' => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null, 'xfh' => $_SERVER['HTTP_X_FORWARDED_HOST'] ?? null, 'ctype' => $_SERVER['CONTENT_TYPE'] ?? null, 'body' => file_get_contents('php://input')]);

<?php
declare(strict_types=1);
// Set secrets in an untracked PHP config OUTSIDE the public web root, or environment.
// AALERTS_PRIVATE_CONFIG may point to that absolute file.
$private = getenv('AALERTS_PRIVATE_CONFIG');
if ($private && is_file($private)) require_once $private;
function requiredEnv(string $key): string {
  $value = getenv($key);
  if (!is_string($value) || $value === '') throw new RuntimeException("Missing configuration: $key");
  return $value;
}
function database(): PDO {
  static $db = null;
  if ($db instanceof PDO) return $db;
  $db = new PDO(requiredEnv('AALERTS_DB_DSN'), requiredEnv('AALERTS_DB_USER'), requiredEnv('AALERTS_DB_PASS'), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false
  ]);
  return $db;
}
function bearer(): ?string {
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  return preg_match('/^Bearer\s+([^\s]+)$/i', trim($h), $m) ? $m[1] : null;
}
function authenticated(): bool {
  $expected = getenv('AALERTS_READ_TOKEN');
  $supplied = bearer();
  return is_string($expected) && strlen($expected) >= 32 &&
    $supplied !== null && hash_equals($expected, $supplied);
}
function jsonOut(int $status, array $data): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, private');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
  exit;
}
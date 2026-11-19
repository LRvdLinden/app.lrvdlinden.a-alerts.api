<?php
declare(strict_types=1);
// Private shadow environment. Configuration must live OUTSIDE the public document root.
$cfg = getenv('AALERTS_PRIVATE_CONFIG');
if ($cfg && is_file($cfg)) require_once $cfg;
function setting(string $key): string { return (string)(getenv($key) ?: ''); }
function directory(): string {
  $dir=setting('AALERTS_SHADOW_DIR');
  if (!$dir || !is_dir($dir) || !is_writable($dir)) throw new RuntimeException('private storage unavailable');
  return rtrim($dir,'/');
}
function loadSnapshot(string $key): array {
  if (!preg_match('/^[a-z0-9_-]{2,40}$/',$key)) throw new InvalidArgumentException('invalid snapshot');
  $f=directory().'/'.$key.'.json';
  if (!is_file($f)) return ['source'=>$key,'updated_at'=>null,'items'=>[],'error'=>'not_collected'];
  $raw=file_get_contents($f);
  return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}
function saveSnapshot(string $key, array $data): void {
  $dir=directory(); $tmp=tempnam($dir,'.partial-');
  if (!$tmp) throw new RuntimeException('cannot create temporary snapshot');
  chmod($tmp,0600);
  try {
    $data['updated_at']=gmdate('c');
    $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    if(file_put_contents($tmp,$json,LOCK_EX)===false || !rename($tmp,"$dir/$key.json")) throw new RuntimeException('cannot save snapshot');
  } finally {if(is_file($tmp)) unlink($tmp);}
}
function fetchRemote(string $url, string $token=''): array {
  if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension required');
  $parsed=parse_url($url);
  if (($parsed['scheme']??'')!=='https' || empty($parsed['host']) || isset($parsed['user']) || isset($parsed['pass'])) throw new RuntimeException('HTTPS URL required');
  // Hostnames are hardcoded or provided by the operator (never taken from a request).
  $ch=curl_init($url);
  $headers=['Accept: application/json, application/rss+xml, application/xml','User-Agent: A-Alerts-shadow/0.2 (+https://a-alerts.nl)'];
  if($token!=='') $headers[]='Authorization: Bearer '.$token;
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,
    CURLOPT_TIMEOUT=>18,CURLOPT_HTTPHEADER=>$headers,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
    CURLOPT_ENCODING=>'',CURLOPT_MAXREDIRS=>0]);
  $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$err=curl_error($ch);curl_close($ch);
  if (!is_string($body) || $status!==200 || strlen($body)>5000000) throw new RuntimeException('source unavailable: HTTP '.$status.($err?' (network error)':''));
  return ['body'=>$body,'status'=>$status];
}
function jsonPayload(string $body): mixed {return json_decode($body,true,512,JSON_THROW_ON_ERROR);}
function tokenAllowed(): bool {
  $secret=setting('AALERTS_READ_TOKEN');
  $head=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';
  if(strlen($secret)<48 || !preg_match('/^Bearer ([A-Za-z0-9._~-]+)$/',$head,$m))return false;
  return hash_equals($secret,$m[1]);
}
function respond(int $code, array $json): never {
  http_response_code($code);header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: private, no-store');header('Referrer-Policy: no-referrer');
  header('X-Content-Type-Options: nosniff');header('X-Robots-Tag: noindex, nofollow');
  echo json_encode($json,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);exit;
}
function uniqueItems(array $items): array {
  $out=[];foreach($items as $item){if(!is_array($item))continue;
    $id=(string)($item['id']??$item['guid']??$item['url']??hash('sha256',json_encode($item)));
    $out[$id]=$item;
  }return array_values($out);
}

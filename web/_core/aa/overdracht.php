<?php
declare(strict_types=1);
/**
 * One-time transfer of the Worker keys (the secrets of LRvdLinden/p2000-apeldoorn) to the private folder of this
 * server. Only possible while a permit made by the API repository exists (aalerts-private/overdracht-once.php, valid
 * for a short time) and only encrypted to the matching one-time key that never leaves the server. After one success
 * the permit and the key are removed. The values are never shown, logged or returned.
 */
require_once dirname(__DIR__).'/private-api.php';

const AA_OVERDRACHT_NAMEN=['P2000_PAGE_API_URL','VERKEER_URL','VERKEER_TOKEN','APN_TEAM_ID','APN_KEY_ID','APN_P8_CERT','FCM_SERVICE_ACCOUNT',
  'BEHEER_USER','BEHEER_PASSWORD','WEB_WEATHERKIT_TEAM_ID','WEB_WEATHERKIT_KEY_ID','WEB_WEATHERKIT_P8','WEB_MAPKIT_JS_TEAM_ID','WEB_MAPKIT_JS_KEY_ID',
  'WEB_MAPKIT_JS_P8','MAPS_SERVER_API_TEAM_ID','MAPS_SERVER_API_KEY_ID','MAPS_SERVER_API_P8','KNMI_WMS_KEY','HOMEY_WEBHOOK_ID','CLOUDFLARE_API_TOKEN','CLOUDFLARE_ACCOUNT_ID'];

function aaPrivateDir(): ?string {
  foreach([(string)($_SERVER['DOCUMENT_ROOT']??''),(string)realpath(__DIR__)] as $c)if(preg_match('~^(/home[^/]*/[^/]+)(?:/|$)~',$c,$m))return $m[1].'/aalerts-private';
  return null;
}
function aaOverdrachtRoute(string $path): void {
  if($path!=='/v1/beheer/sleutels-overdracht')return;
  $fail=function(int $s,string $e){http_response_code($s);header('Content-Type: application/json');header('Cache-Control: no-store');echo json_encode(['error'=>$e]);exit;};
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')$fail(405,'method_not_allowed');
  $dir=aaPrivateDir(); if(!$dir)$fail(404,'not_found');
  $permit=$dir.'/overdracht-once.php'; $keyFile=$dir.'/overdracht-key.pem';
  if(!is_file($permit)||!is_file($keyFile))$fail(404,'not_found');
  $p=require $permit; if(!is_array($p)||(int)($p['tot']??0)<time()){@unlink($permit);@unlink($keyFile);$fail(410,'permit_expired');}
  $raw=file_get_contents('php://input',false,null,0,200001); if(!is_string($raw)||strlen($raw)>200000)$fail(413,'too_large');
  $d=json_decode($raw,true); if(!is_array($d))$fail(400,'invalid');
  $priv=openssl_pkey_get_private((string)file_get_contents($keyFile)); if(!$priv)$fail(500,'key');
  if(!openssl_private_decrypt((string)base64_decode((string)($d['sleutel']??''),true),$keys,$priv,OPENSSL_PKCS1_OAEP_PADDING)||strlen($keys)!==64)$fail(400,'decrypt_key');
  $enc=substr($keys,0,32); $mac=substr($keys,32,32);
  $iv=(string)base64_decode((string)($d['iv']??''),true); $ct=(string)base64_decode((string)($d['data']??''),true);
  if(!hash_equals(hash_hmac('sha256',$iv.$ct,$mac),(string)($d['mac']??'')))$fail(400,'mac');
  $plain=openssl_decrypt($ct,'aes-256-cbc',$enc,OPENSSL_RAW_DATA,$iv); if($plain===false)$fail(400,'decrypt');
  $vals=json_decode($plain,true); if(!is_array($vals))$fail(400,'payload');
  $out=[]; foreach(AA_OVERDRACHT_NAMEN as $n){ $v=$vals[$n]??''; if(is_string($v)&&trim($v)!=='')$out[$n]=str_replace("\r",'',$v); }
  $php="<?php\ndeclare(strict_types=1);\n// Keys of the Cloudflare Worker, transferred once from the secrets of LRvdLinden/p2000-apeldoorn.\nreturn ".var_export($out,true).";\n";
  $tmp=$dir.'/worker-secrets.php.tmp';
  if(file_put_contents($tmp,$php,LOCK_EX)===false||!chmod($tmp,0600)||!rename($tmp,$dir.'/worker-secrets.php'))$fail(500,'write');
  @unlink($permit); @unlink($keyFile);
  http_response_code(200); header('Content-Type: application/json'); header('Cache-Control: no-store');
  echo json_encode(['ok'=>true,'namen'=>array_keys($out)]); exit;
}

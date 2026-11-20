<?php
declare(strict_types=1);
/** Firebase Cloud Messaging HTTP v1 for the Android app (fcm.js). Tokens are stored as "fcm:<token>". */
require_once __DIR__.'/base.php';

const AA_FCM_PREFIX='fcm:';
function aaIsFcmToken(string $t): bool { return str_starts_with($t,AA_FCM_PREFIX); }
function aaValidFcmToken(mixed $raw): bool { return preg_match('/^[A-Za-z0-9_:-]{100,400}$/',(string)($raw??''))===1; }
function aaFcmAccount(): ?array {
  $j=setting('FCM_SERVICE_ACCOUNT'); if($j==='')return null; $sa=json_decode($j,true);
  return is_array($sa)&&!empty($sa['client_email'])&&!empty($sa['private_key'])&&!empty($sa['project_id'])?$sa:null;
}
function aaFcmConfigured(): bool { return aaFcmAccount()!==null; }
function aaFcmAccess(array $sa, bool $nieuw=false): string {
  $now=aaNow(); $saved=aaParse(aaKvGet('aa_fcm_access'),null);
  if(!$nieuw&&is_array($saved)&&($saved['exp']??0)-$now>60000)return (string)$saved['token'];
  $iat=intdiv($now,1000); $b=fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');
  $head=$b(json_encode(['alg'=>'RS256','typ'=>'JWT']));
  $claims=$b(json_encode(['iss'=>$sa['client_email'],'scope'=>'https://www.googleapis.com/auth/firebase.messaging','aud'=>'https://oauth2.googleapis.com/token','iat'=>$iat,'exp'=>$iat+3600],JSON_UNESCAPED_SLASHES));
  $key=openssl_pkey_get_private(str_replace('\n',"\n",(string)$sa['private_key'])); if(!$key||!openssl_sign("$head.$claims",$sig,$key,OPENSSL_ALGO_SHA256))throw new RuntimeException('fcm_sign');
  $jwt="$head.$claims.".$b($sig);
  $ch=curl_init('https://oauth2.googleapis.com/token');
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['content-type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS=>'grant_type='.rawurlencode('urn:ietf:params:oauth:grant-type:jwt-bearer').'&assertion='.$jwt]);
  $r=curl_exec($ch); $st=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($st!==200)throw new RuntimeException('FCM OAuth HTTP '.$st);
  $d=json_decode((string)$r,true);
  $memo=['token'=>$d['access_token'],'exp'=>$now+min((int)($d['expires_in']??3600),3600)*1000-5*60000];
  aaKvSet('aa_fcm_access',aaJson($memo));
  return (string)$memo['token'];
}
function aaFcmMessage(array $payload, array $opt=[]): array {
  $aps=$payload['aps']??[]; $urgent=!empty($opt['urgent']);
  $data=['title'=>(string)(($aps['alert']['title']??'')?:'A-Alerts'),'body'=>(string)($aps['alert']['body']??''),'thread'=>(string)($aps['thread-id']??''),'category'=>(string)($aps['category']??''),'urgent'=>$urgent?'1':'0'];
  foreach($payload as $k=>$v){ if($k==='aps'||$v===null)continue; $data[$k]=is_string($v)?$v:(is_bool($v)?($v?'true':'false'):(is_int($v)||is_float($v)?(string)$v:json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))); }
  $android=['priority'=>'HIGH','ttl'=>($urgent?3600:6*3600).'s']; if(!empty($opt['collapseId']))$android['collapse_key']=substr((string)$opt['collapseId'],0,64);
  return ['android'=>$android,'data'=>$data];
}
function aaSendFcm(string $token, array $payload, array $opt=[]): array {
  $sa=aaFcmAccount(); if(!$sa)return ['ok'=>false,'status'=>0,'reason'=>'FCM not configured'];
  try{
    $body=json_encode(['message'=>['token'=>substr($token,strlen(AA_FCM_PREFIX))]+aaFcmMessage($payload,$opt)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ch=curl_init('https://fcm.googleapis.com/v1/projects/'.$sa['project_id'].'/messages:send');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_TIMEOUT=>10,CURLOPT_HTTPHEADER=>['authorization: Bearer '.aaFcmAccess($sa),'content-type: application/json'],CURLOPT_POSTFIELDS=>$body]);
    $r=curl_exec($ch); $st=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if($st===0)return ['ok'=>false,'status'=>0,'reason'=>'network','fcm'=>true];
    $reason=null;
    if($st<200||$st>299){ $e=json_decode((string)$r,true)['error']??[]; foreach($e['details']??[] as $d)if(!empty($d['errorCode'])){$reason=$d['errorCode'];break;} $reason??=($e['status']??null); if($st===401)aaKvSet('aa_fcm_access','null'); }
    return ['ok'=>$st>=200&&$st<300,'status'=>$st,'reason'=>$reason,'fcm'=>true];
  }catch(Throwable $e){ return ['ok'=>false,'status'=>0,'reason'=>$e->getMessage(),'fcm'=>true]; }
}
function aaIsDeadFcm(array $r): bool { return ($r['status']??0)===404||in_array($r['reason']??null,['UNREGISTERED','SENDER_ID_MISMATCH'],true); }

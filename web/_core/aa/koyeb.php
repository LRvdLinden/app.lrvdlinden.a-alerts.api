<?php
declare(strict_types=1);
/**
 * P2000 stream of Koyeb (the AAlertsKoyebListener of the Worker), as a cron job: every minute one PHP process holds the
 * WebSocket for about 63 seconds. Two slots overlap a few seconds, so there is no gap between two runs; the guid and
 * the dedup key keep a message that arrives twice out. Uses its own client identity (KOYEB_SUBPROTOCOL in the
 * private configuration), never the one of the Worker, so the production connection is not disturbed.
 * CLI only.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/meldingen.php';

const AA_KOYEB_LOOPTIJD=63;

function aaKoyebSlot() {
  $dir=sys_get_temp_dir();
  foreach([0,1] as $slot){
    $f=fopen($dir.'/aalerts-koyeb-'.substr(hash('sha256',__DIR__),0,12).'-'.$slot.'.lock','c');
    if($f&&flock($f,LOCK_EX|LOCK_NB))return $f;
  }
  return null;
}
/** Minimal WebSocket client (RFC 6455) over TLS. */
final class AaWebSocket {
  private $s; private string $buf='';
  public function __construct(string $url, string $protocol) {
    $u=parse_url($url); if(($u['scheme']??'')!=='wss'||empty($u['host']))throw new RuntimeException('koyeb_wss_required');
    $host=$u['host']; $path=($u['path']??'')?:'/';
    $ctx=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'SNI_enabled'=>true,'peer_name'=>$host]]);
    $s=@stream_socket_client('tls://'.$host.':443',$no,$err,10,STREAM_CLIENT_CONNECT,$ctx);
    if(!$s)throw new RuntimeException('koyeb_connect_failed');
    stream_set_timeout($s,10);
    $key=base64_encode(random_bytes(16));
    fwrite($s,"GET $path HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\nSec-WebSocket-Protocol: $protocol\r\nUser-Agent: A-Alerts/2.0 (+https://a-alerts.nl)\r\n\r\n");
    $head='';
    while(!str_contains($head,"\r\n\r\n")){ $c=fread($s,1024); if($c===''||$c===false)throw new RuntimeException('koyeb_handshake_closed'); $head.=$c; if(strlen($head)>16384)throw new RuntimeException('koyeb_handshake_large'); }
    [$h,$rest]=explode("\r\n\r\n",$head,2);
    if(!preg_match('#^HTTP/1\.1 101#',$h))throw new RuntimeException('koyeb_handshake_'.substr($h,9,3));
    $accept=base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11',true));
    if(!preg_match('/^Sec-WebSocket-Accept:\s*'.preg_quote($accept,'/').'\s*$/mi',$h))throw new RuntimeException('koyeb_handshake_accept');
    $this->s=$s; $this->buf=$rest; stream_set_blocking($s,false);
  }
  private function need(int $n, float $until): ?string {
    while(strlen($this->buf)<$n){
      if(microtime(true)>$until)return null;
      $r=[$this->s];$w=null;$e=null;
      if(@stream_select($r,$w,$e,0,250000)===false)throw new RuntimeException('koyeb_select');
      if($r){ $c=fread($this->s,65536); if($c===''&&feof($this->s))throw new RuntimeException('koyeb_closed'); if($c!==false)$this->buf.=$c; }
    }
    $out=substr($this->buf,0,$n); $this->buf=substr($this->buf,$n); return $out;
  }
  public function send(int $op, string $data): void {
    $len=strlen($data); $mask=random_bytes(4); $h=chr(0x80|$op);
    if($len<126)$h.=chr(0x80|$len); elseif($len<65536)$h.=chr(0x80|126).pack('n',$len); else $h.=chr(0x80|127).pack('J',$len);
    $m=''; for($i=0;$i<$len;$i++)$m.=$data[$i]^$mask[$i%4];
    stream_set_blocking($this->s,true); fwrite($this->s,$h.$mask.$m); stream_set_blocking($this->s,false);
  }
  /** Next text message, or null when the time is up. Answers pings itself. */
  public function message(float $until): ?string {
    $msg=''; $frag=false;
    while(true){
      $h=$this->need(2,$until); if($h===null)return null;
      $b0=ord($h[0]); $b1=ord($h[1]); $op=$b0&0x0f; $fin=($b0&0x80)!==0; $len=$b1&0x7f;
      if($len===126)$len=unpack('n',(string)$this->need(2,$until+10))[1]; elseif($len===127)$len=unpack('J',(string)$this->need(8,$until+10))[1];
      if($len>8000000)throw new RuntimeException('koyeb_frame_large');
      $mask=($b1&0x80)?(string)$this->need(4,$until+10):'';
      $data=$len?(string)$this->need($len,$until+30):'';
      if($mask!==''){ for($i=0;$i<$len;$i++)$data[$i]=$data[$i]^$mask[$i%4]; }
      if($op===0x9){$this->send(0xA,$data);continue;}
      if($op===0xA)continue;
      if($op===0x8)throw new RuntimeException('koyeb_server_close');
      if($op===0x1||$op===0x2){$msg=$data;$frag=!$fin;}
      elseif($op===0x0&&$frag){$msg.=$data;$frag=!$fin;}
      if(!$frag&&($op===0x1||$op===0x0||$op===0x2))return $msg;
    }
  }
  public function close(): void { try{$this->send(0x8,pack('n',1000));}catch(Throwable){} @fclose($this->s); }
}

function aaKoyebMessage(string $text, bool &$heartbeatDone): int {
  if(strlen($text)>500000)throw new RuntimeException('koyeb_message_large');
  $list=json_decode($text,true);
  if(!is_array($list)||!array_is_list($list))return 0;
  if(!$heartbeatDone){aaKvSet('koyeb_last_message',(string)aaNow());$heartbeatDone=true;}
  $parsed=array_values(array_filter(array_map('aaFromKoyeb',$list)));
  if(!$parsed)return 0;
  $added=aaIngestParsed($parsed);
  if($added)aaKvSet('koyeb_last_added',(string)aaNow());
  return count($added);
}

$lock=aaKoyebSlot(); if(!$lock)exit(0);
$start=microtime(true); $until=$start+AA_KOYEB_LOOPTIJD;
require_once __DIR__.'/koyeb-login.php';
if(!aaKoyebVerbinding()){fwrite(STDERR,"KOYEB_LOGIN_MISSING\n");exit(0);}
try{ aaEnsureSchema(aaDb()); }catch(Throwable $e){ fwrite(STDERR,'SCHEMA '.get_class($e)."\n"); exit(1); }
$n=0; $berichten=0;
while(microtime(true)<$until){
  try{
    $v=aaKoyebVerbinding(); if(!$v)break;
    $ws=new AaWebSocket($v['url'],$v['protocol']);
    aaKvSet('koyeb_login',$v['soort']);
    aaKvSet('koyeb_last_connected',(string)aaNow());
    if(aaKvGet('aa_koyeb_start')===null)aaKvSet('aa_koyeb_start',(string)aaNow());
    $hb=false; $hbAt=microtime(true);
    while(microtime(true)<$until){
      $text=$ws->message($until); if($text===null)break;
      $berichten++;
      // Health no more than twice per minute, as in the Worker.
      if(microtime(true)-$hbAt>30){$hb=false;$hbAt=microtime(true);}
      try{ $voor=$n; $n+=aaKoyebMessage($text,$hb);
        // A new alert: straight on to the Homeys (only when HOMEY_VIA_API is on, see homey.php).
        if($n>$voor){ require_once __DIR__.'/homey.php'; aaNotifyHomey(); }
      }catch(Throwable $e){ error_log('[aa koyeb ingest] '.$e->getMessage()); }
    }
    $ws->close();
  }catch(Throwable $e){
    aaKvSet('koyeb_last_error',(string)aaNow()); aaKvSet('koyeb_last_error_text',substr($e->getMessage(),0,80));
    if(microtime(true)+5<$until)sleep(5); else break;
  }
}
try{ require_once __DIR__.'/homey.php'; aaNotifyHomey(); }catch(Throwable $e){ error_log('[aa homey] '.$e->getMessage()); }
aaKvSet('koyeb_run',aaJson(['at'=>aaNow(),'berichten'=>$berichten,'nieuw'=>$n,'duur_s'=>round(microtime(true)-$start,1)]));
echo "koyeb berichten=$berichten nieuw=$n\n";

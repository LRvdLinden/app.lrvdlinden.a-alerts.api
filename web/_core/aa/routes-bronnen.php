<?php
declare(strict_types=1);
/**
 * GET /v1/inname: how the own intake of the warning and news sources is doing (public, like /v1/status of the Worker):
 * mode (test or active), per source the last result and, in the test mode, how many items equal the Worker's data.
 */
function aaRouteBronnen(string $path): void {
  if($path!=='/v1/inname'||strtoupper($_SERVER['REQUEST_METHOD']??'GET')!=='GET')return;
  require_once __DIR__.'/bronnen-parse.php';
  try{
    $p=aaParse(aaKvGet('aa_bronnen_proef'),null); $a=aaParse(aaKvGet('aa_bronnen'),null);
    $bron=[]; foreach(['nlalert','burgernet','knmi','kind','vnog'] as $b){ $r=aaKvRow("bron_ok:$b"); $f=aaKvRow("bron_fout:$b"); $bron[$b]=['ok'=>$r?aaIso((int)$r['value']):null,'fout'=>$f['value']??null]; }
    aaReply(200,['proef'=>$p,'actief'=>$a,'bronnen'=>$bron,'warnings_status'=>aaParse(aaKvGet('warnings_status'),null),
      'politie'=>aaParse(aaKvGet('nieuws_politie_status'),null),'n112'=>aaParse(aaKvGet('n112_status'),null)],['Cache-Control'=>'no-store']);
  }catch(Throwable $e){ aaReply(503,['error'=>'inname_onbekend']); }
}

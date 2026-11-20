<?php
declare(strict_types=1);
/**
 * Authenticated docs live viewer: POST only, catalog allowlist, read-only GET.
 * No user-supplied URL, hostname, HTTP method or bearer token accepted.
 * The existing READ_TOKEN never appears in HTML, logs, URLs or JS.
 */
function docsLiveEndpoint(array $routes, string $selection, string $recordId='', string $limit='20'):array {
    $selected=null;
    foreach($routes as $item){
        if(($item['method']??'')==='GET' && $item['method'].' '.$item['path']===$selection){
            $selected=$item;
            break;
        }
    }
    if(!$selected)throw new InvalidArgumentException('Onbekend GET-endpoint.');
    $path=(string)$selected['path'];
    if(!str_starts_with($path,'/v1/') || str_starts_with($path,'/v1/beheer/')){
        throw new InvalidArgumentException('Dit endpoint is niet beschikbaar in de live-viewer.');
    }
    if(str_contains($path,'{')){
        if(!preg_match('/^[a-zA-Z0-9_:\-]{1,80}$/D',$recordId)){
            throw new InvalidArgumentException('Vul een geldig meldings-, nieuws- of toestel-ID in (maximaal 80 tekens).');
        }
        // The catalog is fixed by source control; user data can fill placeholders only.
        $path=(string)preg_replace_callback('/\{[^}]+\}/',static fn()=>rawurlencode($recordId),$path);
    }
    if(preg_match('/[^a-zA-Z0-9_:\/%.-]/',$path))throw new InvalidArgumentException('Ongeldig API-pad.');
    $query=[];
    if(isset($selected['query']['limit'])){
        if(!ctype_digit($limit)|| (int)$limit<1 || (int)$limit>100){
            throw new InvalidArgumentException('Limit moet tussen 1 en 100 liggen.');
        }
        $query['limit']=(int)$limit;
    }
    return [$selected,$path.($query?'?'.http_build_query($query):'')];
}
function docsLiveFetch(string $endpoint):array {
    $token=setting('READ_TOKEN');
    if(strlen($token)<48){
        return ['status'=>503,'error'=>'READ_TOKEN is niet beschikbaar in de private serverconfiguratie. Controleer aalerts-private/config.php.'];
    }
    if(!function_exists('curl_init')){
        return ['status'=>503,'error'=>'De PHP cURL-extensie is niet beschikbaar op deze hosting.'];
    }
    $url='https://api.a-alerts.nl'.$endpoint;
    $ch=curl_init($url);
    if($ch===false)return ['status'=>503,'error'=>'De HTTPS-client kon niet worden gestart.'];
    $body='';
    curl_setopt_array($ch,[
        CURLOPT_HTTPGET=>true,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_CONNECTTIMEOUT=>4,
        CURLOPT_TIMEOUT=>12,
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_HTTPHEADER=>[
            'Authorization: Bearer '.$token,
            'Accept: application/json',
            'User-Agent: A-Alerts-Docs-Viewer/1.0',
        ],
        CURLOPT_USERAGENT=>'A-Alerts-Docs-Viewer/1.0',
        CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$body):int {
            // Never download unbounded payloads.
            if(strlen($body)+strlen($chunk)>524288)return 0;
            $body.=$chunk;
            return strlen($chunk);
        },
    ]);
    $ok=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error=curl_errno($ch);
    curl_close($ch);
    if($ok===false)return ['status'=>502,'error'=>$error===23?'Het JSON-resultaat is groter dan 512 KB. Verlaag de limit.':'API niet bereikbaar of HTTPS-aanvraag mislukt.'];
    if($status===0)return ['status'=>502,'error'=>'De API gaf geen HTTP-respons.'];
    $decoded=json_decode($body,true);
    $display=json_last_error()===JSON_ERROR_NONE
       ?json_encode($decoded,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)
       :mb_substr($body,0,100000);
    return ['status'=>$status,'body'=>(string)$display,'path'=>$endpoint];
}

<?php
declare(strict_types=1);
require __DIR__.'/../web/_core/docs-live.php';
$routes=require __DIR__.'/../web/_core/docs-catalog.php';
function check(bool $ok,string $name):void{if(!$ok){fwrite(STDERR,"FAILED: ".$name."\n");exit(1);}}
[$route,$path]=docsLiveEndpoint($routes,'GET /v1/meldingen','','10');
check($path==='/v1/meldingen?limit=10','limited list');
[$route,$path]=docsLiveEndpoint($routes,'GET /v1/helikopters/{id}/spoor','lifeliner3','20');
check($path==='/v1/helikopters/lifeliner3/spoor','aircraft ID');
[$route,$path]=docsLiveEndpoint($routes,'GET /v1/waarschuwingen/{id}','burgernet:abc123','20');
check(str_contains($path,'burgernet%3Aabc123'),'encoded ID');
foreach([
 ['POST /v1/devices','','20'],
 ['GET /v1/beheer/ping','','20'],
 ['GET /v1/meldingen','','9999'],
 ['GET /v1/meldingen/../../etc','','10'],
 ['GET /v1/nieuws/{id}','https://example.com/','20'],
] as [$endpoint,$id,$limit]){
 $blocked=false;
 try{docsLiveEndpoint($routes,$endpoint,$id,$limit);}catch(InvalidArgumentException $e){$blocked=true;}
 check($blocked,'unsafe request blocked: '.$endpoint);
}
echo "PASS: docs live viewer only allows fixed read routes and encoded IDs\n";

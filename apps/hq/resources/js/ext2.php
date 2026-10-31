<?php
$bn=json_decode(file_get_contents('lib/i18n/bn.json'),true);
$keys=array_map(fn($l)=>rtrim($l,"\r\n"),file('keys.tmp'));
foreach(['Connectors','Parcels','Supplier documents','Reel drafts','Paused ad drafts'] as $e) $keys[]=$e;
$m=array_values(array_unique(array_filter($keys,fn($k)=>!isset($bn[$k]))));
sort($m); echo count($m),"\n"; echo implode("\n",$m),"\n";

<?php
require __DIR__.'/../core/components/multipic/model/Gallery.php';
use MultiPic\Gallery;
function check($condition,$message){if(!$condition)throw new RuntimeException($message);}
$g=['type'=>'multipic','version'=>1,'gallery'=>'test','items'=>[
 ['file'=>'a','active'=>true],['file'=>'b','active'=>false],['file'=>'c','active'=>true],['file'=>'d','active'=>true]
]];
$odd=Gallery::select($g,'odd');$even=Gallery::select($g,'even');
check(array_column($odd,'file')===['a','d'],'odd follows visible positions');
check(array_column($even,'file')===['c'],'hidden item excluded before parity');
check(array_column(Gallery::select($g,'odd',1,1),'file')===['d'],'offset follows parity');
check($odd[0]['first'] && $odd[1]['last'],'first/last final selection');
$nested=json_encode([['month'=>'October','corps'=>json_encode([['photos'=>json_encode($g)]])]]);
check(count(Gallery::collect($nested))===1,'nested serialized MIGX discovered');
check(Gallery::folder('multipic//construction/')==='multipic/construction/','folder normalization');
foreach(['../other','a/../b','/','a\\..\\b'] as $bad){try{Gallery::folder($bad);throw new RuntimeException('accepted unsafe folder');}catch(InvalidArgumentException $e){}}
try{Gallery::collect('{"type":"multipic", broken');throw new RuntimeException('accepted malformed JSON');}catch(RuntimeException $e){check(strpos($e->getMessage(),'Повреждены')!==false,'damaged JSON prevents cleanup');}
echo "PASS: filtering, nested MIGX, folder validation, malformed JSON\n";

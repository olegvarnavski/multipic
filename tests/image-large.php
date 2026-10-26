<?php
/** No MODX/DB needed. Process the reported 6000x4000 dimensions with a bounded PHP worker. */
require __DIR__.'/../core/components/multipic/model/Service.php';
define('MODX_CORE_PATH',__DIR__.'/');
class LargeStatement {
 private $sql;
 function __construct($sql){$this->sql=$sql;}
 function execute($args){return true;}
 function fetch($mode){return false;}
 function fetchColumn(){return strpos($this->sql,'GET_LOCK')!==false?1:0;}
}
class LargeModx {
 function getOption($key){return 'modx_';}
 function prepare($sql){return new LargeStatement($sql);}
 function beginTransaction(){}function commit(){}function rollBack(){}
}
$base=sys_get_temp_dir().'/multipic-large-'.bin2hex(random_bytes(5)).'/';mkdir($base);
function removeLargeFixture($dir){foreach(scandir($dir) as $n){if($n==='.'||$n==='..')continue;$p=$dir.'/'.$n;if(is_dir($p))removeLargeFixture($p);else unlink($p);}rmdir($dir);}
try {
 $input=$base.'source.jpg';$im=imagecreatetruecolor(6000,4000);imagejpeg($im,$input,95);imagedestroy($im);unset($im);
 $service=new MultiPic\Service(new LargeModx());
 $cfg=['base'=>$base,'folder'=>'multipic/','base_url'=>'/','max_bytes'=>20971520,'max_pixels'=>24000000,'edge'=>2560,'quality'=>95,'source'=>1,'max'=>30];
 $item=$service->upload($input,'source.jpg',$cfg,str_repeat('a',64),'test');
 if($item['width']!==2560 || $item['height']!==1707)throw new RuntimeException('Large image dimensions');
 echo "PASS: real GD processing of 6000x4000 JPEG under ".ini_get('memory_limit')." memory_limit\n";
}finally{removeLargeFixture(rtrim($base,'/'));}

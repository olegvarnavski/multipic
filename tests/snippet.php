<?php
class modX {const LOG_LEVEL_ERROR=1;}
class SnippetModx {
 public $resource=null; public $logs=[];
 public function getOption($key,$options=null,$default=null){return $key==='core_path'?dirname(__DIR__).'/core/':$default;}
 public function getChunk($name,$data){return $name==='item'?$data['idx'].':'.$data['alt'].';':'['.$data['output'].']';}
 public function log($level,$message){$this->logs[]=$message;}
}
$modx=new SnippetModx();
$g=['type'=>'multipic','version'=>1,'items'=>[['file'=>'x','active'=>true,'url'=>'/x.jpg','title'=>'<script>','alt'=>'[[!Danger]]"'],['file'=>'y','active'=>false],['file'=>'z','active'=>true,'alt'=>'second']]];
$run=function($properties)use($modx){$scriptProperties=$properties;return include __DIR__.'/../core/components/multipic/elements/snippets/multipic.php';};
$r=$run(['value'=>$g,'tpl'=>'item','tplWrapper'=>'wrapper','parity'=>'odd']);
if($r!=='[1:&#91;&#91;!Danger&#93;&#93;&quot;;]')throw new RuntimeException('Escaping failed: '.$r);
$array=$run(['value'=>$g,'output'=>'array']);if(count($array)!==2 || $array[0]['alt']!=='[[!Danger]]"')throw new RuntimeException('Array contract failed');
$r=$run(['value'=>$g,'tpl'=>'@FILE ../../../../../etc/passwd']);if($r!==''||!$modx->logs)throw new RuntimeException('File escape accepted');
echo "PASS: HTML escaping, raw arrays, @FILE traversal rejection\n";

$g['items'][0]['width']='200" data-test="injected';
$g['items'][0]['height']='100[[!Danger]]';
$g['items'][0]['extra']='[[!Danger]]';
class AuditOutputModx extends SnippetModx {
 public function getChunk($name,$data){
  if(isset($data['extra']))throw new RuntimeException('Unexpected placeholder escaped whitelist');
  if(!is_int($data['width']) || !is_int($data['height']))throw new RuntimeException('Dimensions not normalized');
  return $data['width'].'x'.$data['height'];
 }
}
$modx=new AuditOutputModx();$scriptProperties=['value'=>$g,'parity'=>'odd'];
$out=include __DIR__.'/../core/components/multipic/elements/snippets/multipic.php';
if($out!=='200x100')throw new RuntimeException('Unsafe dimensions in output');
echo "PASS: numeric dimensions and output placeholder whitelist\n";

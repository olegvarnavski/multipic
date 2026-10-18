<?php
require __DIR__.'/../core/components/multipic/model/Service.php';
if(!defined('MODX_CORE_PATH'))define('MODX_CORE_PATH','/test/core/');
final class StatementFixture {
 public function execute($args){return true;}
 public function fetchColumn(){return 1;}
}
final class XpdoFixture {
 public $queries=[];
 public function getConnection(){throw new RuntimeException('xPDOConnection must not be treated as PDO');}
 public function getOption($key){return 'modx_';}
 public function prepare($sql){$this->queries[]=$sql;return new StatementFixture();}
}
$modx=new XpdoFixture();$service=new MultiPic\Service($modx);$service->install();
if(count($modx->queries)!==4)throw new RuntimeException('Schema not created through xPDO API');
if($service->locked(function()use($service){return $service->locked(function(){return 'ok';});})!=='ok')throw new RuntimeException('Nested lock failed');
$locks=array_filter($modx->queries,function($sql){return strpos($sql,'GET_LOCK')!==false;});
if(count($locks)!==1)throw new RuntimeException('Nested locks must be reentrant');
echo "PASS: xPDO prepare API and reentrant locks\n";

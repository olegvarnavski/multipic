<?php
if (getenv('MULTIPIC_TEST_SITE')!=='1' || PHP_SAPI!=='cli')throw new RuntimeException('Disposable CLI fixture only');
require $argv[1]; require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx=new modX();$modx->initialize('mgr');
$r=$modx->getObject('modResource',['pagetitle'=>'MultiPic CI']);
if(!$r)throw new RuntimeException('Fixture not found');
echo $r->get('id');

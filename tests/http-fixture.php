<?php
if (getenv('MULTIPIC_TEST_SITE')!=='1' || PHP_SAPI!=='cli')throw new RuntimeException('Disposable CLI fixture only');
require $argv[1]; require_once __DIR__.'/../core/components/multipic/model/Compat.php';
$modx=\MultiPic\Compat::boot(MODX_CORE_PATH);$modx->initialize('mgr');
$r=$modx->getObject(\MultiPic\Compat::name($modx,'modResource'),['pagetitle'=>'MultiPic CI']);
if(!$r)throw new RuntimeException('Fixture not found');
echo $r->get('id');

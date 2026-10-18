<?php
// Copied into the disposable test site only; never shipped with component assets.
if(getenv('MULTIPIC_TEST_SITE')!=='1' || PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
require __DIR__.'/config.core.php';require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx=new modX();$modx->initialize('mgr');
if(!$modx->user->hasSessionContext('mgr')){http_response_code(403);exit;}
$modx->resource=$modx->getObject('modResource',['pagetitle'=>'MultiPic CI']);
$tv=$modx->getObject('modTemplateVar',['name'=>'multipicCiGallery']);
$modx->runProcessor('element/tv/renders/getinputproperties',['type'=>'multipic','tv'=>$tv->get('id')]);
// Model a manager extension releasing PHP session before rendering a TV.
session_write_close();
echo $tv->renderInput($modx->resource);

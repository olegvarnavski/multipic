<?php
// Copied into the disposable test site only; never shipped with component assets.
if(getenv('MULTIPIC_TEST_SITE')!=='1' || PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
require __DIR__.'/config.core.php';require_once MODX_CORE_PATH.'components/multipic/model/Compat.php';
$modx=\MultiPic\Compat::boot(MODX_CORE_PATH);$modx->initialize('mgr');
if(!$modx->user->hasSessionContext('mgr')){http_response_code(403);exit;}
$modx->resource=$modx->getObject(\MultiPic\Compat::name($modx,'modResource'),['pagetitle'=>'MultiPic CI']);
$tv=$modx->getObject(\MultiPic\Compat::name($modx,'modTemplateVar'),['name'=>'multipicCiGallery']);
$propertiesAction=\MultiPic\Compat::modern($modx)?'MODX\\Revolution\\Processors\\Element\\TemplateVar\\Renders\\GetInputProperties':'element/tv/renders/getinputproperties';
$modx->runProcessor($propertiesAction,['type'=>'multipic','tv'=>$tv->get('id')]);
// Model a manager extension releasing PHP session before rendering a TV.
session_write_close();
echo $tv->renderInput($modx->resource);

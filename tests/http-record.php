<?php
// Return the fixture resource data used by the authenticated save request.
if(getenv('MULTIPIC_TEST_SITE')!=='1' || PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
require __DIR__.'/config.core.php';
require_once MODX_CORE_PATH.'components/multipic/model/Compat.php';
$modx=\MultiPic\Compat::boot(MODX_CORE_PATH);$modx->initialize('mgr');
if(!$modx->user->hasSessionContext('mgr')){http_response_code(403);exit;}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$resource=$id?$modx->getObject(\MultiPic\Compat::name($modx,'modResource'),$id):null;
if(!$resource || !$resource->checkPolicy('save')){http_response_code(404);exit;}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($resource->toArray(),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);

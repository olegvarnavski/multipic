<?php
// Establish a real manager session for the disposable HTTP test site.
if(getenv('MULTIPIC_TEST_SITE')!=='1' || PHP_SAPI!=='cli-server'){http_response_code(404);exit;}
require __DIR__.'/config.core.php';
require_once MODX_CORE_PATH.'components/multipic/model/Compat.php';
$modx=\MultiPic\Compat::boot(MODX_CORE_PATH);$modx->initialize('mgr');
$user=$modx->getObject(\MultiPic\Compat::name($modx,'modUser'),['username'=>'multipic-ci']);
if(!$user || !$user->passwordMatches('multipic-ci-only-admin')){http_response_code(401);echo json_encode(['success'=>false,'message'=>'CI manager credentials are invalid']);exit;}
$user->addSessionContext('mgr');
$_SESSION['modx.mgr.session.cookie.lifetime']=0;
$modx->user=null;
$managerUser=$modx->getUser('mgr',true);
if(!$managerUser || !$managerUser->hasSessionContext('mgr')){http_response_code(401);echo json_encode(['success'=>false,'message'=>'Could not establish a manager session']);exit;}
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success'=>true,'token'=>$managerUser->getUserToken('mgr')]);

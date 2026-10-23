<?php
if(getenv('MULTIPIC_TEST_SITE')!=='1')throw new RuntimeException('Disposable CI site required');
require $argv[1];require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx=new modX();$modx->initialize('mgr');$modx->user=$modx->getObject('modUser',['username'=>'multipic-ci']);
function runSeedProcessor($action,$data=[]){global $modx;$r=$modx->runProcessor($action,$data);if($r->isError())throw new RuntimeException(json_encode($r->getResponse()));}
$old='multipic-0.1.3-alpha';copy(__DIR__.'/../dist/'.$old.'.transport.zip',MODX_CORE_PATH.'packages/'.$old.'.transport.zip');
runSeedProcessor('workspace/packages/scanlocal');runSeedProcessor('workspace/packages/install',['signature'=>$old]);
$setting=$modx->getObject('modSystemSetting','multipic.quality');$setting->set('value',93);$setting->save();
$chunk=$modx->getObject('modChunk',['name'=>'multipicWrapper']);$chunk->setContent('<div class="upgrade-preserved">[[+output]]</div>');$chunk->save();
$tv=$modx->newObject('modTemplateVar');$tv->fromArray(['name'=>'multipicUpgradeGallery','type'=>'multipic','input_properties'=>['folder'=>'multipic/upgrade/']]);$tv->save();
$r=$modx->newObject('modDocument');$r->fromArray(['pagetitle'=>'MultiPic upgrade preserved','context_key'=>'web']);$r->save();
require_once MODX_CORE_PATH.'components/multipic/model/Service.php';$svc=new MultiPic\Service($modx);$cfg=$svc->config($tv,'web');
$token=$svc->createEditor((int)$tv->get('id'),(int)$r->get('id'),'web');
$temp=tempnam(sys_get_temp_dir(),'mp-upgrade-');$im=imagecreatetruecolor(13,17);imagepng($im,$temp);imagedestroy($im);
$item=$svc->upload($temp,'old.png',$cfg,$token,'upgrade');unlink($temp);
$r->setTVValue('multipicUpgradeGallery',json_encode(['type'=>'multipic','version'=>1,'tv'=>(int)$tv->get('id'),'items'=>[$item]]));$svc->syncResource((int)$r->get('id'));
echo "PASS: seeded real 0.1.3 gallery, files, settings and custom chunk\n";

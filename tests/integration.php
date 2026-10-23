<?php
/** Destructive checks ONLY on a disposable CI site. */
if(getenv('MULTIPIC_TEST_SITE')!=='1')throw new RuntimeException('Set MULTIPIC_TEST_SITE=1 only on a disposable site');
require $argv[1];
require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx=new modX();$modx->initialize('mgr');$modx->user=$modx->getObject('modUser',['username'=>'multipic-ci']);
if(!$modx->user || !$modx->user->get('sudo'))throw new RuntimeException('CI admin not found');
function expect($condition,$message){if(!$condition)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;}
function processor($action,$params=[]){global $modx;$r=$modx->runProcessor($action,$params);if($r->isError())throw new RuntimeException($action.': '.json_encode($r->getResponse()));return $r->getObject();}
$package='multipic-0.1.4-alpha';
copy(__DIR__.'/../dist/'.$package.'.transport.zip',MODX_CORE_PATH.'packages/'.$package.'.transport.zip');
processor('workspace/packages/scanlocal');processor('workspace/packages/install',['signature'=>$package]);
expect((bool)$modx->getObject('modSnippet',['name'=>'MultiPic']),'transport install creates snippet');
expect((bool)$modx->getObject('modChunk',['name'=>'multipicExampleFenom']),'examples installed as admin chunks');
$modx->cacheManager->refresh();$modx->setOption('multipic.folder','multipic/ci/');
require_once MODX_CORE_PATH.'components/multipic/model/Service.php';
$service=new MultiPic\Service($modx);
$service->install();$service->install();
expect((int)$modx->getObject('modSystemSetting','multipic.quality')->get('value')===93,'upgrade preserves user setting');
expect(strpos($modx->getObject('modChunk',['name'=>'multipicWrapper'])->getContent(),'upgrade-preserved')!==false,'upgrade preserves custom chunk');
$oldResource=$modx->getObject('modResource',['pagetitle'=>'MultiPic upgrade preserved']);
$oldGallery=MultiPic\Gallery::decode($oldResource->getTVValue('multipicUpgradeGallery'));
expect(is_file($service->file($oldGallery['items'][0]['file'])['path']),'upgrade preserves gallery JSON and physical file');
expect($service->integrity()['issues']===[],'read-only integrity accepts existing storage');
$tv=$modx->newObject('modTemplateVar');$tv->fromArray(['name'=>'multipicCiGallery','caption'=>'CI','type'=>'multipic','input_properties'=>['maxFiles'=>4,'folder'=>'multipic/ci/']]);expect($tv->save(),'create TV');
$template=$modx->getObject('modTemplate',[]);expect((bool)$template,'default template exists');
$link=$modx->newObject('modTemplateVarTemplate');$link->fromArray(['tmplvarid'=>$tv->get('id'),'templateid'=>$template->get('id')],'',true,true);expect($link->save(),'assign TV to template');
$source=$modx->newObject('sources.modMediaSourceElement');$source->fromArray(['source'=>1,'object'=>$tv->get('id'),'object_class'=>'modTemplateVar','context_key'=>'web'],'',true,true);expect($source->save(),'assign source to TV');
$resource=$modx->newObject('modDocument');$resource->fromArray(['pagetitle'=>'MultiPic CI','template'=>$template->get('id'),'context_key'=>'web','published'=>1]);expect($resource->save(),'create resource');
$modx->resource=$resource;
$cfg=$service->config($tv,'web');$token=$service->createEditor((int)$tv->get('id'),(int)$resource->get('id'),'web');
expect((bool)$service->editor($token),'editor capability persisted independently of PHP session');
$auth=$modx->user->getUserToken('mgr');$_SESSION['modx.mgr.user.token']='different-manager-session';
expect($service->editor($token)===null,'editor rejects different manager authentication token');
$_SESSION['modx.mgr.user.token']=$auth;
$service->query('UPDATE '.$modx->getOption('table_prefix').'multipic_editors SET touched=? WHERE token=?',[time()-86401,$token]);
expect($service->editor($token)===null,'expired editor capability rejected');
$service->touchEditor($token);
$temp=tempnam(sys_get_temp_dir(),'mp-test-');$image=imagecreatetruecolor(200,100);imagepng($image,$temp);imagedestroy($image);
$limited=$cfg;$limited['allowed_types']='jpg';
try{$service->upload($temp,'test.png',$limited,$token,'test');throw new RuntimeException('Source restriction bypassed');}catch(RuntimeException $e){expect(strpos($e->getMessage(),'запрещён')!==false,'source allowed formats enforced');}
$item=$service->upload($temp,'001.png',$cfg,$token,'Ход строительства');
$again=$service->upload($temp,'other-name.png',$cfg,$token,'Ignored');
expect($item['file']===$again['file'],'same bytes deduplicate');
$g=['type'=>'multipic','version'=>1,'gallery'=>bin2hex(random_bytes(16)),'tv'=>(int)$tv->get('id'),'session'=>$token,'items'=>[$item]];
$post=['tv'.$tv->get('id')=>json_encode($g),'multipic_tokens'=>json_encode([$token])];
$service->validatePosted($post,(int)$resource->get('id'));
expect(true,'server accepts capability and canonical uploaded data');
$bad=$g;$bad['items'][0]['url']='/forged.png';
try{$service->validatePosted(['tv'.$tv->get('id')=>json_encode($bad)],(int)$resource->get('id'));throw new RuntimeException('forged URL accepted');}catch(RuntimeException $e){expect(strpos($e->getMessage(),'служебные')!==false,'server rejects forged canonical URL');}
$bad=$g;$bad['items'][0]['width']=$item['width'].'" data-test="x';
try{$service->validatePosted(['tv'.$tv->get('id')=>json_encode($bad)],(int)$resource->get('id'));throw new RuntimeException('forged width accepted');}catch(RuntimeException $e){expect(strpos($e->getMessage(),'служебные')!==false,'server rejects non-integer dimension injection');}
$resource->setTVValue($tv->get('name'),json_encode($g));$service->syncResource((int)$resource->get('id'));
$nested=json_encode([['month'=>'October','corps'=>json_encode([['photos'=>json_encode($g)]])]]);
expect(count(MultiPic\Gallery::collect($nested))===1,'nested MIGX JSON recognized');
$html=$modx->runSnippet('MultiPic',['value'=>json_encode($g),'tpl'=>'multipicItem','tplWrapper'=>'multipicWrapper']);expect(strpos($html,$item['url'])!==false,'installed snippet/chunks render image');
$copy=processor('resource/duplicate',['id'=>$resource->get('id'),'name'=>'MultiPic copy']);
$copyId=(int)($copy['id']??0);expect($copyId>0,'resource duplicate processor');
$service->syncResource($copyId);
$path=$service->file($item['file'])['path'];
$g['items']=[];$resource->setTVValue($tv->get('name'),json_encode($g));$service->syncResource((int)$resource->get('id'));
$service->query('DELETE FROM '.$modx->getOption('table_prefix').'multipic_leases WHERE token=?',[$token]);
$service->cleanup();expect(is_file($path),'copy retains shared physical file');
$copyResource=$modx->getObject('modResource',$copyId);$copyResource->setTVValue($tv->get('name'),json_encode($g));$service->syncResource($copyId);$service->cleanup();
expect(!is_file($path),'last reference removal deletes main file');
$staleId=str_repeat('b',32);$part='bb/bb/stale-'.$staleId.'.png';
$stalePath=$service->safeDirectory($cfg['base'],$cfg['folder'].'files/bb/bb/').'stale-'.$staleId.'.png';
$staleThumb=$service->safeDirectory($cfg['base'],$cfg['folder'].'thumbs/bb/bb/').'stale-'.$staleId.'.png';
file_put_contents($stalePath,'partial');file_put_contents($staleThumb,'partial');
$service->query('INSERT INTO '.$modx->getOption('table_prefix').'multipic_pending(id,token,path,thumb,created) VALUES(?,?,?,?,?)',[$staleId,str_repeat('f',64),$stalePath,$staleThumb,time()-86401]);
$service->cleanup();expect(!is_file($stalePath) && !is_file($staleThumb),'expired interrupted upload recovered from journal');
unlink($temp);
echo "Integration complete on MODX ".$modx->getVersionData()['full_version'].' / PHP '.PHP_VERSION.PHP_EOL;

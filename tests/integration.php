<?php
/** Destructive checks ONLY on a disposable CI site. */
if(getenv('MULTIPIC_TEST_SITE')!=='1')throw new RuntimeException('Set MULTIPIC_TEST_SITE=1 only on a disposable site');
require $argv[1];
require_once MODX_CORE_PATH.'vendor/autoload.php';
if(class_exists('MODX\\Revolution\\modX'))$modx=new \MODX\Revolution\modX();else{require_once MODX_CORE_PATH.'model/modx/modx.class.php';$modx=new modX();}
$modx->initialize('mgr');$modx->user=$modx->getObject(coreClass('modUser'),['username'=>'multipic-ci']);
if(!$modx->user || !$modx->user->get('sudo'))throw new RuntimeException('CI admin not found');
function expect($condition,$message){if(!$condition)throw new RuntimeException($message);echo 'PASS: '.$message.PHP_EOL;}
function coreClass($name){global $modx;if(!($modx instanceof \MODX\Revolution\modX))return $name;if(strpos($name,'sources.')===0)return 'MODX\\Revolution\\Sources\\'.substr($name,8);return 'MODX\\Revolution\\'.$name;}
function processor($action,$params=[]){global $modx;$r=$modx->runProcessor($action,$params);if($r->isError())throw new RuntimeException($action.': '.json_encode($r->getResponse()));return $r->getObject();}
$package='multipic-0.3.0-alpha';
copy(__DIR__.'/../dist/'.$package.'.transport.zip',MODX_CORE_PATH.'packages/'.$package.'.transport.zip');
processor('workspace/packages/scanlocal');processor('workspace/packages/install',['signature'=>$package]);
expect((bool)$modx->getObject(coreClass('modSnippet'),['name'=>'MultiPic']),'transport install creates snippet');
expect((bool)$modx->getObject(coreClass('modChunk'),['name'=>'multipicExampleFenom']),'examples installed as admin chunks');
$modx->cacheManager->refresh();$modx->setOption('multipic.folder','multipic/ci/');
require_once MODX_CORE_PATH.'components/multipic/model/Service.php';
$service=new MultiPic\Service($modx);
if(class_exists('MODX\\Revolution\\modX'))require __DIR__.'/modern-seed.php';
$service->install();$service->install();
expect(!$modx->getObject(coreClass('modSystemSetting'),'multipic.max_pixels'),'obsolete pixel quota removed on upgrade');
$transportClass=class_exists(\xPDO\Transport\xPDOTransport::class)?\xPDO\Transport\xPDOTransport::class:\xPDOTransport::class;
$object=$modx->newObject(coreClass('modCategory'));$options=[$transportClass::PACKAGE_ACTION=>$transportClass::ACTION_INSTALL];
if(!(include __DIR__.'/../_build/resolver.php'))throw new RuntimeException('Repeat resolver failed');
expect(!$modx->getObject(coreClass('modSystemSetting'),'multipic.max_pixels'),'pixel quota stays removed after repeated resolver');
expect((int)$modx->getObject(coreClass('modSystemSetting'),'multipic.quality')->get('value')===93,'upgrade preserves user setting');
expect(strpos($modx->getObject(coreClass('modChunk'),['name'=>'multipicWrapper'])->getContent(),'upgrade-preserved')!==false,'upgrade preserves custom chunk');
$oldResource=$modx->getObject(coreClass('modResource'),['pagetitle'=>'MultiPic upgrade preserved']);
$oldGallery=MultiPic\Gallery::decode($oldResource->getTVValue('multipicUpgradeGallery'));
expect(is_file($service->file($oldGallery['items'][0]['file'])['path']),'upgrade preserves gallery JSON and physical file');
expect($service->integrity()['issues']===[],'read-only integrity accepts existing storage');
// Upgrade migration must update independent resource copies and serialized nested MIGX values.
$legacyItem=$oldGallery['items'][0];$legacyPath=$service->file($legacyItem['file'])['path'];
$nestedTv=$modx->newObject(coreClass('modTemplateVar'));$nestedTv->fromArray(['name'=>'multipicNestedMigration','type'=>'textarea']);expect($nestedTv->save(),'create nested migration fixture');
$nestedValue=json_encode([['title'=>'Месяц','children'=>json_encode([['gallery'=>json_encode($oldGallery)]])]]);
$oldResource->setTVValue($nestedTv->get('name'),$nestedValue);$service->syncResource((int)$oldResource->get('id'));
$oldCopy=processor('resource/duplicate',['id'=>$oldResource->get('id'),'name'=>'Legacy copy']);$service->syncResource((int)$oldCopy['id']);
expect(count($service->storage()->plan())===1,'migration plan sees legacy file once');
$oldResource->setTVValue($nestedTv->get('name'),'{"type":"multipic","file":"'.$legacyItem['file'].'",broken');
try{$service->storage()->migrate();throw new RuntimeException('Broken JSON migration accepted');}catch(RuntimeException $e){expect(strpos($e->getMessage(),'Повреждены')!==false,'migration rejects malformed nested JSON');}
expect((int)$service->file($legacyItem['file'])['layout']===1,'failed migration rolls registry back');
$oldResource->setTVValue($nestedTv->get('name'),$nestedValue);
expect($service->storage()->migrate()===1,'journal resumes interrupted migration');
$moved=$service->file($legacyItem['file']);
expect((int)$moved['owner_resource']===(int)$oldResource->get('id') && strpos($moved['path'],'/'.$oldResource->get('id').'/')!==false,'legacy file assigned stable resource folder');
expect(is_file($moved['path'])&&is_file($legacyPath),'migration keeps old URL during editor grace period');
$table=$modx->getTableName(coreClass('modTemplateVarResource'));
foreach([(int)$oldResource->get('id'),(int)$oldCopy['id']] as $rid){
 $value=$service->query("SELECT value FROM {$table} WHERE contentid=? AND tmplvarid=?",[$rid,$nestedTv->get('id')])->fetchColumn();
 $galleries=MultiPic\Gallery::collect($value);expect($galleries[0]['items'][0]['url']===$moved['url'],'migration rewrites nested serialized JSON and resource copies');
}
expect($service->storage()->matches($moved,$legacyItem),'open editor legacy URL accepted during grace period');
expect($service->storage()->migrate()===0,'migration repeat is idempotent');
$tv=$modx->newObject(coreClass('modTemplateVar'));$tv->fromArray(['name'=>'multipicCiGallery','caption'=>'CI','type'=>'multipic','input_properties'=>['maxFiles'=>4,'folder'=>'multipic/ci/']]);expect($tv->save(),'create TV');
$template=$modx->getObject(coreClass('modTemplate'),[]);expect((bool)$template,'default template exists');
$link=$modx->newObject(coreClass('modTemplateVarTemplate'));$link->fromArray(['tmplvarid'=>$tv->get('id'),'templateid'=>$template->get('id')],'',true,true);expect($link->save(),'assign TV to template');
$source=$modx->newObject(coreClass('sources.modMediaSourceElement'));$source->fromArray(['source'=>1,'object'=>$tv->get('id'),'object_class'=>coreClass('modTemplateVar'),'context_key'=>'web'],'',true,true);expect($source->save(),'assign source to TV');
$resource=$modx->newObject(coreClass('modDocument'));$resource->fromArray(['pagetitle'=>'MultiPic CI','template'=>$template->get('id'),'context_key'=>'web','published'=>1]);expect($resource->save(),'create resource');
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
$beforePromotion=$item;$service->storage()->promoteResource((int)$resource->get('id'));
$file=$service->file($item['file']);$g=MultiPic\Gallery::decode($service->query("SELECT value FROM {$table} WHERE contentid=? AND tmplvarid=?",[$resource->get('id'),$tv->get('id')])->fetchColumn());$item=$g['items'][0];
expect((int)$file['owner_resource']===(int)$resource->get('id')&&strpos($file['path'],'/'.$resource->get('id').'/')!==false,'unsaved resource staging promoted after first save');
expect($service->storage()->matches($file,$beforePromotion),'staging URL remains acceptable for open editor');
$thumb=$service->thumbnail($file);expect(is_file($thumb)&&getimagesize($thumb)[0]===200,'private thumbnail generated lazily');
unlink($thumb);expect(is_file($service->thumbnail($file)),'deleted thumbnail regenerates');
$otherCfg=$cfg;$otherCfg['folder']='multipic/other/';$reused=$service->import($file['path'],$otherCfg,$token,'test',(int)$resource->get('id'));
expect($reused['file']===$file['id']&&$reused['id']!==$item['id'],'server selection reuses managed master with independent metadata');

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
$copyResource=$modx->getObject(coreClass('modResource'),$copyId);$copyResource->setTVValue($tv->get('name'),json_encode($g));$service->syncResource($copyId);$service->cleanup();
expect(!is_file($path),'last reference removal deletes main file');
$staleId=str_repeat('b',32);$part='bb/bb/stale-'.$staleId.'.png';
$stalePath=$service->safeDirectory($cfg['base'],$cfg['folder'].'files/bb/bb/').'stale-'.$staleId.'.png';
$staleThumb=$service->safeDirectory($cfg['base'],$cfg['folder'].'thumbs/bb/bb/').'stale-'.$staleId.'.png';
file_put_contents($stalePath,'partial');file_put_contents($staleThumb,'partial');
$service->query('INSERT INTO '.$modx->getOption('table_prefix').'multipic_pending(id,token,path,thumb,created) VALUES(?,?,?,?,?)',[$staleId,str_repeat('f',64),$stalePath,$staleThumb,time()-86401]);
$service->cleanup();expect(!is_file($stalePath) && !is_file($staleThumb),'expired interrupted upload recovered from journal');
unlink($temp);
echo "Integration complete on MODX ".$modx->getVersionData()['full_version'].' / PHP '.PHP_VERSION.PHP_EOL;

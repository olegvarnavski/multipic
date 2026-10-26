<?php
if(getenv('MULTIPIC_TEST_SITE')!=='1')throw new RuntimeException('Disposable CI site required');
require $argv[1];if(is_file(MODX_CORE_PATH.'vendor/autoload.php'))require_once MODX_CORE_PATH.'vendor/autoload.php';
if(class_exists('MODX\\Revolution\\modX'))$modx=new \MODX\Revolution\modX();else{require_once MODX_CORE_PATH.'model/modx/modx.class.php';$modx=new modX();}
$modx->initialize('mgr');$modx->user=$modx->getObject(coreClass('modUser'),['username'=>'multipic-ci']);
$tv=$modx->getObject(coreClass('modTemplateVar'),['name'=>'multipicCiGallery']);
$modx->resource=$modx->getObject(coreClass('modResource'),['pagetitle'=>'MultiPic CI']);
function coreClass($name){global $modx;if(!($modx instanceof \MODX\Revolution\modX))return $name;if(strpos($name,'sources.')===0)return 'MODX\\Revolution\\Sources\\'.substr($name,8);return 'MODX\\Revolution\\'.$name;}
if(!$tv || !$modx->resource)throw new RuntimeException('Missing integration fixtures');
$propertiesAction=class_exists('MODX\\Revolution\\modX')?'MODX\\Revolution\\Processors\\Element\\TemplateVar\\Renders\\GetInputProperties':'element/tv/renders/getinputproperties';
$r=$modx->runProcessor($propertiesAction,['type'=>'multipic','tv'=>$tv->get('id')]);
$properties=$r->getResponse();if(is_array($properties))$properties=json_encode($properties);
if(strpos((string)$properties,'inopt_folder')===false)throw new RuntimeException('TV properties renderer not found');
if(strpos((string)$properties,'modx-combo-source')===false || strpos((string)$properties,'inopt_mediaSource')===false)throw new RuntimeException('Existing-source dropdown missing');
$modx->lexicon->load('ru:multipic:default');
foreach(['folder','max_files','max_edge','quality','max_bytes','chunks_path'] as $key) {
    if(!$modx->lexicon->exists('setting_multipic.'.$key,'ru') || !$modx->lexicon->exists('setting_multipic.'.$key.'_desc','ru'))throw new RuntimeException('Russian setting label or help missing: '.$key);
}
require_once MODX_CORE_PATH.'components/multipic/model/Service.php';
$service=new MultiPic\Service($modx);
$props=$tv->get('input_properties');$props['mediaSource']=1;$tv->set('input_properties',$props);
if($service->config($tv,'web')['source']!==1)throw new RuntimeException('Selected source ignored');
$props['mediaSource']=2147483647;$tv->set('input_properties',$props);
try{$service->config($tv,'web');throw new RuntimeException('Deleted source silently accepted');}catch(RuntimeException $e){if($e->getMessage()!=='Не выбран медиаисточник TV')throw $e;}
$props['mediaSource']=0;$tv->set('input_properties',$props);
echo "PASS: installed TV settings, Russian help and source selection\n";
$html=$tv->renderInput($modx->resource);
if(strpos($html,'data-multipic-config')===false || strpos($html,'multipic-error')!==false)throw new RuntimeException('TV input renderer failed: '.$html);
echo "PASS: installed TV input renders gallery and configuration\n";
// MIGX changes the synthetic DOM ID while retaining inputTVid of the original TV.
$rootId=$tv->get('id');$tv->_fieldMeta['id']['phptype']='string';$tv->set('id','migx_7_1');
$html=$tv->getRender(['inputTVid'=>$rootId],'{"type":"multipic","version":1,"items":[]}',$tv->getRenderDirectories('OnTVInputRenderList','input'),'input',null,'multipic');
if(strpos($html,'tvmigx_7_1')===false || strpos($html,'data-multipic-config')===false)throw new RuntimeException('MIGX synthetic TV ID rendering failed: '.$html);
echo "PASS: MIGX inputTVid and synthetic field ID render through installed TV\n";
foreach(['[]','{}','null','""','',json_encode('{"type":"multipic","version":1,"items":[]}')] as $emptyValue) {
 $html=$tv->getRender(['inputTVid'=>$rootId],$emptyValue,$tv->getRenderDirectories('OnTVInputRenderList','input'),'input',null,'multipic');
 if(strpos($html,'data-multipic-config')===false || strpos($html,'multipic-error')!==false)throw new RuntimeException('MIGX empty/quoted input renderer failed: '.$html);
}
$legacy='[{"image":"old.jpg"}]';$tv->set('value',$legacy);
$html=$tv->getRender(['inputTVid'=>$rootId],$legacy,$tv->getRenderDirectories('OnTVInputRenderList','input'),'input',null,'multipic');
if(strpos($html,'multipic-error')===false || strpos($html,'old.jpg')===false)throw new RuntimeException('Incompatible data was not preserved');
echo "PASS: installed MIGX renderer accepts empty sentinels and quoted JSON; incompatible contents preserved\n";

// Missing files must not disable the entire installed editor.
$oldResource=$modx->getObject(coreClass('modResource'),['pagetitle'=>'MultiPic upgrade preserved']);
$old=MultiPic\Gallery::decode($oldResource->getTVValue('multipicUpgradeGallery'));
$file=$service->file($old['items'][0]['file']);$backup=$file['path'].'.ci-backup';
rename($file['path'],$backup);
try {
 $tv->set('id',$rootId);
 $html=$tv->getRender(['inputTVid'=>$rootId],json_encode($old),$tv->getRenderDirectories('OnTVInputRenderList','input'),'input',null,'multipic');
 if(strpos($html,'data-multipic-config')===false || strpos($html,'_missing')===false || strpos($html,'multipic-error')!==false)throw new RuntimeException('Missing file blocked entire editor');
 echo "PASS: missing file rendered as removable card without disabling gallery\n";
}finally{rename($backup,$file['path']);}

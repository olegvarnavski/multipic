<?php
if(getenv('MULTIPIC_TEST_SITE')!=='1')throw new RuntimeException('Disposable CI site required');
require $argv[1];require_once MODX_CORE_PATH.'model/modx/modx.class.php';
$modx=new modX();$modx->initialize('mgr');$modx->user=$modx->getObject('modUser',['username'=>'multipic-ci']);
$tv=$modx->getObject('modTemplateVar',['name'=>'multipicCiGallery']);
$modx->resource=$modx->getObject('modResource',['pagetitle'=>'MultiPic CI']);
if(!$tv || !$modx->resource)throw new RuntimeException('Missing integration fixtures');
$r=$modx->runProcessor('element/tv/renders/getinputproperties',['type'=>'multipic','tv'=>$tv->get('id')]);
$properties=$r->getResponse();if(is_array($properties))$properties=json_encode($properties);
if(strpos((string)$properties,'inopt_folder')===false)throw new RuntimeException('TV properties renderer not found');
if(strpos((string)$properties,'modx-combo-source')===false || strpos((string)$properties,'inopt_mediaSource')===false)throw new RuntimeException('Existing-source dropdown missing');
$modx->lexicon->load('ru:multipic:default');
foreach(['folder','max_files','max_edge','quality','max_bytes','max_pixels','chunks_path'] as $key) {
    if(!$modx->lexicon->exists('setting_multipic.'.$key) || !$modx->lexicon->exists('setting_multipic.'.$key.'_desc'))throw new RuntimeException('Russian setting label or help missing: '.$key);
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

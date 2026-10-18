<?php
/** Run against a disposable MODX installation, not production. */
if (!isset($modx)) {
    if (empty($argv[1]) || !is_file($argv[1])) { fwrite(STDERR,"Usage: php _build/build.transport.php /path/to/config.core.php\n"); exit(1); }
    require $argv[1];
    if (is_file(MODX_CORE_PATH.'vendor/autoload.php')) { require_once MODX_CORE_PATH.'vendor/autoload.php'; }
    if (class_exists('MODX\\Revolution\\modX')) { $modx=new \MODX\Revolution\modX(); }
    else { require_once MODX_CORE_PATH.'model/modx/modx.class.php'; $modx=new modX(); }
    $modx->initialize('mgr');
}
$root=dirname(__DIR__).'/';
$modern=class_exists('MODX\\Revolution\\modX') && $modx instanceof \MODX\Revolution\modX;
if ($modern) {
    foreach(['modX','modTemplateVarInputRender'] as $name) { if (!class_exists($name)) class_alias('MODX\\Revolution\\'.$name,$name); }
    if (!class_exists('xPDOTransport')) class_alias('xPDO\\Transport\\xPDOTransport','xPDOTransport');
    $builder=new \MODX\Revolution\Transport\modPackageBuilder($modx);
} else { $modx->loadClass('transport.modPackageBuilder','',false,true);$builder=new modPackageBuilder($modx); }
$class=function($name) use ($modern) { return $modern ? 'MODX\\Revolution\\'.$name : $name; };
$builder->createPackage('multipic','0.1.1','alpha');
$namespace=$modx->newObject($class('modNamespace'));$namespace->fromArray(['name'=>'multipic','path'=>'{core_path}components/multipic/','assets_path'=>'{assets_path}components/multipic/'],'',true,true);
$builder->registerNamespace($namespace,false,true);
$settings=['folder'=>'multipic/','max_files'=>30,'max_edge'=>2560,'quality'=>95,'max_bytes'=>20971520,'max_pixels'=>24000000,'chunks_path'=>'{core_path}elements/chunks/'];
foreach($settings as $key=>$value){$o=$modx->newObject($class('modSystemSetting'));$o->fromArray(['key'=>'multipic.'.$key,'value'=>$value,'xtype'=>is_int($value)?'numberfield':'textfield','namespace'=>'multipic','area'=>'multipic'],'',true,true);$builder->putVehicle($builder->createVehicle($o,[xPDOTransport::UNIQUE_KEY=>'key',xPDOTransport::PRESERVE_KEYS=>true,xPDOTransport::UPDATE_OBJECT=>false]));}
$category=$modx->newObject($class('modCategory'));$category->set('category','MultiPic');
$code=function($file) use ($root) {return preg_replace('/^<\?php\s*/','',file_get_contents($root.$file));};
$plugin=$modx->newObject($class('modPlugin'));$plugin->fromArray(['name'=>'MultiPic','description'=>'Тип TV и учёт файлов галерей.','plugincode'=>$code('core/components/multipic/elements/plugins/multipic.php')]);
$events=[];foreach(['OnTVInputRenderList','OnTVInputPropertiesList','OnDocFormPrerender','OnBeforeDocFormSave','OnDocFormSave','OnResourceDuplicate','OnResourceDelete','OnEmptyTrash'] as $event){$e=$modx->newObject($class('modPluginEvent'));$e->fromArray(['event'=>$event,'priority'=>0,'propertyset'=>0],'',true,true);$events[]=$e;}$plugin->addMany($events,'PluginEvents');
$snippet=$modx->newObject($class('modSnippet'));$snippet->fromArray(['name'=>'MultiPic','description'=>'Вывод MultiPic: TV, MIGX, массив, limit/offset, odd/even.','snippet'=>$code('core/components/multipic/elements/snippets/multipic.php')]);
$chunks=[];foreach(require __DIR__.'/examples.php' as $name=>$data){$c=$modx->newObject($class('modChunk'));$c->fromArray(['name'=>$name,'description'=>$data['description'],'snippet'=>$data['content']]);$chunks[]=$c;}
$plugins=[$plugin];$snippets=[$snippet];$category->addMany($plugins,'Plugins');$category->addMany($snippets,'Snippets');$category->addMany($chunks,'Chunks');
$base=[xPDOTransport::UNIQUE_KEY=>'name',xPDOTransport::PRESERVE_KEYS=>false,xPDOTransport::UPDATE_OBJECT=>true];
$pluginAttrs=$base+[xPDOTransport::RELATED_OBJECTS=>true,xPDOTransport::RELATED_OBJECT_ATTRIBUTES=>['PluginEvents'=>[xPDOTransport::UNIQUE_KEY=>['pluginid','event'],xPDOTransport::PRESERVE_KEYS=>true,xPDOTransport::UPDATE_OBJECT=>true]]];
$chunkAttrs=$base;$chunkAttrs[xPDOTransport::UPDATE_OBJECT]=false;
$attrs=[xPDOTransport::UNIQUE_KEY=>'category',xPDOTransport::PRESERVE_KEYS=>false,xPDOTransport::UPDATE_OBJECT=>true,xPDOTransport::RELATED_OBJECTS=>true,xPDOTransport::RELATED_OBJECT_ATTRIBUTES=>['Snippets'=>$base,'Plugins'=>$pluginAttrs,'Chunks'=>$chunkAttrs]];
$vehicle=$builder->createVehicle($category,$attrs);
$vehicle->validate('php',['source'=>__DIR__.'/validator.php']);
$vehicle->resolve('file',['source'=>$root.'core/components/multipic','target'=>"return MODX_CORE_PATH.'components/';"]);
$vehicle->resolve('file',['source'=>$root.'assets/components/multipic','target'=>"return MODX_ASSETS_PATH.'components/';"]);
$vehicle->resolve('php',['source'=>__DIR__.'/resolver.php']);
$builder->putVehicle($vehicle);
$builder->setPackageAttributes(['readme'=>file_get_contents($root.'README.md'),'license'=>'Copyright 2026. MultiPic project.','requires'=>['php'=>'>=7.4','modx'=>'>=2.8.6 <3.0.0']]);
if(!$builder->pack())throw new RuntimeException('Не удалось собрать пакет');
echo $builder->directory.$builder->filename.PHP_EOL;

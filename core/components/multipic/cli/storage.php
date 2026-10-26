<?php
/** Usage: php storage.php /absolute/site/config.core.php --plan|--apply [--limit=100] */
if(PHP_SAPI!=='cli')exit(1);
try{
    if(empty($argv[1])||!is_file($argv[1]))throw new RuntimeException('Укажите полный путь к config.core.php');
    $apply=in_array('--apply',$argv,true);$plan=in_array('--plan',$argv,true);
    if($apply===$plan)throw new RuntimeException('Укажите ровно один режим: --plan или --apply');
    $limit=100;foreach($argv as $arg)if(strpos($arg,'--limit=')===0)$limit=max(1,min(500,(int)substr($arg,8)));
    require $argv[1];require_once MODX_CORE_PATH.'model/modx/modx.class.php';
    $modx=new modX();$modx->initialize('mgr');
    require_once dirname(__DIR__).'/model/Service.php';$service=new \MultiPic\Service($modx);
    if($plan)echo json_encode($service->storage()->plan($limit),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
    else{try{$count=$service->storage()->migrate($limit);echo 'Перенесено файлов: '.$count.PHP_EOL;}finally{$modx->cacheManager->refresh(['resource'=>[]]);}}
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

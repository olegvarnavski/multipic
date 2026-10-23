<?php
if (PHP_SAPI !== 'cli') { http_response_code(403);exit; }
if (empty($argv[1]) || !is_file($argv[1])) { fwrite(STDERR,"Usage: php cleanup.php /absolute/path/config.core.php\n");exit(1); }
require $argv[1];
if (is_file(MODX_CORE_PATH.'vendor/autoload.php'))require_once MODX_CORE_PATH.'vendor/autoload.php';
if(class_exists('MODX\\Revolution\\modX'))$modx=new \MODX\Revolution\modX();
else {require_once MODX_CORE_PATH.'model/modx/modx.class.php';$modx=new modX();}
$modx->initialize('mgr');
require_once __DIR__.'/model/Service.php';
try {
    $service=new \MultiPic\Service($modx);
    $check=in_array('--check',$argv,true);
    $result=$check?$service->integrity(1000,(string)(($argv[3] ?? '')==='--check'?($argv[4] ?? ''):'')):$service->cleanup();
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
    if(!empty($result['failed']) || !empty($result['issues']))exit(2);
}catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}

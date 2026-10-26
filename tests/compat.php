<?php
namespace MODX\Revolution { class modX {} }
namespace {
require __DIR__.'/../core/components/multipic/model/Compat.php';
$old=new stdClass();$modern=new \MODX\Revolution\modX();
foreach(['modResource','modTemplateVar','modChunk','modSystemSetting','modTemplateVarInputRender'] as $name){
 if(\MultiPic\Compat::name($old,$name)!==$name)throw new RuntimeException('MODX 2 class changed');
 if(\MultiPic\Compat::name($modern,$name)!=='MODX\\Revolution\\'.$name)throw new RuntimeException('MODX 3 class mapping');
}
if(\MultiPic\Compat::name($modern,'sources.modMediaSource')!=='MODX\\Revolution\\Sources\\modMediaSource')throw new RuntimeException('Source mapping');
if(class_exists('modX',false))throw new RuntimeException('Unexpected global alias');
echo "PASS: explicit MODX 2/3 class and media source mapping without global aliases\n";
}

<?php
/** Imported legacy layout fixture on a fresh MODX 3 test site, not an old-package install. */
$setting=$modx->getObject(\MultiPic\Compat::name($modx,'modSystemSetting'),'multipic.quality');$setting->set('value',93);$setting->save();
$chunk=$modx->getObject(\MultiPic\Compat::name($modx,'modChunk'),['name'=>'multipicWrapper']);$chunk->setContent('<div class="upgrade-preserved">[[+output]]</div>');$chunk->save();
$tv=$modx->newObject(\MultiPic\Compat::name($modx,'modTemplateVar'));$tv->fromArray(['name'=>'multipicUpgradeGallery','type'=>'multipic','input_properties'=>['folder'=>'multipic/upgrade/']]);$tv->save();
$r=$modx->newObject(\MultiPic\Compat::name($modx,'modDocument'));$r->fromArray(['pagetitle'=>'MultiPic upgrade preserved','context_key'=>'web']);$r->save();
$cfg=$service->config($tv,'web');$token=$service->createEditor((int)$tv->get('id'),(int)$r->get('id'),'web');
$temp=tempnam(sys_get_temp_dir(),'mp-modern-');$image=imagecreatetruecolor(13,17);imagepng($image,$temp);imagedestroy($image);
$item=$service->upload($temp,'old.png',$cfg,$token,'upgrade',(int)$r->get('id'));unlink($temp);$file=$service->file($item['file']);
$sub=substr($file['id'],0,2).'/'.substr($file['id'],2,2).'/';$name=basename($file['path']);
$main=$service->safeDirectory($cfg['base'],$cfg['folder'].'files/'.$sub).$name;$thumb=$service->safeDirectory($cfg['base'],$cfg['folder'].'thumbs/'.$sub).$name;
rename($file['path'],$main);copy($main,$thumb);
$service->query('UPDATE '.$modx->getOption('table_prefix').'multipic_files SET path=?,thumb=?,url=?,thumb_url=?,layout=1,owner_resource=0,source_id=0,storage_base=NULL,storage_folder=NULL WHERE id=?',[$main,$thumb,$cfg['base_url'].$cfg['folder'].'files/'.$sub.$name,$cfg['base_url'].$cfg['folder'].'thumbs/'.$sub.$name,$file['id']]);
$item=$service->item($service->file($file['id']));$r->setTVValue('multipicUpgradeGallery',json_encode(['type'=>'multipic','version'=>1,'tv'=>(int)$tv->get('id'),'items'=>[$item]]));$service->syncResource((int)$r->get('id'));
expect(!class_exists('modX',false),'MODX 3 runtime has no global legacy class aliases');

<?php
/** CI fixture; credentials only for the disposable MySQL service in check.yml. */
$base=rtrim($argv[1],'/').'/';
$xml=simplexml_load_file($base.'setup/config.dist.new.xml');
$data=['database_server'=>'127.0.0.1','database'=>'multipic_ci','database_user'=>'root','database_password'=>'multipic-ci-only','database_connection_charset'=>'utf8mb4','database_charset'=>'utf8mb4','database_collation'=>'utf8mb4_unicode_ci','cmsadmin'=>'multipic-ci','cmspassword'=>'multipic-ci-only-admin','cmsadminemail'=>'ci@example.invalid','core_path'=>$base.'core/','context_web_path'=>$base,'context_web_url'=>'/','context_mgr_path'=>$base.'manager/','context_mgr_url'=>'/manager/','context_connectors_path'=>$base.'connectors/','context_connectors_url'=>'/connectors/','remove_setup_directory'=>0];
foreach($data as $key=>$value)$xml->$key=$value;
$xml->asXML($base.'setup/config.xml');

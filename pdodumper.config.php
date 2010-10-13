<?php

$config = array(
  'log' => FALSE, // './dumps/PDODumper.log.txt',
  'timeout' => 120,
  
  'db_local' => array(
    'dsn' => 'mysql:dbname=yoga;host=127.0.0.1',
    'user' => 'root',
    'password' => '1231',
    'dump_dir' => './dumps/',
    'filter_exclude_tables' => array('cache_.*'),
    'delete_dump' => TRUE,
  ),
  'db_remote' => array(
    'dsn' => 'mysql:dbname=yoga2;host=127.0.0.1',
  	'user' => 'root',
    'password' => '1231',
    'filter_include_tables' => array('users$'),
    'delete_dump' => TRUE,
  ),
  'ftp' => array(
    'host' => '127.0.0.1',
    'user' => 'nexor',
    'password' => '1231',
    'remote_dump_dir' => '/projects/www/pdodumper/dumps_remote/',
  ),
  'http' => array(
    'remote_url' => 'http://pdodumper.loc/pdodumper_remote.php'
  )
);

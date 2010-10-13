<?php

$config = array(
  'log' => './dumps_remote/PDODumper.log.txt',
  'timeout' => 120,
  'db_remote' => array(
   'dump_dir' => './dumps_remote/',
  ),
);

require_once './pdodumper.lib.php';


class cMainDumperRemoteApp extends cMainDumperApp {
  public $delete_dump = FALSE;
  protected function import() {
    $bImported = parent::import();
    if (  $bImported ) {
      if ( $this->delete_dump ) {
        unlink($this->filename);
      }
      cLog::puttoStdOut(self::RESPONCE_MAGIC . self::RESPONCE_UPLOAD_OK);
    }
  }
  
  protected function export() {
    $filename = parent::export();
    if( $filename ) {
      cLog::puttoStdOut( self::RESPONCE_MAGIC . $filename );
    }
  }
  
  public function run( $action ) {
    switch ( $action ) {
      case 'remote_export':
        $this->export();
        break;
      case 'remote_import':
        $this->import();
        break;
      default:
        throw new ErrorException( t('RPC part of pdodumper, only for internal use') );
    }
  }
}

function main($config) {
  check_ver();
  cLog::init( $config['log'] );
  
  $action = cT::getAction();
  $include_tables = array();
  if ( ($include_tables_str = cT::getSafePost('filter_include_tables')) ) {
    $include_tables = explode(', ', $include_tables_str );
  }
  $remote_app = new cMainDumperRemoteApp(
    cT::getSafePost('dsn'),
    cT::getSafePost('user'),
    cT::getSafePost('password'),
    $config['db_remote']['dump_dir'],
    $include_tables,
    TRUE,
    ($config['db_remote']['dump_dir'] . cT::getSafePost('filename'))
  );
  $remote_app->delete_dump = (bool)cT::getSafePost('delete_dump');
  
  $remote_app->run( $action );
  
  cLog::done();
}

main($config);

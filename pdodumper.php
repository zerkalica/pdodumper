<?php

require_once './pdodumper.config.php';
require_once './pdodumper.lib.php';

class cMainDumperLocalApp extends cMainDumperApp {

  public $remote_dsn;
  public $remote_user;
  public $remote_password;
  public $remote_filter_include_tables;
  public $remote_delete_dump = FALSE;
  public $local_delete_dump = FALSE;
  private $http = NULL;
  private $ftp = NULL;
  private $ftp_dir;
  
  function __construct( $dsn, $user, $password, $dump_dir, $filter, $filter_include, $local_delete_dump,
    $remote_dsn, $remote_user, $remote_password, $remote_filter_include_tables, $remote_delete_dump, 
    $remote_url,
    $ftp_host, $ftp_user, $ftp_password, $ftp_dir, 
    $timeout = 120 ) {
    parent::__construct($dsn, $user, $password, $dump_dir, $filter, $filter_include, '');
    $this->remote_dsn = $remote_dsn;
    $this->remote_user = $remote_user;
    $this->remote_password = $remote_password;
    $this->remote_filter_include_tables = $remote_filter_include_tables;
    
    $this->remote_delete_dump = $remote_delete_dump;
    $this->local_delete_dump = $local_delete_dump;
    
    $this->ftp_dir = $ftp_dir;
    $this->ftp = new cFTP($ftp_host, $ftp_user, $ftp_password, $timeout);
    $this->http = new cCurl($remote_url, $timeout);
  }
  
  /**
   * Makes new ftp connection and uploads localfile to ftp
   * 
   * @param string $local_filename local file pathname
   * @param string $remote_filename remote file pathname
   * @throws Exception Can't upload to ftp
   */
  private function uploadtoFTP( $local_filename, $remote_filename) {
    if ( !file_exists($local_filename) ) {
      throw new ErrorException (cT::t('%filename not exists', array('%filename'=>$local_filename)) );
    }
    cLog::Notice(cT::t("Trying to connect ftp %ftp", array('%ftp' => $this->ftp->getHost())));
    $this->ftp->connect();
    cLog::Notice(cT::t("Trying to ftp upload %file", array('%file' => $local_filename)));
    if ( !$this->ftp->put($local_filename,  $remote_filename ) ) {
      throw new ErrorException (cT::t('Can\'t upload to ftp, due to timeout') );
    }
    if (! $this->ftp->chmod(0666, $remote_filename) ) {
      throw new ErrorException (cT::t('Can\'t chmod %filename', array('%filename'=>$remote_filename)) );
    }
  }
  
  /**
   * Download file from ftp
   * 
   * @param string $remote_filename remote file pathname
   * @param string $local_filename local file pathname
   * @throws Exception Can't download from ftp...
   */
  private function downloadFromFtp( $remote_filename, $local_filename ) {
    cLog::Notice(cT::t("Trying to connect ftp %ftp", array('%ftp' => $this->ftp->getHost())));
    $this->ftp->connect();
    cLog::Notice(cT::t("Trying to download %file", array('%file' => $local_filename)));
    if ( !$this->ftp->get($local_filename, $remote_filename ) ) {
      throw new ErrorException ( cT::t('Can\'t download from ftp') );
    } else if ( $this->remote_delete_dump ) {
      $this->ftp->delete($remote_filename);
    }
  }
  
  /**
   * Make http POST query, get the page and search string 'Done'
   *   
   * @param string $filename filename, which sending to remote script
   * @param string dsn db pdo dsn
   * @param string $user remote database user
   * @param string $password remote database password
   * @throws Exception Can't retreive status for... if 'Done' string not found 
   */
  private function doRemoteImport( $filename, $dsn, $user, $password, $filter_include_tables ) {
    cLog::Notice(cT::t('Query status for remote import %url', array('%url' => $this->http->getUrl())));
    $this->http->setPost( array(
    	'action' => 'remote_import',
    	'filename'=> $filename,
      'dsn' => $dsn,
    	'user'=> $user,
    	'password' => $password,
      'filter_include_tables' => implode(', ', $filter_include_tables),
      'delete_dump' => $this->remote_delete_dump,
    )
    );
    $this->http->connect();
    cLog::Notice(cT::t('Site returns: [%status] \'%msg\'', array('%status'=>$this->http->getHttpStatus(), '%msg' => $this->http)));
    if ( strpos($this->http, self:: RESPONCE_MAGIC . self::RESPONCE_UPLOAD_OK) === FALSE )  {
      throw new ErrorException ( cT::t('Can\'t retreive status for remote import') );
    } else {
      cLog::Notice(cT::t('DONE responce catched, all OK')); 
    }
  }
  
  /**
   * Send request to remote script to run remote db export
   * 
   * @param string $url remote web page url
   * @param string dsn db pdo dsn
   * @param int $timeout http timeout
   * @param string $user remote database user
   * @param string $password remote database password
   * @throws Exception Can't remote export ...
   * @return string filename for download from remote script
   */
  private function doRemoteExport( $dsn, $user, $password ) {
    cLog::Notice(cT::t('Remote export to %url', array('%url' => $this->http->getUrl() )));
    $this->http->setPost(
      array(
      	'action' => 'remote_export',
        'dsn' => $dsn,
        'user' => $user,
        'password' => $password,
        'delete_dump' => $this->remote_delete_dump,
      )
    );
    $this->http->connect();
    cLog::Notice(cT::t('Site returns: [%status] \'%msg\'', array('%status'=>$this->http->getHttpStatus(), '%msg' => $this->http)));
    if ( ($pos = strpos((string) $this->http, self::RESPONCE_MAGIC)) === FALSE )  {
      throw new ErrorException( cT::t('Can\'t remote export on %host', array('%host'=>$this->http->getUrl() )) );
    }
    $filename = substr((string) $this->http, $pos + strlen(self::RESPONCE_MAGIC) );
    $filename = preg_replace('#([\w\d\-_+=\/,.]*).*#', '$1', $filename);
    if ( !$filename ) {
      throw new ErrorException( cT::t('Can\'t find valid filename responce in data'));
    }
    
    cLog::Notice(cT::t('Remote script returns file name %file', array('%file' => $filename) ));
    return $filename;
  }
  
  /**
   * make remote dump, download and return file pathname
   * @return string file pathname 
   */
  public function getRemoteDump() {
    $filename_old = $this->doRemoteExport(
      $this->remote_dsn,
      $this->remote_user,
      $this->remote_password
    );
    $this->downloadFromFtp(
      $this->ftp_dir . $filename_old,
      $this->dump_dir . $filename_old
    );
    return $this->dump_dir . $filename_old;
  }
  
  protected function import() {
    $filename_old = $this->getRemoteDump();
    $this->setImportFilename($filename_old);
    parent::import();
    if ( $this->local_delete_dump ) {
      cLog::Notice(t('Delete %file', array('%file' => $filename_old)));
      unlink($filename_old);
    }
  }
  
  public function uploadAndImport($filename) {
    $local_file = $this->dump_dir . $filename;
    $this->uploadtoFtp(
      $local_file,
      $this->ftp_dir . $filename
    );
    if ( $this->local_delete_dump ) {
      cLog::Notice(cT::t('Delete %file', array('%file' => $local_file)));
      unlink($local_file);
    }
    $this->doRemoteImport($filename,
      $this->remote_dsn,
      $this->remote_user,
      $this->remote_password,
      $this->remote_filter_include_tables
    );
  }
  
  protected function export() {
    $filename = parent::export();
    $this->uploadAndImport($filename);
  }
  
  public function run( $action ) {
    cLog::Notice(cT::t('Running job %action', array('%action' => $action)));
    switch ( $action ) {
      case 'local_import':
        $this->import();
        break;
      case 'local_export':
        $this->export();
        break;
      case 'get_remote_dump':
        $filename_old = $this->getRemoteDump();
        cLog::Notice( cT::t('OK download: %file', array('%file' => $filename_old)) );
        break;
      case 'upload_local_dump':
        $filename = cT::getGet('filename');
        if ( empty($filename) ) {
          cLog::Error('Enter input parameter: filename=filename.sql.gz');
        } else { 
          $this->uploadAndImport($filename);
        }
        break;
      default:
        cLog::Notice(cT::t('No $_GET[\'action\'] specified, what can i do ?'));
        cLog::Notice(cT::t('local_import - make remote dump, download, and import to local database'));
        cLog::Notice(cT::t('local_export - make local dump, upload, and import to remote database'));
        cLog::Notice(cT::t('get_remote_dump - make remote dump, upload'));
        cLog::Notice(cT::t('upload_local_dump - get filename from $_GET[\'filename\'], upload, import to remote database'));
    }
  }
}

function main($config) {
  check_ver();
  cLog::init( $config['log'] );
  $action = cT::getAction();
  $local_app = new cMainDumperLocalApp(
    $config['db_local']['dsn'],
    $config['db_local']['user'],
    $config['db_local']['password'],
    $config['db_local']['dump_dir'],
    $config['db_local']['filter_exclude_tables'],
    FALSE,
    $config['db_local']['delete_dump'],
    $config['db_remote']['dsn'],
    $config['db_remote']['user'],
    $config['db_remote']['password'],
    $config['db_remote']['filter_include_tables'],
    $config['db_remote']['delete_dump'],
    $config['http']['remote_url'],
    $config['ftp']['host'],
    $config['ftp']['user'],
    $config['ftp']['password'],
    $config['ftp']['remote_dump_dir'],
    $config['timeout']
  );
  $local_app->run( $action );
  cLog::done();
}

main($config);

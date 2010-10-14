<?php

/**
 * @name PDO SQL dumper, uploader, downloader and synchronizer
 * @package administration utility
 * @uses curl, ftp, php > 5.3.0
 * @version 0.1b
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */


/**
 * Base static functions
 *
 * @author nexor <nexor@ya.ru>
 * @version $Id$
 *
 */
class cT {
  /**
   * Translate string template
   *
   * @param string $string - string template to translate
   * @param array $params - template parameters
   * @return string - translated string
   */
  static public function t( $string, $params = array() ) {
    global $locale;
    return str_replace(array_keys($params), array_values($params), isset($locale[$string]) ? $locale[$string] : $string);
  }
  static public function getPost( $name ) {
    return isset( $_POST[$name] ) ? $_POST[$name] : NULL; 
  }
  static public function getGet( $name ) {
    return isset( $_GET[$name] ) ? $_GET[$name] : NULL; 
  }
  static public function getSafePost( $name ) {
    //@TODO: safe checks
    return self::getPost($name);
  }
  static public function getAction() {
    return isset($_REQUEST['action']) ? $_REQUEST['action'] : FALSE;
  }
}

/**
 * Error and stdout handler
 *
 * @author nexor <nexor@ya.ru>
 * @version $Id$
 *
 */
class cLog {
  static private $olderrorHandler = NULL;
  static private $oldexceptionHandler = NULL;
  static public $showErrors = TRUE;
  static public $logErrors = TRUE;
  static public $logFilename = '';
  static public $skipClasses = array();
  
  /**
   * Static constructor, sets default error handler and exception handler
   * 
   * @param string $filename log file pathname
   */
  static public function init(  $filename  ) {
    if ( ! self::$olderrorHandler ) {
      self::setlogFilename($filename);
      if ( file_exists($filename) ) { 
        unlink($filename);
      }
      self::$olderrorHandler = set_error_handler(array(__CLASS__, 'errorHandler'));
      self::$oldexceptionHandler = set_exception_handler(array(__CLASS__, 'exceptionHandler'));
    }
  }
  
  /**
   * Put string to stdout or console
   * @param string $string message
   */
  static public function puttoStdOut($string) {
    echo $string;
  }
  
  /**
   * Format log string for html or plain text output
   * 
   * @param string $entry log string
   * @return formatted log string
   */
  static private function format( $entry ) {
    return $entry . "<br/>\n";
  }
  
  /**
   * Returns names for error codes
   * @return array of error_code => name 
   * 
   */
  static private function getErrorMap() {
    static $types;
    if ( !isset($types) ) {
      $types = array(
        E_ERROR => cT::t('Error'),
        E_WARNING => cT::t('Warning'),
        E_PARSE => cT::t('Parse error'),
        E_NOTICE => cT::t('Notice'),
        E_CORE_ERROR => cT::t('Core error'),
        E_CORE_WARNING => cT::t('Core warning'),
        E_COMPILE_ERROR => cT::t('Compile error'),
        E_COMPILE_WARNING => cT::t('Compile warning'),
        E_USER_ERROR => cT::t('User error'),
        E_USER_WARNING => cT::t('User warning'),
        E_USER_NOTICE => cT::t('User notice'),
        E_STRICT => cT::t('Strict warning'),
        E_RECOVERABLE_ERROR => cT::t('Recoverable fatal error')
      );
    }
    return $types;
  }
  
  /**
   * Set skip classes in stack of backtrace
   * 
   * @param array $classes array of classes names
   */
  static public function setSkipClasses( array $classes ) {
    self::$skipClasses = $classes;
  }
  
  /**
   * Returns array of skip classes
   * @return array of skip classes
   * @see self::setSkipClassess
   */
  static public function getSkipClasses() {
    return self::$skipClasses;    
  }
  
  /**
   * Returns templates for formatting log message
   * @return array key = > template
   */
  static private function getErrorTemplates() {
    return array(
    	'full' => '%type: %message in %filename on line %line',
    	'brief' => '[%type, %filename:%line] %message'
    );
  }
  
  /**
   * 
   * @param int $errno
   * @return bool is recoverable error or not
   */
  static private function isRecovarable( $errno ) {
    static $recoverable = array(
      E_WARNING,
      E_COMPILE_WARNING,
      E_CORE_WARNING,
      E_NOTICE,
      E_RECOVERABLE_ERROR,
      E_DEPRECATED,
      E_STRICT,
      E_USER_NOTICE,
      E_USER_WARNING,
      E_USER_DEPRECATED
    );
    return in_array($errno, $recoverable);
  }

  /**
   * Error handler points to exception hanlder
   * 
   * @param int $errno
   * @param string $message
   * @param string $filename
   * @param int $line
   * @param array $context
   * @throws ErrorException if not recoverable error
   */
  static public function errorHandler( $errno, $message, $filename, $line, $context) {
    $exception = new ErrorException($message, 0, $errno, $filename, $line);
    if ( self::isRecovarable($errno) ) {
      return self::exceptionHandler( $exception ); 
    } else {
      throw $exception;
    }    
  }
  
  /**
   * Standart exception handler
   * 
   * @param Exception $exception
   */
  static public function exceptionHandler( Exception $exception ) {
  // If the @ error suppression operator was used, error_reporting will have
    // been temporarily set to 0.
    if (error_reporting() == 0) {
      return;
    }
    $skip = array(__CLASS__) + self::getSkipClasses();
    $types = self::getErrorMap();
    $templates = self::getErrorTemplates();
    
    
    if ( method_exists($exception, 'getSeverity') ) { // Handle error exception
      $errno = $exception->getSeverity();
    } else {
      $errno = E_USER_ERROR;
    }
    
    $message = $exception->getMessage();
    
    $backtrace = $exception->getTrace();
    $backtrace = array_reverse( $backtrace );
    $line = $exception->getLine();
    $filename = $exception->getFile();
    foreach ($backtrace as $index => $function) {
      if ( isset($function['class']) && in_array($function['class'], $skip) ) {
        if ( isset($backtrace[$index]['line']) && (isset( $backtrace[$index]['file'])) ) {
          $line = $backtrace[$index]['line'];
          $filename = $backtrace[$index]['file'];
        }
        break;
      }
    }
    $filename = basename($filename);
    
    $entry = array();
    foreach ( $templates as $name => $template ) {
      $entry[$name] = cT::t($template, array(
        '%type' => $types[$errno],
        '%message' => $message,
        '%filename' => $filename,
        '%line' => $line
      ));
    }
    
    if ( self::getShowErrors() ) {
      self::displayError($entry['brief'], $errno);
    }
    if ( self::getLogErrors() ) {
      self::putLogError($entry['full'], $errno);
    }
  }
  
  /**
   * Display error to std out
   * 
   * @param string $entry
   * @param int $errno
   */
  static public function displayError($entry, $errno) {
    self::puttoStdOut( self::format($entry) );
  }
  
  /**
   * Add error string to file
   * 
   * @param string $entry
   * @param int $errno
   */
  static public function putLogError($entry, $errno) {
    if (self::$logFilename) { 
      $sth = new cStream(self::$logFilename, 'ab');
      $sth->put( self::format($entry) );
    }
  }
  
  /**
   * Sets flag for show error
   * 
   * @return bool show error state 
   */
  static public function getShowErrors() {
    return self::$showErrors;
  }
  
  /**
   * Get show error flag state
   *  
   * @return bool log errors
   */
  static public function getLogErrors() {
    return self::$logErrors;
  }
  
  /**
   * Sets flag for show error
   * 
   * @param bool $use 
   */
  static public function setShowErrors( $use ) {
    self::$showErrors = $use;
  }
  
  /**
   * Set log error flag state
   * 
   * @param bool $use 
   */
  static public function setLogErrors( $use ) {
    self::$logErrors = $use;
  }
  
  /**
   * Get log filename
   * 
   * @return string filename
   */
  static public function getlogFilename() {
    return self::$logFilename;
  }
  
  /**
   * Set log filename
   * 
   * @param string $filename
   */
  static public function setlogFilename( $filename ) {
    self::$logFilename = $filename;
  }
    
  /**
   * make trigger_error
   * @param string $string
   * @param int $error_type
   */
  static private function put($string, $error_type ) {
    return trigger_error($string , $error_type);
  }
  /**
   * Make user notice
   * @param string $string notice message
   */
  static public function Notice($string) {
    return self::put($string, E_USER_NOTICE);
  }
  /**
   * Make user warning
   * @param string $string warning message
   */
  static public function Warning($string) {
    return self::put($string, E_USER_WARNING);
  }
  
  /**
   * Make user error and stop
   * @param string $string error message
   */
  static public function Error($string) {
    return self::put($string, E_USER_ERROR);
  }
  
  /**
   * 
   * Static destructor
   */
  static public function done() {
    if ( self::$olderrorHandler ) {
      set_error_handler(self::$olderrorHandler);
      set_exception_handler(self::$oldexceptionHandler);
      self::$oldexceptionHandler = self::$olderrorHandler = NULL;
    }
  }
}

/**
 * session settings manager
 * @author nexor <nexor@ya.ru>
 * @version $Id$
 *
 */
class cSession {
  /**
   *
   * class name
   * @var string class name
   */
  public $class;
  public function __construct( $class = __CLASS__ ) {
    if ( ! session_id() ) {
      session_start();
    }
    $this->class = $class;
  }
  public function & get( $class = NULL ) {
    if ( !$class ) {
      $class = $this->class;
    }
    return $_SESSION[$class];
  }
}

/**
 *
 * Stream manipulation class
 * @author nexor <nexor@ya.ru>
 * @version $Id$
 */
class cStream {
  private $handle = NULL;
  public function __construct( $filename = '', $mode = '' ) {
    if ($filename && $mode) {
        $this->open($filename, $mode);
    }
  }

  /**
   *
   * Open file
   * @param string $filename
   * @param string $mode standart fopen mode
   */
  public function open( $filename, $mode ) {
    $this->handle = fopen($filename, $mode);
    if ( !$this->handle ) {
      throw new ErrorException(cT::t('Can\'t open file %filename [%mode]', array('%filename'=>$filename, '%mode'=>$mode)));
    }
  }

  /**
   *
   * reads $len bytes from stream and return in string
   * @param int $len
   * @return string buffer
   */
  public function get( $len ) {
    return fread($this->handle, $len);
  }

  /**
   *
   * writes bytes into stream
   * @param string $data data buffer
   * @return bytes writed
   */
  public function put( $data ) {
    return fwrite($this->handle, $data);
  }

  /**
   *
   * Seek to position in stream
   * @param int $pos stream position
   * @return int new offset
   */
  public function seek( $pos ) {
    return fseek($this->handle, $pos);
  }
  public function __destruct() {
    if ($this->handle) {
      fclose($this->handle);
    }
  }
}

/**
 * Ftp class
 *
 * @author nexor <nexor@ya.ru>
 * @version $Id$
 *
 */
class cFtp {
  private $host;
  private $user;
  private $password;
  private $port = 21;
  private $timeout = 90;
  public $passive = FALSE;
  /**
   * @var bool use ssl connection
   */
  public $ssl 	 = FALSE;

  public $mode;

  private $conn_id = NULL;
  private  $system_type = '';

  public function __construct($host, $user, $password, $timeout = 120, $port = 21, $passive = FALSE, $ssl = FALSE, $mode = FTP_BINARY) {
    $this->host = $host;
    $this->user = $user;
    $this->password = $password;
    $this->timeout = $timeout;
    $this->port = $port;
    $this->passive = $passive;
    $this->ssl = $ssl;
    $this->setMode($mode);
  }
  
  public function getHost() {
    return $this->host;
  }
  
  public function setHost( $host ) {
    $this->host = $host;
  }
  
  /**
   * sets mode FTP_BINARY or FTP_ASCII
   *
   * @param const $mode
   */
  public function setMode( $mode = FTP_BINARY ) {
    $this->mode = $mode;
  }

  /**
   * get current ftp mode
   *
   * @return const ftp mode
   */
  public function getMode( ) {
    return $this->mode;
  }

  /**
   * Connect to ftp server
   *
   * @return bool - TRUE, if connected
   */
  public function connect() {
    if ($this->ssl == FALSE) {
      $this->conn_id = ftp_connect($this->host, $this->port);
    } else {
      if ( function_exists('ftp_ssl_connect') ) {
        $this->conn_id = ftp_ssl_connect($this->host, $this->port);
      } else {
        throw new ErrorException(cT::t('Function ftp_ssl_connect not defined, but ssl used'));
      }
    }
    if ( !$this->conn_id ) {
      throw new ErrorException(cT::t('ftp_connect return NULL'));
    }
    
    
    if ( ftp_login($this->conn_id, $this->user, $this->password) ) {
      ftp_set_option($this->conn_id, FTP_TIMEOUT_SEC, $this->timeout);
      ftp_pasv($this->conn_id, $this->passive);
      $this->system_type = ftp_systype($this->conn_id);
      return TRUE;
    } else {
      return FALSE;
    }
  }

  /**
   * put file to remote ftp
   *
   * @param string $local_file_path local file path
   * @param string $remote_file_path remote file path
   * @return bool - TRUE, if done
   */
  public function put($local_file_path, $remote_file_path) {
    $state = ftp_put($this->conn_id, $remote_file_path, $local_file_path, $this->mode);
    return $state;
  }

  /**
   * get file from remote ftp
   *
   * @param string $local_file_path local file path
   * @param string $remote_file_path remote file path
   * @return bool - TRUE, if done
   */
  public function get($local_file_path, $remote_file_path) {
    return ftp_get($this->conn_id, $local_file_path, $remote_file_path, $this->mode);
  }

  /**
   * change permissions on remote file
   *
   * @param int $permissions permission code
   * @param string $remote_filename remote filename
   * @throws Exception, trows if permissions is not octal number
   * @return bool - TRUE, if done
   */
  public function chmod($permissions, $remote_filename) {
    if ($this->is_octal($permissions)) {
      return ftp_chmod($this->conn_id, $permissions, $remote_filename);
    } else {
      throw new ErrorException(cT::t('$permissions must be an octal number', array('$permissions' => $permissions) ));
    }
  }

  /**
   * cnage directory on remote fs
   *
   * @param string $directory
   * @return bool - TRUE, if done
   */
  public function chdir($directory) {
    return ftp_chdir($this->conn_id, $directory);
  }

  /**
   * delete remote file
   *
   * @param string $remote_file_path remote filename
   * @return bool - TRUE, if done
   */
  public function delete($remote_file_path) {
    return ftp_delete($this->conn_id, $remote_file_path);
  }

  public function make_dir($directory) {
    return ftp_mkdir($this->conn_id, $directory);
  }

  public function rename($old_name, $new_name) {
    return ftp_rename($this->conn_id, $old_name, $new_name);
  }

  public function remove_dir($directory) {
    return ftp_rmdir($this->conn_id, $directory);
  }

  public function dir_list($directory) {
    return ftp_nlist($this->conn_id, $directory);
  }

  public function cdup() {
    ftp_cdup($this->conn_id);
  }

  public function current_dir() {
    return ftp_pwd($this->conn_id);
  }

  private function is_octal($i) {
    return TRUE;
    //@TODO: works not correct on int like 0666
    // return decoct(octdec($i)) == $i;
  }

  public function __destruct() {
    if ($this->conn_id) {
      ftp_close($this->conn_id);
    }
  }
}

/**
 * curl library manipulation class
 *
 * @author artem at zabsoft dot co dot in
 * @filesource http://php.net/manual/en/book.curl.php
 *
 */
class cCurl {
  protected $useragent = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1)';
  public $url;
  protected $followlocation;
  protected $timeout;
  protected $maxRedirects;
  protected $cookieFileLocation = '';
  protected $post;
  protected $postFields;
  protected $referer ="http://www.google.com";

  protected $webpage;
  protected $includeHeader;
  protected $noBody;
  protected $status;
  protected $binaryTransfer;
  public    $authentication = FALSE;
  public    $auth_name      = '';
  public    $auth_pass      = '';

  public function __construct($url, $followlocation = TRUE, $timeOut = 90, $maxRedirecs = 4, $binaryTransfer = FALSE, $includeHeader = FALSE, $noBody = FALSE) {
    $this->setUrl($url);
    $this->followlocation = $followlocation;
    $this->timeout = $timeOut;
    $this->maxRedirects = $maxRedirecs;
    $this->noBody = $noBody;
    $this->includeHeader = $includeHeader;
    $this->binaryTransfer = $binaryTransfer;
  }
  
  /**
   * Sets use flag for using authentication
   *
   * @param bool $use
   */
  public function useAuth($use) {
    $this->authentication = $use;
  }

  /**
   *
   * Sets user name for http connection
   * @param string $name login
   */
  public function setName($name) {
    $this->auth_name = $name;
  }

  /**
   * Sets auth password for http connection
   *
   * @param string $pass password
   */
  public function setPass($pass) {
    $this->auth_pass = $pass;
  }
  
  public function getUrl() {
    return $this->url;
  }

  public function setUrl( $url ) {
    $this->url = $url;
  }
  
  public function setReferer($referer) {
    $this->referer = $referer;
  }

  public function setCookiFileLocation($path) {
    $this->cookieFileLocation = $path;
  }

  public function setPost ($postFields) {
    $this->post = TRUE;
    $this->postFields = $postFields;
  }

  public function setuserAgent($userAgent) {
    $this->useragent = $userAgent;
  }

  /**
   * Invoke curl_exec / curl_close and writing status to $this->webpage and $this->status
   *
   */
  public function connect() {
    assert(!empty($this->url));
    $CurlHandler = curl_init($this->url);

    curl_setopt($CurlHandler, CURLOPT_URL, $this->url);
    curl_setopt($CurlHandler, CURLOPT_HTTPHEADER,array('Expect:'));
    curl_setopt($CurlHandler, CURLOPT_TIMEOUT, $this->timeout);
    curl_setopt($CurlHandler, CURLOPT_MAXREDIRS, $this->maxRedirects);
    curl_setopt($CurlHandler, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($CurlHandler, CURLOPT_FOLLOWLOCATION, $this->followlocation);
    if ( $this->cookieFileLocation ) {
      curl_setopt($CurlHandler, CURLOPT_COOKIEJAR, $this->cookieFileLocation);
      curl_setopt($CurlHandler, CURLOPT_COOKIEFILE, $this->cookieFileLocation);
    }
    if($this->authentication) {
      curl_setopt($CurlHandler, CURLOPT_USERPWD, $this->auth_name.':'.$this->auth_pass);
    }
    if($this->post) {
      curl_setopt($CurlHandler, CURLOPT_POST, TRUE);
      curl_setopt($CurlHandler, CURLOPT_POSTFIELDS, $this->postFields);

    }

    if($this->includeHeader) {
      curl_setopt($CurlHandler, CURLOPT_HEADER, TRUE);
    }

    if($this->noBody) {
      curl_setopt($CurlHandler, CURLOPT_NOBODY, TRUE);
    }
    if($this->binaryTransfer) {
      curl_setopt($CurlHandler, CURLOPT_BINARYTRANSFER, TRUE);
    }
    curl_setopt($CurlHandler, CURLOPT_USERAGENT, $this->useragent);
    curl_setopt($CurlHandler, CURLOPT_REFERER, $this->referer);

    $this->webpage = curl_exec($CurlHandler);
    $this->status = curl_getinfo($CurlHandler, CURLINFO_HTTP_CODE);
    $code = curl_errno($CurlHandler);
    if ( $code ) { 
      $lasterror = curl_error($CurlHandler);
    }
    
    curl_close($CurlHandler);
    if ( $code ) {
      throw new ErrorException( cT::t('CURL error: [%code] %error', array('%code'=> $code, '%error'=>$lasterror)) );
    }
  }

  /**
   * Get http status, which sets in connect method
   * @return string status
   */
  public function getHttpStatus() {
    return $this->status;
  }

  /**
   * Get string, which returned by curl_exec, invoked in connect method
   * return string webpage content
   */
  public function __tostring() {
    return $this->webpage ? (string) $this->webpage : '';
  }
}

/**
 * base implementation of pdodumper driver
 * @author <nexor@ya.ru>
 */
abstract class PDODumperDriverBase extends PDO {
  /**
   * null value, if no field data defined
   *
   * @return NULL string for dump 
   */
  abstract public function getNullValue();
  /**
   *
   * default charset for connection
   *
   * @param string $Charset charset name
   */
  abstract public function setdefaultCharset($Charset);
  /**
   * get table names in array
   *
   * @return array table names
   */
  abstract public function getTablesList();
  /**
   * get table query handler
   *
   * @param string $Table - table name
   * @param int $From - start row number
   * @param int $Limit - limit rows
   * @return PDOStatement
   */
  abstract public function getDataDumpSTH( $Table, $From, $Limit );
  /**
   * get table structure dump
   *
   * @param string $Table table name
   * @return string table structure dump
   */
  abstract public function getTableDump( $Table );
  /**
   * returns query string for drop table
   *
   * @param string $Table table name
   * @return string query string
   */
  abstract public function getTableRowCount( $Table );
  /**
   * returns rows count in table
   *
   * @param string $Table table name
   * @return int rows count
   */
  abstract public function getQuotableFieldList( $Table );
  /**
   * returns array of field=>bool, where bool is true, if fields are quotable
   *
   * @param string $Table table name
   * @return array( 'field' => TRUE, 'field2' => FALSE, ...)
   */
}

/**
 *
 * Abstraction level driver for PDO dumper
 *
 * @author nexor <nexor@ya.ru>
 * @version $Id$
 *
 */
class PDODumperDriver_mysql extends PDODumperDriverBase {
  public function getNullValue() {
    return '\N';
  }

  public function setdefaultCharset($Charset) {
    $this->query('SET NAMES "' . $Charset . '"');
  }

  public function getTablesList() {
    $sth = $this->query('SHOW TABLES');
    return $sth->fetchAll(PDO::FETCH_COLUMN);
  }

  public function getDataDumpSTH( $Table, $From, $Limit ) {
    $sth = $this->prepare('SELECT * FROM `' . $Table . '` LIMIT :from, :limit');
    $sth->bindValue( ':from', $From, PDO::PARAM_INT);
    $sth->bindValue( ':limit', $Limit, PDO::PARAM_INT);
    $sth->execute();
    return $sth;
  }

  public function getTableDump( $Table ) {
    $sth = $this->query('SHOW CREATE TABLE `' . $Table . '`');
    return $sth->fetchColumn(1);
  }

  public function getTableDropDump($Table ) {
    return 'DROP TABLE IF EXISTS `' . $Table . '`';
  }

  public function getTableRowCount( $Table ) {
    $sth = $this->query('SELECT COUNT(*) FROM `' . $Table . '`');
    return $sth->fetchColumn();
  }

  public function getQuotableFieldList( $Table ) {
    $sth = $this->query('SHOW COLUMNS FROM `' . $Table . '`');
    $List = array();
    while ($Info = $sth->fetch(PDO::FETCH_ASSOC) ) {
      $List[$Info['Field']] = !preg_match('/^(tinyint|smallint|mediumint|bigint|int|float|double|real|decimal|numeric|year)/', $Info['Type']);
    }
    return $List;
  }
}

/**
 * PDODumper driver factory, find and instance dumper driver
 * @author nexor
 * @version $Id$
 *
 */
class PDODumperDriver {
  /**
   * static method returns driver object
   *
   * @param string $dsn
   * @param string $user
   * @param string $password
   * @param string $options
   * @throws Exception if can't find driver
   * @return object driver instance
   */
  public static function Factory( $dsn, $user, $password, $options = NULL ) {
    $pos = strpos($dsn, ':');
    $driverClassName = 'PDODumperDriver_' . substr($dsn, 0, $pos);
    try {
      $class = new $driverClassName($dsn, $user, $password, $options);
    }
    catch( Exception $e) {
      throw new ErrorException(cT::t('Can\'t init pdo dumper driver: [$driverClassName] %msg', array('$driverClassName' => $driverClassName, '%msg' => $e->getMessage() ) ));
    }
    return $class;
  }
}

/**
 * PDO dumper, that exports or import sql dump to filestream
 *
 * @author nexor <nexor@ya.ru>
 * @version $Id$
 *
 */
class PDODumper {
  /**
   * BD driver
   * @var PDODumperDriver_* class
   */
  private $PDO  = NULL;

  private $session = NULL;

  /**
   * @var string version number
   */
  const VERSION = '0.1';

  /**
   * @var string default charset
   */
  public $defaultCharset = 'utf8';

  /**
   * @var default buffer size for reading dump files
   */
  public $readBufferSize = 4096;
  /**
   * @var int rows limit for one discontinues insert operation
   */
  public $insertLimit = 1000;

  public $dsn;
  public $user;
  public $password;
  public $options;

  public $filterTableData = array();
  public $filterTableStructure = array();
  public $filterIncludeMode = FALSE;

  private $tableDataSeparator = "#PDODMP separator\n\n";
  private $queryEndTemplate = ";\n";
  private $insertBeginTemplate = "INSERT INTO `:table` VALUES\n";
  private $insertLineTemplate = '(:values)';
  private $insertEndLineTemplate = ",\n";

  public function __construct( $dsn, $user, $password, $defaultCharset = 'utf8', $readBufferSize = 4096, $insertLimit = 1000, $options = NULL) {
    $this->dsn = $dsn;
    $this->user = $user;
    $this->password = $password;
    $this->defaultCharset = $defaultCharset;
    $this->readBufferSize = $readBufferSize;
    $this->insertLimit = $insertLimit;
    $this->session = new cSession( __CLASS__ );
    $this->options = $options;
  }

  /**
   * init dumper driver and connect to database
   * @throws Exception Nothing to export, if no export tables
   * @return State
   */
  public function connect() {
    $this->PDO = PDODumperDriver::Factory( $this->dsn, $this->user, $this->password, $this->options);
    $this->PDO->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $this->PDO->setdefaultCharset($this->defaultCharset);
  }


  /**
   * 
   * Sets filter include mode
   * @param bool $mode, TRUE if filter is include filter - else - exlide filter
   */
  public function setFilterIncludeMode( $mode = FALSE) {
    $this->filterIncludeMode = $mode; 
  }
  /**
   * is table matched to set of filters
   *
   * @param string $Table
   * @param array $Filters array of preg_match filters to exclude tables
   * @return bool - TRUE, if match found
   */
  private function isMatchedTable( $Table, &$Filters ) {
    foreach ( $Filters as $Filter ) {
      $bFound = preg_match('#' . $Filter . '#i', $Table);
      if ( $this->filterIncludeMode  && $bFound ) {
        return TRUE;
      } else if ( !$this->filterIncludeMode  && !$bFound ) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * removes tables from list
   *
   * @param array $Tables tables array
   * @param array $Filters array of preg_match filters to exclude tables
   * @return array filtered table
   */
  private function filterTables( $Tables, &$Filters ) {
    $newTables = array();
    foreach ( $Tables as $Table ) {
      if ( !$Filters || $this->isMatchedTable($Table, $Filters) ) {
        $newTables[] = $Table;
      }
    }
    return $newTables;
  }

  /**
   * Exports table create structure into stream
   * @param $Stream - fopen stream
   * @return array State : array('table_name' => array('Count'=> int, 'StartRow' => 0) )
   */
  private function ExportStructure(&$Stream) {
    $TableStructures = array();
    $Tables = $this->PDO->getTablesList();
    if ( empty($Tables) ) {
      throw new ErrorException(cT::t('Nothing to export: $dsn', array('$dsn' => $this->dsn) ));
    }
    if ( $this->filterIncludeMode && !$this->filterTableStructure ) {
      $this->filterTableStructure = $this->filterTableData;
    }
    $Tables = $this->filterTables($Tables, $this->filterTableStructure);

    $State = array();
    $Stream->put('#PDODumper v ' . PDODumper::VERSION . ", dsn=" . $this->dsn .  "\n#" . implode(', ', $Tables) . "\n" );
    foreach ( $Tables as $Table) {
      $TableDump = $this->PDO->getTableDump($Table);

      if ($this->isMatchedTable($Table, $this->filterTableData) ) {
        $State[$Table]['Count'] = NULL;
        $State[$Table]['StartRow'] = 0;
      }

      $Stream->put(
      $this->PDO->getTableDropDump($Table)
      . $this->queryEndTemplate
      . $TableDump
      . $this->queryEndTemplate
      . $this->tableDataSeparator);
    }
    return $State;
  }

  /**
   *
   * Exports database data into stream
   * @param $Stream - fopen stream
   * @param array $State: array('table_name' => array('Count'=> int, 'StartRow' => int ) )
   * @return array of modified state or empty, if all tables data stored into stream
   */
  private function ExportData( &$Stream, &$State ) {
    reset($State);
    list($Table, ) = each($State);
    $Info = &$State[$Table];
    if ( is_null($Info['Count']) ) {
      // $this->PDO->beginTransaction();
      $Info['Count'] = $this->PDO->getTableRowCount($Table);
      if( empty($Info['QuotableFields']) ) {
        $Info['QuotableFields'] = $this->PDO->getQuotableFieldList($Table);
      }
      if( !isset($Info['StartRow']) ) {
        $Info['StartRow'] = 0;
      }
    }

    $sth = $this->PDO->getDataDumpSTH($Table, $Info['StartRow'], $this->insertLimit);
    $RowDump = '';
    $bFirst = TRUE;
    while ( $RowArray = $sth->fetch(PDO::FETCH_ASSOC) )  {
      if ( $bFirst ) {
        $Stream->put( str_replace(':table', $Table, $this->insertBeginTemplate) );
        $bFirst = FALSE;
      }
      $Info['StartRow']++;

      if ( $RowDump ) {
        $Stream->put( $RowDump . $this->insertEndLineTemplate );
      }
      $Values = array();
      foreach ($Info['QuotableFields'] as $Field => $bQuotable ) {
        if (isset ( $RowArray[$Field] )) {
          $Value = $RowArray[$Field];
          $Value = $bQuotable ? $this->PDO->quote($Value) : $Value;
        } else {
          $Value = $this->PDO->getNullValue();
        }
        $Values[] = $Value;
      }
      if ( $Values ) {
        $RowDump =  str_replace(':values',  implode(', ', $Values), $this->insertLineTemplate);
      }
    } //while

    if ( $RowDump ) {
      $Stream->put($RowDump . $this->queryEndTemplate . $this->tableDataSeparator);
    }

    $isEnd = $Info['Count'] == $Info['StartRow'];

    if ( $isEnd ) {
      unset($State[$Table]);
      // $this->PDO->commit();
    }
    return $State;
  }

  /**
   * reads all data before $Tag from stream into $sData string
   *
   * @param cStream $Stream
   * @param string $Tag
   * @param array $State in session we are store temp part of buffer and filepos
   * @param string $sData
   * @return bool TRUE, if end of file reached
   */
  private function getDataBeforeTag(&$Stream, $Tag, &$State, &$sData) {

    $Buffer = &$State['Buffer'];
    $filePos = &$State['filePos'];

    $sData = '';
    $TagPos = TRUE;
    $bDone = FALSE;
    do {
      if( !$Buffer || $TagPos === FALSE) {
        $tmpBuffer = $Stream->get($this->readBufferSize);
        if ( !$tmpBuffer ) {
          $bDone = TRUE;
        } else {
          $filePos += strlen($tmpBuffer);
          $Buffer .= $tmpBuffer;
        }
      }

      if ( !$bDone ) {
        $TagPos = strpos($Buffer, $Tag);
        if ($TagPos !== FALSE) {
          $sData = substr($Buffer, 0, $TagPos);
          $Buffer = substr($Buffer, $TagPos + strlen($Tag) );
        }
      } else {
        $TagPos = TRUE;
        $sData = $Buffer;
        $Buffer = '';
      }

    } while ( $TagPos === FALSE );
    return $bDone;

  }

  /**
   *
   * Exports all data from connected db into stream
   * data are stored in session PDODumper->dsn
   * calls this method until return TRUE
   * @param $filename - output stream file
   * @return TRUE if all data exported, FALSE - if operation in progress
   */
  public function Export( $filename ) {
    $State =  & $this->session->get();
    $mode = empty($State['Export']) ? 'w' : 'a';

    $Stream = new cStream($filename, $mode . 'b');


    if ( empty($State) ) {
      $State['Export'] = $this->ExportStructure($Stream);
    }

    $this->ExportData($Stream, $State['Export']);

    $bDone = empty($State['Export']);
    if ( $bDone ) {
      unset($State['Export']);
    }
    return $bDone;
  }

  /**
   * imports all data from connected db into stream
   *
   * @param string $filename
   * @throws Exception - throws if can't import to database
   * @return bool TRUE, if import complete
   */
  public function Import( $filename ) {
    $State = & $this->session->get();
    $Stream = new cStream($filename, 'rb');

    if ( !isset( $State['Import']['Buffer']) ) {
      $State['Import']['Buffer'] = '';
    }
    if ( !isset( $State['Import']['filePos']) ) {
      $State['Import']['filePos'] = 0;
    } else {
      $Stream->seek($State['Import']['filePos']);
    }
    $sData = '';
    $bDone = $this->getDataBeforeTag($Stream, $this->tableDataSeparator, $State['Import'], $sData);
    if( $sData ) {
      try {
        $this->PDO->query($sData);
      }
      catch (Exception $e) {
        cLog::Warning(
          cT::t(
          	'Can\'t import to $dsn, message=$msg, data=\'$data\'', 
            array(
            	'$dsn' => $this->dsn,
            	'$msg'=> $e->getMessage(),
            	'$data' => substr($sData, 0 ,255))
            )
          );
      }
    }

    if ( $bDone ) {
      unset($State['Import']);
    }
    return $bDone;
  }

  /**
   * Sets filters for table data
   *
   * @param array $Filters array of preg_match strings, without slashes
   */
  public function setFilterTableData( $Filters ) {
    $this->filterTableData = $Filters;
  }

  /**
   * Sets exclude filters for table structure
   *
   * @param array $Filters array of preg_match strings, without slashes
   */
  public function setFilterTableStructure( $Filters ) {
    $this->filterTableStructure = $Filters;
  }

}

class cMainDumperApp {
  public $dump_dir;
  public $filter;
  public $filter_include = TRUE;
  public $filename; 
  private $dumper = NULL;
  const RESPONCE_MAGIC = 'Responce: ';
  const RESPONCE_UPLOAD_OK = 'DONE';
  
  function __construct( $dsn, $user, $password, $dump_dir, $filter, $filter_include = TRUE, $filename = '') {
    $this->dumper = new PDODumper( $dsn, $user, $password );
    $this->dump_dir = $dump_dir;
    $this->filter = $filter;
    $this->filter_include = $filter_include;
    $this->setImportFilename($filename);
  }
  
  /**
   * Export database dump to file
   * filename is autogenerated
   *
   * @throws Exception Can't connect to local database
   * @return string filename of table dump file
   */
  protected function export() {
    $dbname = preg_replace('#.*dbname=(.*);.*#', '$1', $this->dumper->dsn );
    $suffix = date('d-m-Y_H-i-s'). '-' . uniqid();
    $filename =  $dbname . '-' . $suffix . '.sql.gz';
    $local_filename = $this->dump_dir . $filename;
    
    cLog::Notice(cT::t('Connecting to database %dsn', array('%dsn' => $this->dumper->dsn)));
    $this->dumper->connect();
    $this->dumper->setFilterTableData($this->filter);
    $this->dumper->setFilterIncludeMode( $this->filter_include );
    
    cLog::Notice(cT::t('Exporting %dsn to %file', array('%dsn' => $this->dumper->dsn, '%file' => $local_filename) ));
    while ( !$this->dumper->Export('compress.zlib://' . $local_filename) ) {
      set_time_limit(120);
    }
    return $filename;
  }
  
  /**
   * Import dump data from local file to database
   * 
   * @throws Exception Can't find file, Can't connect to database  
   */
  protected function import() {
    if ( !file_exists($this->filename) ) {
      throw new ErrorException (cT::t('Can\'t find file %file to import', array('%file'=> $this->filename)));
    }
    cLog::Notice(cT::t('Connecting to database %dsn', array('%dsn' => $this->dumper->dsn)));
    $this->dumper->connect();
    cLog::Notice(cT::t('Importing from %file to %dsn', array('%file' => $this->filename, '%dsn' => $this->dumper->dsn) ));
    while ( !$this->dumper->Import('compress.zlib://' . $this->filename) ) {
      set_time_limit(120);
    }
    return TRUE;
  }
  
  public function setImportFilename( $filename ) {
    $this->filename = $filename;
  }

  public function run( $action ) {   
    switch ( $action ) {
      default:
        // throw new ErrorException( cT::t('Action unknown: %action', array('%action' => $action)) );
    }
  }
}

function check_ver() {
  if ( PHP_VERSION_ID < 50206) {
    echo 'Sorry, php version must be >= 5.2.6';
    exit(1);
  }
}
  

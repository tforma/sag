<?php
/**
 * Uses the PHP cURL bindings for HTTP communication with CouchDB. This gives
 * you more advanced features, like SSL supports, with the cost of an
 * additional dependency that your shared hosting environment might now have. 
 *
 * @version 0.7.1
 * @package HTTP
 */
require_once('SagHTTPAdapter.php');

class SagCURLHTTPAdapter extends SagHTTPAdapter {
  private $ch;
  private $headers;

  public function __construct($host, $port) {
    if(!extension_loaded('curl')) {
      throw new SagException('Sag cannot use cURL on this system: the PHP cURL extension is not installed.');
    }

    parent::__construct($host, $port);
  }

  private function _setHeader($ch, $header)
  {
    $len=strlen($header);
    $header=trim($header);

    // skip 100 Continue header and blank lines
    if ($header != 'HTTP/1.1 100 Continue' && $header != '')
      $this->headers[] = trim($header);
    return($len);
  }

  private function _read_cb($ch, $fd, $length)
  {
    return fread($fd,$length);
  }

  public function procPacket($method, $url, $data = null, $headers = array(), $specialHost = null, $specialPort = null) {
    $this->ch = curl_init();
    $this->headers = array();

    // the base cURL options
    $opts = array(
      CURLOPT_URL => "{$this->proto}://{$this->host}:{$this->port}{$url}",
      CURLOPT_PORT => $this->port,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HEADER => false,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_NOBODY => false,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HEADERFUNCTION => array($this, '_setHeader'),
    );

    // send data through cURL's poorly named opt
    if($data) {
      if ($method=='PUT') {
        // specify upload if it is a PUT request
        $opts[CURLOPT_PUT] = true;
        $opts[CURLOPT_UPLOAD] = true;

        // chunking data because Couch disconnects as soon as it sends an HTTP error response
        // which causes curl to throw an SSL_write error instead of processing the HTTP error
        unset ($headers['Content-Length']);
        unset ($headers['Expect']);
        $headers['Transfer-Encoding'] = 'chunked';

        // don't technically need this b/c curl will automatically add it
        // but better to have it than to not realize it's being sent
        $headers['Expect'] = '100-continue';

        // _read_cb callback function is called to write data to the output stream
        $opts[CURLOPT_READFUNCTION] = array($this, '_read_cb');

        //create an in-memory file handle to read from
        $fp = fopen('php://temp/maxmemory:256000', 'w');
        if (!$fp) {
          throw new SagException ("could not open temp memory data");
        }
        fwrite($fp, $data);
        fseek($fp, 0);

        $opts[CURLOPT_INFILE] =  $fp;
        $opts[CURLOPT_INFILESIZE] = strlen($data);
      } else {
        $opts[CURLOPT_POSTFIELDS] = $data;
      }
    }

    // cURL wants the headers as an array of strings, not an assoc array
    if(is_array($headers) && sizeof($headers) > 0) {
      $opts[CURLOPT_HTTPHEADER] = array();

      foreach($headers as $k => $v) {
        $opts[CURLOPT_HTTPHEADER][] = "$k: $v";
      }
    }

    // special considerations for HEAD requests
    if($method == 'HEAD') {
      $opts[CURLOPT_NOBODY] = true;
    }

    // connect timeout
    if(is_int($this->socketOpenTimeout)) {
      $opts[CURLOPT_CONNECTTIMEOUT] = $this->socketOpenTimeout;
    }

    // exec timeout (seconds)
    if(is_int($this->socketRWTimeoutSeconds)) {
      $opts[CURLOPT_TIMEOUT] = $this->socketRWTimeoutSeconds;
    }

    // exec timeout (ms)
    if(is_int($this->socketRWTimeoutMicroseconds)) {
      $opts[CURLOPT_TIMEOUT_MS] = $this->socketRWTimeoutMicroseconds;
    }

    // SSL support: don't verify unless we have a cert set
    if($this->proto === 'https') {
      if(!$this->sslCertPath) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
      }
      else {
        $opts[CURLOPT_SSL_VERIFYPEER] = true;
        $opts[CURLOPT_SSL_VERIFYHOST] = true;
        $opts[CURLOPT_CAINFO] = $this->sslCertPath;
      }
    }

    curl_setopt_array($this->ch, $opts);

    $chResponse = curl_exec($this->ch);

    // close file handle if opened
    if ($fp)
      fclose ($fp);

    if ($curl_errno = curl_errno($this->ch)) {
        $curl_error = curl_error($this->ch);
    }
    curl_close($this->ch);

    if($chResponse !== false) {
      // prepare the response object
      $response = new stdClass();
      $response->headers = new stdClass();
      $response->headers->_HTTP = new stdClass();
      $response->body = $chResponse;

      for($i = 0; $i < sizeof($this->headers); $i++) {
        // first element will always be the HTTP status line
        if($i === 0) {
          $response->headers->_HTTP->raw = $this->headers[$i];

          preg_match('(^HTTP/(?P<version>\d+\.\d+)\s+(?P<status>\d+))S', $this->headers[$i], $match);

          $response->headers->_HTTP->version = $match['version'];
          $response->headers->_HTTP->status = $match['status'];
          $response->status = $match['status'];
        }
        else {
          $line = explode(':', $this->headers[$i], 2);
          $line[0] = strtolower($line[0]);
          $response->headers->$line[0] = ltrim($line[1]);

          if($line[0] == 'Set-Cookie') {
            $response->cookies = $this->parseCookieString($line[1]);
          }
        }
      }
    }
    else if($curl_errno) {
      throw new SagException('cURL error #' . $curl_errno . ': ' . $curl_error);
    }
    else {
      throw new SagException('cURL returned false without providing an error.');
    }

    unset ($this->headers);
    return self::makeResult($response, $method);
  }
}
?>

<?php
require_once('SagHTTPAdapter.php');

/**
 * Uses native PHP sockets to communicate with CouchDB. This means zero new
 * dependencies for your application.
 *
 * This is also the original socket code that was used in Sag.
 *
 * @version 0.6.0
 * @package HTTP
 */
class SagNativeHTTPAdapter extends SagHTTPAdapter {
  private $connPool = array();          //Connection pool

  /**
   * Closes any sockets that are left open in the connection pool.
   */
  public function __destruct()
  {
    foreach($this->connPool as $sock)
      @fclose($sock);
  }

  public function procPacket($method, $url, $data = null, $headers = array()) {
    //Start building the request packet.
    $buff = "$method $url HTTP/1.1\r\n";

    foreach($headers as $k => $v) {
      $buff .= "$k: $v\r\n";
    }

    $buff .= "\r\n$data"; //it's okay if $data isn't set

    if($data && $method !== "PUT") {
      $buff .= "\r\n\r\n";
    }

    // Open the socket only once we know everything is ready and valid.
    $sock = null;

    while(!$sock) {
      if(sizeof($this->connPool) > 0) {
        $maybeSock = array_shift($this->connPool);
        $meta = stream_get_meta_data($maybeSock);

        if(!$meta['timed_out'] && !$meta['eof']) {
          $sock = $maybeSock;
        }
        elseif(is_resource($maybeSock)) {
          fclose($maybeSock);
        }
      }
      else {
        try {
          //these calls should throw on error
          if($this->socketOpenTimeout) {
            $sock = fsockopen($this->host, $this->port, $sockErrNo, $sockErrStr, $this->socketOpenTimeout);
          }
          else {
            $sock = fsockopen($this->host, $this->port, $sockErrNo, $sockErrStr);
          }

          //some PHP configurations don't throw when fsockopen() fails
          if(!$sock) {
            throw new Exception($sockErrStr, $sockErrNo);
          }
        }
        catch(Exception $e) {
          throw new SagException('Was unable to fsockopen() a new socket: '.$e->getMessage());
        }
      }
    }

    if(!$sock) {
      throw new SagException("Error connecting to {$this->host}:{$this->port} - $sockErrStr ($sockErrNo).");
    }

    // Send the packet.
    fwrite($sock, $buff);

    // Set the timeout.
    if(isset($this->socketRWTimeoutSeconds)) {
      stream_set_timeout($sock, $this->socketRWTimeoutSeconds, $this->socketRWTimeoutMicroseconds);
    }

    // Prepare the data structure to store the response.
    $response = new stdClass();
    $response->headers = new stdClass();
    $response->headers->_HTTP = new stdClass();
    $response->body = '';

    $isHeader = true;

    $chunkParsingDone = false;
    $chunkSize = null;

    // Read in the response.
    while(
      !$chunkParsingDone &&
      !feof($sock) && 
      (
        $isHeader ||
        (
          !$isHeader &&
          $method != 'HEAD' &&
          (
            isset($response->headers->{'Transfer-Encoding'}) == 'chunked' ||
            !isset($response->headers->{'Content-Length'}) ||
            (
              isset($response->headers->{'Content-Length'}) &&
              strlen($response->body) < $response->headers->{'Content-Length'}
            )
          )
        )
      )
    ) {
      $sockInfo = stream_get_meta_data($sock);

      if($sockInfo['timed_out']) {
        throw new SagException('Connection timed out while reading.');
      }

      $line = fgets($sock);

      if(!$line && !$sockInfo['feof'] && !$sockInfo['timed_out']) {
        throw new SagException('Unexpectedly failed to retrieve a line from the socket before the end of the file.');
      }

      if($isHeader) {
        //Parse headers

        //Clean the input
        $line = trim($line);

        if($isHeader && empty($line)) {
          /*
           * Don't parse empty lines before the initial header as being the
           * header/body delim line.
           */
          if($response->headers->_HTTP->raw) {
            $isHeader = false; //the delim blank line
          }
        }
        else {
          if(!isset($response->headers->_HTTP->raw)) {
            //the first header line is always the HTTP info
            $response->headers->_HTTP->raw = $line;

            if(preg_match('(^HTTP/(?P<version>\d+\.\d+)\s+(?P<status>\d+))S', $line, $match)) {
              $response->headers->_HTTP->version = $match['version'];
              $response->headers->_HTTP->status = $match['status'];
            }
            else {
              throw new SagException('There was a problem while handling the HTTP protocol.'); //whoops!
            }
          }
          else {
            $line = explode(':', $line, 2);
            $response->headers->$line[0] = ltrim($line[1]);

            if($line[0] == 'Set-Cookie') {
              $response->cookies = new stdClass();

              foreach(explode('; ', $line[1]) as $cookie) {
                $crumbs = explode('=', $cookie);
                $response->cookies->{trim($crumbs[0])} = trim($crumbs[1]);
              } 
            }
          }
        }
      }
      else if($response->headers->{'Transfer-Encoding'}) {
        /*
         * Parse the response's body, which is being sent in chunks. Welcome to
         * HTTP/1.1 land.
         *
         * Each chunk is preceded with a size, so if we don't have a chunk size
         * then we should be looking for one. A zero chunk size means the
         * message is over.
         */
        if($chunkSize === null) {
          //Look for a chunk size
          $line = rtrim($line);

          if(!empty($line) || $line == "0") {
            $chunkSize = hexdec($line);

            if(!is_int($chunkSize)) {
              throw new SagException('Invalid chunk size: '.$line);
            }
          }
        }
        else if($chunkSize === 0) {
          // We are done processing all the chunks.
          $chunkParsingDone = true;
        }
        else if($chunkSize) {
          //We have a chunk size, so look for data
          if(strlen($line) > $chunkSize && strlen($line) - 2 > $chunkSize) {
            throw new SagException('Unexpectedly large chunk on this line.');
          }
          else {
            $response->body .= $line;

            preg_match_all("/\r\n/", $line, $numCRLFs);
            $numCRLFs = sizeof($numCRLFs);

            /*
             * Chunks can span >1 line, which PHP is going to give us one a a
             * time.
             */
            $chunkSize -= strlen($line);

            if($chunkSize <= 0) {
              /*
               * Nothing left to this chunk, so the next link is going to be
               * another chunk size. Or so we hope.
               */
              $chunkSize = null;
            }
          }
        }
        else {
          throw new SagException('Unexpected empty line.');
        }
      }
      else {
        /*
         * Parse the response's body, which is being sent in one piece like in
         * the good ol' days.
         */
        $response->body .= $line;
      }
    }

    // HTTP/1.1 assumes persisted connections, but proxies might close them.
    if(strtolower($response->headers->Connection) != 'close') {
      $this->connPool[] = $sock;
    }

    return self::makeResult($response, $method);
  }
}
?>
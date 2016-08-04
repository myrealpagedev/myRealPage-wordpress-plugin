<?php

/**
 * Class for handling HTTP requests including header management.
 **/

namespace MRPIDX\HTTP;

class Client
{
    const GET = 'GET';
    const POST = 'POST';

    protected $headers;
    protected $method;
    protected $params;
    protected $response;
    protected $uri;
    protected $userAgent;

    public function __construct($uri, $options = array())
    {
        $this->setUri($uri);
        $this->setHeaders(self::getWithDefault($options, 'headers', array()));
        $this->setMethod(self::getWithDefault($options, 'method', self::GET));
        $this->setParams(self::getWithDefault($options, 'params'));
        $this->setUserAgent(self::getWithDefault($options, 'userAgent', $_SERVER['HTTP_USER_AGENT']));
        $this->response = null;
    }

    public function makeRequest()
    {
        // sanity check
        if (empty($this->uri) || parse_url($this->uri) === false) {
            throw new \Exception("Invalid URI: {$this->uri}");
        }

        $uri = $this->uri;
        $ch = curl_init();

        // GET request with no query parameters, but has parameters needs to have them appended
        $query = parse_url($uri, PHP_URL_QUERY);
        $params = $this->getParams();
        if ($this->getMethod() == self::GET && empty($query) && !empty($params)) {
            $this->uri = $this->uri . '?' . $this->getParams();
        }

        curl_setopt($ch, CURLOPT_URL, $this->uri);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_BINARYTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        // ensure we send along client side UA string in case mobile conversion is done
        curl_setopt($ch, CURLOPT_USERAGENT, $this->getUserAgent());
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if ($this->hasHeaders()) {
            $this->removeHeader('Expect');
            $headers = array();
            foreach ($this->getHeaders() as $name => $value) {
                $headers[] = "$value";
            }
            $headers[] = "Expect:"; // need this to prevent 100-continue headers interfering
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if ($this->getMethod() == self::POST) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($this->getParams()) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->getParams());
            }
        }

        // make the request and build a response object
        $content = @curl_exec($ch);
        $responseInfo = @curl_getinfo($ch);
        $this->response = new Response($content, $responseInfo);

        curl_close($ch);
        return $this;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function hasResponse()
    {
        return $this->response !== null;
    }

    public function setResponseHeader($name, $value)
    {
        if ($this->hasResponse()) {
            $this->response->setHeader($name, $value);
        }

        return $this;
    }

    public function hasHeaders()
    {
        return (bool) count($this->headers);
    }

    public function hasHeader($name)
    {
        foreach ($this->headers as $header) {
            if (strtoupper(substr($header, 0, strpos($header, ":"))) == strtoupper($name)) {
                return true;
            }
        }
        return false;
    }

    public function removeHeader($name, $delete = true)
    {
        $newHeaders = array();
        $changed    = false;

        foreach ($this->headers as $header) {
            if (strtoupper(substr($header, 0, strpos($header, ":"))) == strtoupper($name)) {
                $changed = true;
                if (!$delete) {
                    $newHeaders[] = "$name: ";
                }
            } else {
                $newHeaders[] = $header;
            }
        }

        if ($changed) {
            $this->headers = $newHeaders;
        }

        return $this;
    }

    public function getHeaders()
    {
        return $this->headers;
    }

    public function getHeader($name)
    {
        if (!$this->hasHeaders()) {
            return null;
        }

        foreach ($this->headers as $header) {
            if (strtoupper(substr($header, 0, strpos($header, ":"))) == strtoupper($name)) {
                return substr($header, strpos($header, ":"));
            }
        }

        // not found
        return null;
    }

    public function setHeader($name, $value)
    {
        $this->headers[] = "$name: $value";
        return $this;
    }

    public function setHeaders($headers)
    {
        $this->headers = $headers;
        return $this;
    }

    public function getMethod()
    {
        return $this->method;
    }

    public function setMethod($method)
    {
        $this->method = $method;
        return $this;
    }

    public function getParams()
    {
        return $this->params;
    }

    public function setParams($params)
    {
        $this->params = $params;
        return $this;
    }

    public function getUserAgent()
    {
        return $this->userAgent;
    }

    public function setUserAgent($agent)
    {
        $this->userAgent = $agent;
        return $this;
    }

    public function getUri()
    {
        return $this->uri;
    }

    public function setUri($uri)
    {
        $this->uri = $uri;
        return $this;
    }

    public static function getWithDefault($array, $name, $default = null)
    {
        return isset($array[$name]) ? $array[$name] : $default;
    }

    // note that this does not support multiple headers with the same
    // name!
    public static function parseHeaders($headers)
    {
        $ret = array();
        foreach ($headers as $header) {
        	if( !stripos( $header, ':' ) ) {
	        	continue;
        	}
            list($name, $value) = explode(':', $header, 2);
            $ret[$name] = $value;
        }
        return $ret;
    }
    
    public static function parseCookies($headers)
    {
        $ret = array();
        foreach ($headers as $header) {
        	if( !stripos( $header, ':' ) ) {
	        	continue;
        	}
            list($name, $value) = explode(':', $header, 2);
            if( $name == "Set-Cookie" ) {
	            $ret[] = $value;
            }
        }
        return $ret;
    }

}
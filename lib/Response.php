<?php

/**
 * Class for abstracting HTTP responses.
 */

namespace MRPIDX\HTTP;

class Response {
    public $cookies; 
    protected $headers;
    protected $content;
    protected $info;
    protected $rawContent;
    protected $responseCodes = array(
        200 => "OK",
        301 => "Moved Permanently",
        302 => "Moved Temporarily",
        304 => "Not Modified",
        400 => "Bad Request",
        401 => "Unauthorized",
        403 => "Forbidden",
        404 => "Not Found"
    );

    public function __construct($body, $info) {
        $this->rawContent        = $body;
        $this->info              = $info;
        list($headers, $content) = explode("\r\n\r\n", $body, 2);
        $this->headers           = Client::parseHeaders(explode("\r\n", $headers));
        $this->cookies           = Client::parseCookies(explode("\r\n", $headers));
        $this->content           = $content;
    }

    public function getContent() {
        return $this->content;
    }

    public function getRawContent() {
        return $this->rawContent;
    }

    public function getContentType() {
        return $this->getInfo('content_type');
    }

    public function getResponseCode()
    {
        return $this->getInfo('http_code');
    }

    public function getResponseCodeWithString()
    {
        $code = $this->getResponseCode();
        if (isset($this->responseCodes[$code])) {
            return $code . " " . $this->responseCodes[$code];
        }
        return $code;
    }

    public function getInfo($name) {
        return Client::getWithDefault($this->info, $name);
    }

    public function isRedirect() {
        $code = intval($this->getResponseCode());
        return ($code == 301 || $code == 302);
    }

    // TODO: refactor this into a superclass - both the response and client
    // both do stuff with headers
    public function hasHeaders() {
        return (bool) count($this->headers);
    }

    public function hasHeader($name) {
        return isset($this->headers[$name]) && strlen(trim($this->headers[$name]));
    }

    public function getHeaders() {
        return $this->headers;
    }
    
    public function getCookies() {
	    return $this->cookies;
    }

    public function getHeader($name) {
        return Client::getWithDefault($this->headers, $name);
    }

    public function setHeader($name, $value) {
        $this->headers[$name] = $value;
        return $this;
    }
}

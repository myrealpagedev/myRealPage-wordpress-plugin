<?php

namespace MRPIDX;

use \MRPIDX\HTTP\Client;

/**
 * Class Proxy - main abstraction for direct proxying of requests from MRP, usually things like resources.
 */
class Proxy {
    protected $defaultHeaders;
    protected $host; // direct proxy host
    protected $cache; // cache instance
    protected $logger; // logger instance
    protected $context; // context in which this proxy is running


    public function __construct(
        $context,
        $logger = null,
        $cache = null
    ) {
        $this->context = $context;
        $this->host = "http://" . InlineClient::SERVER;
        $this->logger = $logger == null ? new Logger("Proxy") : $logger;
        $this->cache = $cache == null ? new DBCache($this->logger) : $cache;
        $this->defaultHeaders = array(
            'X-WordPress-Site: ' . ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' ),
            'X-WordPress-Referer: ' . ( isset($_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' ),
            "X-WordPress-Theme: " . get_template()
        );
    }

    public function doProxy($uri, $postParams = array())
    {    
        if (headers_sent()) {
            // this is bad - we'll need to send headers, and we can't
            $this->logger->error("Headers already sent: " . __METHOD__);
            return;
        }
        
        $response = $this->proxy($uri, $postParams);

        // handle redirects
        if ($response->isRedirect() && $response->hasHeader("Location")) {
           	
           	$location = $response->getHeader('Location');
           	$location = preg_replace('@http://(.+?)/(.*)@', 'http://'.$_SERVER['HTTP_HOST'].'/$2', $location);

            header("HTTP/1.1 " . $response->getResponseCodeWithString());
            header("Location: " . $location);
            exit();
        }

        // give the same HTTP response code as we got on the server side.
        header('HTTP/1.1 ' . $response->getResponseCodeWithString());

        $headers = $response->getHeaders();
        $content = $response->getContent();

        // content-type header
        if (isset($headers['Content-Type'])) {
            header('Content-Type: ' . $headers['Content-Type'], true);
        }

        // ensure any relative references to wps-listings.css are made absolute
        $content = str_replace(
            '"/wps-listings.css',
            '"http://listings.myrealpage.com/wps-listings.css',
            $content
        );

        // touch up the content length header, since the above search and replace may have broken it
        if ($response->getInfo('download_content_length')) {
            header('Content-Length: ' . strlen($content));
        }

        // output Set-Cookie: or caching headers, headers if any
        if ($response->hasHeaders()) {
            foreach ($response->getHeaders() as $name => $value) {
                if (
                    $name == "Cache-Control"
                    || $name == "Expires"
                    || $name == "ETag"
                    || $name == "Last-Modified"
                ) {
                    header("$name: $value");
                } elseif ($name == "Set-Cookie") {
                    header("$name: $value", false);
                }
            }
        }

        echo($content);
    }

    /**
     * @param $uri
     * @param array $postParams
     * @return HTTP\Response response from this URI
     */
    private function proxy($uri, $postParams = array())
    {
        // check cache first
        $uri = $this->host . $uri;
        $cached = false;
        if ($this->cache) {
            $cached = $this->cache->getItem($uri);
        }

        if ($cached) {
            return new HTTP\Response($cached["content"], array());
        }

        $context = $this->context;
        $client  = new Client($uri);
        $headers = $this->defaultHeaders;

        if ($context->getPageName()) {
            $headers[] = 'MrpInlineRoot: ' . '/' . $context->getPageName();
        }

        if ($context->getGoogleMapApiKey()) {
            $headers[] = 'X-Mrp-GoogleMapKey: ' . $context->getGoogleMapApiKey();
        }
        
        //error_log( $uri . " -> X-Requested-With: " . $_SERVER['HTTP_X_REQUESTED_WITH'] );
        
        // custom header passing
        if( isset( $_SERVER['HTTP_X_REQUESTED_WITH'] ) && $_SERVER['HTTP_X_REQUESTED_WITH'] ) {
	        // X-Requested-With: XMLHttpRequest (normally)
	        $headers[] = 'X-Requested-With: ' . $_SERVER['HTTP_X_REQUESTED_WITH'];
        }
        if( isset( $_SERVER['HTTP_X_MRP_TMPL'] ) && $_SERVER['HTTP_X_MRP_TMPL'] ) {
	        // X-Requested-With: XMLHttpRequest (normally)
	        $headers[] = 'X-MRP-TMPL: ' . $_SERVER['HTTP_X_MRP_TMPL'];
        }
        if( isset( $_SERVER['HTTP_X_MRP_CACHE'] ) && $_SERVER['HTTP_X_MRP_CACHE'] ) {
	        // X-Requested-With: XMLHttpRequest (normally)
	        $headers[] = 'X-MRP-CACHE: ' . $_SERVER['HTTP_X_MRP_CACHE'];
        }
        
        if ($this->getCookieAsHeader()) {
            $headers[] = $this->getCookieAsHeader();
        }

        $client->setHeaders($headers);
        if (count($postParams)) {
            $client->setParams($postParams);
            $client->setMethod(Client::POST);
        }

        // make and cache this request if possible
        $client->makeRequest();
        $response = $client->getResponse();
        // we will set header X-MRP-CACHE for the dynamic forms, retrievable as $_SERVER['HTTP_X_MRP_CACHE'] (note 'HTTP_' prefix and '-' to '_' conversion)
        if ($response && $this->cache->isCacheable($uri) && 'no' != $_SERVER['HTTP_X_MRP_CACHE'] ) {
            $this->cache->setItem($uri, $response->getRawContent());
        }
        return $response;
    }

    private function getCookieAsHeader() {
        $cookie = '';
        if (count($_COOKIE)) {
            foreach($_COOKIE as $name => $value) {
                if (!is_array($value)) {
                    $cookie .= "$name=".urlencode($value)."; ";
                }
            }
        }
        return "Cookie: $cookie";
    }

}
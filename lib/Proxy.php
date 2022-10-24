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
        $this->host = "https://" . InlineClient::SERVER;
        $this->logger = $logger == null ? new Logger("Proxy") : $logger;
        $this->cache = $cache == null ? new DBCache($this->logger) : $cache;
        $this->defaultHeaders = array(
            "MrpInlineSecure: ". ($this->isSecure() ? "true": "false"),
            'X-WordPress-Site: ' . ( isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '' ),
            'X-WordPress-Referer: ' . ( isset($_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : '' ),
            "X-Real-IP: " . ( isset( $_SERVER["REMOTE_ADDR"] ) ? $_SERVER["REMOTE_ADDR"] : "-" ),
            "X-WordPress-Theme: " . get_template()
        );
        //error_log( 'PROXY MRP INLINE ROOT: ' . $_SERVER['HTTP_MRPINLINEROOT'] );
        if( isset( $_SERVER['HTTP_MRPINLINEROOT'] ) ) {
	        $this->defaultHeaders[] = 'MrpInlineRoot: ' . $_SERVER['HTTP_MRPINLINEROOT'];
        }
        if( isset( $_SERVER['HTTP_X_MRP_INPAGE_NAV'] ) ) {
	        $this->defaultHeaders[] = 'X-MRP-INPAGE-NAV: ' . $_SERVER['HTTP_X_MRP_INPAGE_NAV'];
        }
    }

    private function isSecure() {
		return	(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
	}

   	public function nocacheHeaders()
    {
        header( "Cache-Control: no-store" );
        header( "X-MRP-DYNAMIC: " . gmdate("D, d M Y H:i:s") . " GMT" );
        header( "Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT" );

        // we have to add this cookie to simulate an auth env for hosting envs like WPEngine which look
        // for wordpress_ cookies to disable cache
        header( "Set-Cookie: wordpress_mrp_cache=no-store; Path=/wps", false );
    }

    public function doProxy($uri, $postParams = array())
    {
        if (headers_sent()) {
            // this is bad - we'll need to send headers, and we can't
            $this->logger->error("Headers already sent: " . __METHOD__);
            return;
        }

        $response = $this->proxy($uri, $postParams);

        if( $response ) {
	        error_log( 'PROXY RESPONSE: ' . $response->getResponseCodeWithString() );
        }

        // handle redirects
        if ($response->isRedirect() && $response->hasHeader("Location")) {

            $location = trim($response->getHeader('Location'));
			if( preg_match('@^http(s?):\/\/([^\/]+\.myrealpage.com)\/.*@', $location, $matches)) {
                $location = preg_replace('@^http(s?):\/\/(.+?)\/(.*)@', ( $this->isSecure() ? 'https://' : 'http://' ) .$_SERVER['HTTP_HOST'].'/$3', $location);
			}

            header("HTTP/1.1 " . $response->getResponseCodeWithString());
            header("Location: " . $location);

            $this->nocacheHeaders();

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
        /*
$content = str_replace(
            '"/wps-listings.css',
            '"http://listings.myrealpage.com/wps-listings.css',
            $content
        );
*/

        // touch up the content length header, since the above search and replace may have broken it
        /* skip content-length altogether to prevent nginx "chunked" transfer encoding conflict

        if ($response->getInfo('download_content_length')) {
            header('Content-Length: ' . strlen($content));
        }
        */

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
                    // skip, this is a multi-header
                    //header("$name: $value", false);
                }
            }
        }

        $this->outputCookieHeaders($response);

        $this->nocacheHeaders();

        echo($content);
    }

    public function outputCookieHeaders($response)
	{

	    $cookies = $response->getCookies();
        //$this->logger->debug("Setting cookies: " . print_r($cookies,true) );
        foreach( $cookies as $cookie ) {
			parse_str(strtr($cookie, array('&' => '%26', '+' => '%2B', ';' => '&')), $parsed);
			if( isset($parsed['mrp_sort']) && $parsed['mrp_sort']) {
				$mod_sorted_cookie = "mrp_sort=" . $parsed["mrp_sort"] . "; Path=/";
				error_log( "SORT COOKIE: " . $mod_sorted_cookie );
				header( "Set-Cookie:" . $mod_sorted_cookie, false );
			}
			else {
				$this->logger->debug("Setting cookie: " . $cookie );
				header( "Set-Cookie:" . $cookie, false );
			}
        }
        //error_log( "COOKIES: " . print_r( $response->getCookies(), true ));
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

		$hasInlineRoot = false;
		foreach($headers as &$header) {
			if( $header && strpos($header,'MrpInlineRoot') == 0 ) {
				$hasInlineRoot = true;
				break;
			}
		}
        if ($context->getPageName() && !$hasInlineRoot) {
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
        if( isset( $_SERVER["HTTP_X_MRP_AUTO_SOLD"] ) ) {
	        $headers[] = "X-MRP-AUTO-SOLD: " . $_SERVER["HTTP_X_MRP_AUTO_SOLD"];
        }
        if( isset( $_SERVER['HTTP_X_MRP_INPAGE_NAV'] ) ) {
	        $headers[] = 'X-MRP-INPAGE-NAV: ' . $_SERVER['HTTP_X_MRP_INPAGE_NAV'];
        }
        if( isset( $_SERVER["HTTP_LISTING_VIEWATTRS"] ) ) {
        	$headers[] = "Listing-ViewAttrs: " . $_SERVER["HTTP_LISTING_VIEWATTRS"];
        }


		$headers[] = 'cache-control: no-store';

        if ($this->getCookieAsHeader()) {
            $headers[] = $this->getCookieAsHeader();
        }

        error_log( "PROXY HEADERS: (" . $uri . ") " . print_r( $headers, true ) );

        $client->setHeaders($headers);
        // OLD if (count($postParams)) {
        // NEW https://stackoverflow.com/questions/66671269/fatal-error-uncaught-typeerror-count-argument-1-var-must-be-of-type-cou
        if (count((array)$postParams)) { 
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

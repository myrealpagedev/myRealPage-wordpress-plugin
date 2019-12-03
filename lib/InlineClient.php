<?php

namespace MRPIDX;

use MRPIDX\HTTP\Client;

class InlineClient {

	const SERVER = 'listings.myrealpage.com';
    //const SERVER = 'ec2-beta.myrealpage.com';
    //const SERVER = '174.7.235.34';
    const RES_SERVER = \MRPIDX\InlineClient::SERVER;

    protected $context;
    protected $logger;
    protected $headers = array();
    protected $content;
    protected $client;

    public function __construct($logger, $context = null)
    {
        $this->context = !is_null($context) ? $context : new Context();
        $this->logger  = $logger;
        
        // add default headers
        $this->headers += array(
            "MrpStripPowered: false",
            "MrpInlinePort: 80",
            "X-WordPress-Site: " . ( isset( $_SERVER["HTTP_HOST"] ) ? $_SERVER["HTTP_HOST"] : "" ),
            "X-WordPress-Referer: " . ( isset( $_SERVER["HTTP_REFERER"] ) ? $_SERVER["HTTP_REFERER"] : "" ),
            "X-WordPress-Theme: " . get_template(),
            "X-MRP-TMPL: v2",
            "X-Real-IP: " . ( isset( $_SERVER["REMOTE_ADDR"] ) ? $_SERVER["REMOTE_ADDR"] : "-" ),
            //"X-MRP-Server-Debug: true",
            "Cookie: " . $this->getCookieHeader()
        );
        
        if( isset( $_SERVER["HTTP_X_MRP_AUTO_SOLD"] ) ) {
	        $this->headers += array(
	        	"X-MRP-AUTO-SOLD: " . $_SERVER["HTTP_X_MRP_AUTO_SOLD"]
	        );
        }

        // figure out our extension
        $extension = $context->get("extension");
        if ($extension != '' && substr($extension, 0, 4) == 'wps/') {
            // strip out wps/<context>/<account_id> so we're left with the controller part and
            // any query string variables
            preg_match('/wps\/.+?\/\d+[\/]?(.*)$/', $extension, $matches);
            if (isset($matches[1])) {
                $extension = $matches[1];
                $this->context->set("extension", $extension);
            } else {
                $this->logger->error("Unable to parse extension: " . $extension);
                $this->context->set("extension", "");
            }
        }

        // include query strings
        /* bill: this doubles up the query string
        if (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] != '') {
            $extension .= '?' . $_SERVER['QUERY_STRING'];
            $this->context->set("extension", $extension);
        }
        */

        if (strlen($extension)) {
            $this->headers[] = "X-Extension: " . $extension;
        }

        if ($context->has("listingDef")) {
            $this->headers[] = "Listing-Definition: " . $context->get("listingDef");
        }

        if ($context->has("permAttr")) {
            $this->headers[] = "Listing-ViewAttrs: " . $context->get("permAttr");
        }
        
        if( $context->has( "googleMapApiKey") ) {
	        $this->headers[] = "X-Google-API-Key: " . $context->get("googleMapApiKey");
        }
		
		$this->logger->debug("Context: " . print_r($this->context->getAllValues(), true));

        $this->client = new Client($this->generateUrl(), array("headers" => $this->headers));
    }

    public function setExtension($extension)
    {
        $this->context->setExtension($extension);
        return $this;
    }

    /**
     * Process inline content.
     */

    public function processInline()
    {
        $client = $this->client;
        if (!$client) {
            $this->logger->error("Invalid HTTP Client.");
            return;
        }

        // add MrpInlineRoot header
        $pageName = $this->context->getPageName();
        if (strlen($pageName)) {
	        //error_log( '------- PAGE NAME: ' . $pageName );
            $client->setHeader("MrpInlineRoot", "/" . $pageName . "/");
        } else {
            $client->setHeader("MrpInlineRoot", "/");
        }

        $this->client = $client;
        $this->logger->debug("Request headers: " . print_r($client->getHeaders(), true));
        //error_log("Request headers: " . print_r($client->getHeaders(), true));
        
        
        if( $_SERVER['REQUEST_METHOD'] == 'POST' ) {
        	$client->setMethod(Client::POST);
        	$client->setParams( file_get_contents("php://input") );
        } 
        
        $client->makeRequest();
        $response = $client->getResponse();
        
        //error_log( "RESPONSE_HEADERS RAW: " . print_r( $response->getHeaders(), true ) );
		//error_log( "CONTENT: ". $response->getRawContent() );

        // set raw content in case we need it later
        $this->setInlineContent("raw", $response->getRawContent());
        
                
        $listingContentType = "";
        if( $response->hasHeader( "X-MRP-LISTING-CONTENT" ) && $response->getHeader( "X-MRP-LISTING-CONTENT" ) ) {
	        $listingContentType = $response->getHeader( "X-MRP-LISTING-CONTENT" );
        }
        $this->setInlineContent("listing_content_type", trim($listingContentType) );
        
        $status  = $response->getResponseCode();
        $content = $response->getContent();
        $body = '';
        if ($status == 200 || $status == 404 || $status == 410 ) {
            // If the content type is text/plain we do some magic - we check that it begins
            // with text/plain in case it is followed by an encoding.
            if ($response->hasHeader('Content-Type')
                && substr($response->getHeader('Content-Type'), 0 ,10) == 'text/plain') {
                $body = "<div style='margin-top: 100px; text-align: center;'><pre>$content</pre></div>";
            } else {
                // we ended up with HTML
                $this->parseInlineContent();
                //$content['cookies'] = $inline['cookies'];
            }

            if ($status == 404) {
                $this->setInlineContent("title", "Not Found");
            }
            if ($status == 410) {
                $this->setInlineContent("title", "Not Found");
            }
            return;
        }

        // handle redirects from MRP side
        if ($response->isRedirect()) {
                
            if (!$response->hasHeader("Location")) {
                // redirect with no location header
                error_log( "no location: " . $response->getHeader('Location') );
                return;
            }

            // root the the location to the local site
            $location = $response->getHeader('Location');
            //error_log( "REDIRECT: " . $location );
            $location = preg_replace('@http://(.+?)/(.*)@', ( $this->isSecure() ? 'https://' : 'http://' ) .$_SERVER['HTTP_HOST'].'/$2', $location);
            
            if( substr( $location, -1 ) != "/" ) {
	            $location .= "/";
            }
            //error_log( "REDIRECT2: " . $location );
            $this->client->setResponseHeader("Location", $location);
        }
    }
    
    private function isSecure() {
		return	(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;
	}

    private function parseInlineContent()
    {
        $content = $this->getInlineContent("raw");
        if (!strlen(trim($content))) {
            $url = $this->client->getUri();
            $this->logger->error("No inline content from previous request. [$url]");
        }

        $themes = array("twentyten" => "twentyten.css", "thesis_18" => "thesis.css");
        $head   = $this->getStringBetween($content, '</TITLE>', '</head>');
        
        $head = "<meta name='x-mrp-wordpress' content='true'>" . $head;
        // if we are a virtual page, like "recip-XXX" then generate noindex as the page contents are synthetic and non-controllable by user
        if( preg_match( '/recip-[0-9]+|evow-[0-9]+/', $this->context->getPageName() ) ) {
        	$head = "<!-- (mrp listings plugin) : virtual page noindex : this page synthetic and not suitable for indexing -->\n<meta name='robots' content='noindex,follow'>\n" . $head;
        }
        
        $template = get_template();
        // bill: we dont generate the themes.css "reset" link any more, it will be generated by the application
        $body = $this->getStringBetween($content, "<body>", "</body>");
        
        // if we are using the "the_title" hook, then, the page will most likely display the title of the post already, so let's not duplicate
        // TODO: make it an option??
        $body = preg_replace( '@<h1 class=\"mrp-listing-title \".*?<\/h1>@', '', $body );
        
        $title = html_entity_decode(trim($this->getStringBetween($content, "<TITLE>", "</TITLE>")));
        $description = html_entity_decode(trim($this->getStringBetween($content,  "<META name=\"description\" content=\"", "\">")));
        $this->setInlineContent("head", $head);
        $this->setInlineContent("body", $body);
        $this->setInlineContent("title", $title);
        $this->setInlineContent("description", $description);
    }

    public function isRedirect()
    {
        if (!$this->client) {
            return false;
        }
        $response = $this->client->getResponse();
        if (!$response) {
            return false;
        }

        return $response->isRedirect();
    }

    public function getInlineContent($key)
    {
        if (isset($this->content[$key])) {
            return $this->content[$key];
        }
        return "";
    }

    public function setInlineContent($key, $value)
    {
        if (is_null($this->content) || !is_array($this->content)) {
            $this->content = array();
        }
        $this->content[$key] = $value;
        return $this;
    }

    public function getStatus()
    {
        if (!$this->client) {
            return false;
        }

        $response = $this->client->getResponse();
        return $response ? $response->getResponseCode() : 0;
    }
    
    public function outputHeaders()
    {
	    //$this->logger->debug("Outputting headers " );
        if (headers_sent()) {
            // headers have already been sent - this is an error
            $this->logger->error("Headers already sent!");
            return;
        }
        

        if (!$this->client) {
            // client hasn't been used
            $this->logger->error("Client not used? (" . __METHOD__ . ")");
            return;
        }
        

        $response = $this->client->getResponse();

        // response code header
        header("HTTP/1.1 " . $response->getResponseCode(), true );

        // Set-Cookie: headers if present
        if ($response->hasHeaders() ) {
            foreach ($response->getHeaders() as $name => $value) {
            	if( $name != "Set-Cookie" ) {
            		// we process cookies separately, due to there being multiple headers
					//$this->logger->debug($name . ": " . $value);
					header($name . ": " . $value);
            	}
            }
        }
        
        $this->outputCookieHeaders();
                
        // Location: header if required (if this is a redirect)
        if ($response->isRedirect() && $response->hasHeader("Location")) {
            header("Location: " . $response->getHeader("Location"), true);
        }
    }
    
    public function outputRegularHeaders()
    {
    	//$this->logger->debug("Outputting headers " );
        if (headers_sent()) {
            // headers have already been sent - this is an error
            $this->logger->error("Headers already sent!");
            return;
        }
        

        if (!$this->client) {
            // client hasn't been used
            $this->logger->error("Client not used? (" . __METHOD__ . ")");
            return;
        }
        

        $response = $this->client->getResponse();

        // response code header
        header("HTTP/1.1 " . $response->getResponseCode(), true );

        // Set-Cookie: headers if present
        if ($response->hasHeaders() ) {
            foreach ($response->getHeaders() as $name => $value) {
	            //$this->logger->debug(" >>" . $name . ": " . $value);
            	if( $name != "Set-Cookie" && ( $name == "Cache-Control" || $name == "Expires" ) ) {
            		// we process cookies separately, due to there being multiple headers
					$this->logger->debug("Setting header: " . $name . ": " . $value);
					//error_log( "Setting header: " . $name . ": " . $value);
					header($name . ": " . $value, true);
            	}
            }
        }

        
        $this->outputCookieHeaders();
                
        // Location: header if required (if this is a redirect)
        if ($response->isRedirect() && $response->hasHeader("Location")) {
            header("Location: " . $response->getHeader("Location"), true);
        }
    }
    
    public function outputCookieHeaders()
	{
    
    	$response = $this->client->getResponse();
        		
	    $cookies = $response->getCookies();
        //$this->logger->debug("Setting cookies: " . print_r($cookies,true) );
        foreach( $cookies as $cookie ) {
			parse_str(strtr($cookie, array('&' => '%26', '+' => '%2B', ';' => '&')), $parsed);
			if( $parsed['mrp_sort']) {
				$mod_sorted_cookie = "mrp_sort=" . $parsed["mrp_sort"] . "; Path=" . $_SERVER['REQUEST_URI'];
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

    public function getHeaders()
    {
        $headers = array();
        $response = $this->client->getResponse();
        if ($response) {
            $headers = array("HTTP/1.1 " . $response->getResponseCode());
            $headers = $headers + $response->getHeaders();
        }
        return $headers;
    }

    public function getEmbeddedFormJS()
    {
        $context = $this->context;
        $url = "//idx.myrealpage.com/wps/rest/"
            . $context->getAccountId()
            . "/l/idx2/"
            . $context->getContext()
            . "/";

        // include perm_attr if defined
        if ($context->has("permAttr")) {
            $url .= $context->getPermAttr() . ",";
        }
        $url .= "noframe~true";

        // include init_attr if defined. Otherwise use a - to maintain position.
        $url .= $context->has("init_attr") ? $context->getInitAttr() : "/-";
        $url .= "/" . $context->getSearchformDef() . ".searchform/in.js";
        return "<script id=\"mrpscript\" type=\"text/javascript\" src=\"$url\"></script>";

    }

    public function proxy($uri, $postParams = array(), $cache)
    {
        $proxy = new Proxy($this->context, $this->logger, $cache);
        $proxy->doProxy($uri, $postParams);
    }

    private function getStringBetween($string, $begin, $end)
    {
        $start = strpos($string, $begin);
        if (!$start) {
            return "";
        }
        $start += strlen($begin);
        return substr($string, $start, strpos($string, $end, $start) - $start);
    }

    private function generateUrl()
    {
        $context = $this->context;
        $url  = self::SERVER . "/wps/";
        $url .= $context->has("context") ? $context->get("context") : "";
        $url .= '/' . $context->get("accountId");
        $url .= '/';
        $url  = 'http://' . preg_replace('/(\/+)/', '/', $url);
        
        $isIDXBrowse = false;

        if ($context->has("extension") && $context->get("extension") != "/evow") {
            $url .= $context->get("extension");
        } elseif ($context->has("listingDef")) {
            $url .= "listing-page";
        } elseif ($context->has("detailsDef")) {
            $url .= "details-" . $context->get("detailsDef");
        } elseif ($context->has("detailsPhotosDef")) {
            $url .= "photos-" . $context->get("detailsPhotosDef");
        } elseif ($context->has("detailsVideosDef")) {
            $url .= "videos-" . $context->get("detailsVideosDef");
        } elseif ($context->has("detailsMapDef")) {
            $url .= "map-" . $context->get("detailsMapDef");
        } elseif ($context->has("searchformDef")) {
            // numeric (integer) and non-numeric search forms are handled differently
            $searchformDef = $context->get("searchformDef");
            if( $searchformDef == "idx.browse" ) {
	            $url .= "idx.browse";
	            $isIDXBrowse = true;
            }
            else {
	            if (ctype_digit($searchformDef)) {
	                $url .= $searchformDef . ".searchform";
	            } else {
	                $url .= "Search.form?_sf_=" . $searchformDef;
	            }
            }
        }
        
        $inqueryString = $_SERVER['QUERY_STRING'];
        
        if( $isIDXBrowse && $inqueryString ) {
	        ; // do nothing; no initAttr
        }
        else {
	        $initAttr = trim($context->get("initAttr"));
	        if (!$context->get("extension") && strlen($initAttr) && stripos($url, "?") == false) {
	            $query = preg_replace('/~/', '=', $initAttr);
	            $query = preg_replace('/,/', '&', $query);
	            $url .= '?' . $query;
	        }
        }

        
        
        if( $inqueryString ) {
	        if( stripos( $url, '?' ) ) {
		        $url .= '&' . $inqueryString;
	        }
	        else {
		        $url .= '?' . $inqueryString;
	        }
        }
        
        if ($context->has("detailsDef") ) {
	        if( stripos( $url, '?' ) ) {
		        $url .= '&noredir=true';
	        }
	        else {
		        $url .= '?noredir=true';
	        }
        }
        
        
        $this->logger->debug("Target MRP URL: " . $url );
        //error_log("Target MRP URL: " . $url );

        return $url;
    }

    private function getCookieHeader()
    {
        $cookie = "";
        
        if ( function_exists('getallheaders') && getallheaders() ) {
	        $this->logger->debug( 'RAW COOKIE HEADER: ' . getallheaders()['Cookie'] );
        }
        elseif( !function_exists('getallheaders') ) {
        	$this->logger->debug( 'RAW COOKIE HEADER: ' . $_SERVER['HTTP_Cookie'] );
        }
        
        $this->logger->debug( '$_COOKIE: ' . print_r($_COOKIE,true) . " COUNT: " . count($_COOKIE) );

        if (count($_COOKIE)) {
            foreach ($_COOKIE as $name => $value) {
            	$this->logger->debug( "COOKIE NAME: " . $name . " VALUE: " . $value );
                if (is_array($value)) {
                    continue;
                }
                $cookie .= $name . "=" . urlencode($value) . "; ";
            }
        }

        return $cookie;
    }
}

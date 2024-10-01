<?php

/**
 * Plugin Name: myRealPage IDX Listings
 * Description: Embeds myRealPage IDX and Listings solution into WordPress. Uses shortcodes. Create a post or page and use integrated shortcode button to launch myRealPage Listings Shortcode Wizard and generate a shortcode based on your choice of listing content, as well as functional and visual preferences.
 * Version: 0.9.83
 * Author: myRealPage (support@myrealpage.com)
 * Author URI: https://myrealpage.com
 **/

// PHP >= 5.4.x needed

if (PHP_MAJOR_VERSION < 5 || (PHP_MAJOR_VERSION == 5 && PHP_MINOR_VERSION < 4)) {
    die('PHP Version 5.4 or above is required for this plugin.');
}

if (!class_exists('MRPListing')) {
    class MRPListing {
        const DEBUG_PARAMETER = "__mrpdebug"; // use ?__mrpdebug=true
        const SHORTCODE_NAME  = "mrp";
        const CONFIG_LOCATION = "/wps/other/wpidx-config.json";

        // global cache object
        private $cache;

        // current configuration
        private $config;

        // logger instance
        private $logger;

        private $inlineClient;
        private $pluginName;

        // body, title, head and meta data grabbed from the inline client
        private $mrpData;
        private $blogURI;

        // current option values, loaded when plugin starts
        private $options;

        // option names
        const DEBUG_OPT_NAME     = "mrp_debug";
        const GOOGLE_MAP_API_KEY = "mrp_google_api_key";
        const CONFIG_OPT_NAME    = "mrp_config";

        private $synthetic_page = null;

        public function __construct()
        {
            $this->loadRequired();
            $this->loadOptions();

            // load configuration as an option, or if not present, grab the default
            $config = $this->getOption(self::CONFIG_OPT_NAME);
            //$config = '';
            $this->config = $config && strlen(trim($config))
                ? json_decode($config,true)
                : $this->defaultConfig();
            $this->logger = new \MRPIDX\Logger("MRP IDX", $this->getOption(self::DEBUG_OPT_NAME));
            $this->mrpData = array("head" => "", "description" => "", "title" => "", "body" => "");

            // build the cache object
            $this->cache = new \MRPIDX\DBCache(
                $this->logger,
                $this->config ? $this->config : array()
            );
            $this->pluginName = plugin_basename(__FILE__);
            $this->blogURI = get_bloginfo('url');

/*
            // create an inline client with a barebones context
            $context = new \MRPIDX\Context(
                array(
                    "debug"           => $this->options[self::DEBUG_OPT_NAME],
                    "pageName"        => $this->getPageName(),
                    "googleMapApiKey" => $this->options[self::GOOGLE_MAP_API_KEY]
                )
            );
            $this->inlineClient = new MRPIDX\InlineClient($this->logger, $context);
            */

            register_activation_hook($this->pluginName, array(&$this, 'install'));
            register_deactivation_hook($this->pluginName, array(&$this, 'uninstall'));
            $this->registerHooks();
        }

        /**
         * Loads all dependent code for the plugin.
         */
        private function loadRequired()
        {
            $files = array(
                "lib/Context.php",
                "lib/DBCache.php",
                "lib/HttpClient.php",
                "lib/InlineClient.php",
                "lib/Logger.php",
                "lib/Proxy.php",
                "lib/Response.php"
            );
            foreach ($files as $file) {
                require_once($file);
            }
        }

        public function getOption($key)
        {
            return isset($this->options[$key]) ? $this->options[$key] : "";
        }

        public function install()
        {
            $this->registerHooks();
            $this->activateCron();
            $this->flushRules();
        }

        public function uninstall()
        {
            remove_shortcode(self::SHORTCODE_NAME);

            // remove any scheduled jobs
            $this->deactivateCron();

            // regenerate rewrite rules also
            $this->flushRules();
        }

        /**
         * Anything that needs to happen asynchronously, hourly, goes in this function.
         */
        public function performHourlyTasks()
        {
            // attempt to update the internal config to the remote one
            $this->updateConfig();

            // clear the cache so we re-generate it on the next request
            $this->cache && $this->cache->clear();
        }

        public function flushRules()
        {
            global $wp_rewrite;
            $wp_rewrite->flush_rules();
        }

        public function registerHooks()
        {
            // flush rewrites on post/page save and front page option setting changes
            add_filter('save_post', array(&$this, 'flushRules'));
            add_filter('update_option_page_on_front', array(&$this, 'flushRules'));
            // add rewrite rules in so we can handle URI segments past the end of a permalink
            add_filter('generate_rewrite_rules', array(&$this, 'addRewrites'));
            add_filter('query_vars', array(&$this, 'addQueryVars'));
            add_filter( 'body_class',array(&$this, 'bodyClass') );
            // register with shortcode API to do content replacement.
            add_shortcode(self::SHORTCODE_NAME, array(&$this, 'replaceContent'));

            // init method to trap /wps/evow/ requests
            add_action('init', array(&$this, 'evowAndRecipHandler'));
            add_action('init', array(&$this, 'handleRequest'));

            // method that handles direct proxying
            add_action('parse_request', array(&$this, 'directProxy')); // 1

            // generates saved values.
            add_action('wp', array(&$this, 'replacedWP'));

            // adds merged MRP header.
            add_action('wp_head', array(&$this, 'addHeader'));

            // replace title with custom MRP one.
            add_filter('wp_title', array(&$this, 'customTitle'), 100000);
            add_filter('pre_get_document_title', array(&$this, 'customTitle'), 100000);

            // ensure we set post meta data based on the parent, for synthetic pages
            add_filter('get_post_metadata', array(&$this, 'getPostMetadata'), 99, 4);

            // this needs attention: this can be called for a variety of posts
            // within ANY url to get titles, for generating menus, etc. This
            // may be detrimental, but if enabled allows proper generation of
            // breadcrumbs, etc.
            add_filter('the_title', array(&$this, 'customTheTitle'), 1, 2 );


            add_filter('pre_get_shortlink', array(&$this, 'customShortlink'), 10, 4 );


            // custom filter for Yoast
            add_filter('wpseo_title', array(&$this,'changeYoastTitle'),100,1);
            add_filter('wpseo_metadesc', array(&$this,'changeYoastDescription'),100,1);
            //add_filter('wpseo_canonical', array(&$this,'canonicalLink'),100,1);
			add_filter('wpseo_canonical', array(&$this,'changeYoastCanonicalLink'),100,1);
            add_filter('wpseo_opengraph_title', array(&$this,'unsetYoastTagOnDetails'),10000,1);
			add_filter('wpseo_opengraph_desc', array(&$this,'unsetYoastTagOnDetails'),10000,1);
			add_filter('wpseo_opengraph_url', array(&$this,'unsetYoastTagOnDetails'),10000,1);
			add_filter('wpseo_opengraph_type', array(&$this,'unsetYoastTagOnDetails'),10000,1);


            // add debug/error logs to end of page contents
            add_action("wp_footer", array(&$this, "outputLogs"));

            // dd admin/options menu
            add_action('admin_menu', array(&$this, 'addMenu'));
            // buttons for MRP shortcode in the editor
            add_action('edit_form_advanced', array(&$this, 'addHTMLButton'));
            add_action('edit_page_form', array(&$this, 'addHTMLButton'));
            // TinyMCE button hooks
            add_filter('mce_external_plugins', array(&$this, 'addTinyMCEPlugin'));
            add_filter('mce_buttons', array(&$this, 'addTinyMCEButton'));
            // add admin javascript for button functionality.
            add_action('admin_print_scripts', array(&$this, 'loadAdminScripts'));
            // any tasks that ought to be run in the background (hourly)
            add_action("mrpidx_hourly_event_hook", array(&$this, "performHourlyTasks"));
            add_action( "admin_enqueue_scripts", array(&$this,"adminScripts" ));

			// Gutenberg Support
			if ( function_exists( 'register_block_type' ) ) {
				add_filter( 'block_categories', array(&$this,"createMrpBlocksCategory" ), 10, 2);
				require_once plugin_dir_path( __FILE__ ) . './mrp-blocks/src/init.php';
			}
		}

        function customShortlink( $shortlink, $id, $context, $allow_slugs ) {
            if( isset($this->synthetic_page) && $this->synthetic_page ) {
                return "";
            }
        	return false;
        }

        function createMrpBlocksCategory( $categories, $post ) {
            return array_merge(
                $categories,
                array(
                    array(
                        'slug' => 'mrp-blocks',
                        'title' => __( 'myRealPage Blocks', 'mrp-blocks' ),
                    ),
                )
			);
        }

        public function adminScripts() {
	        wp_register_script('mrp-sc-editor', plugins_url('mrp_sc_editor.js?v1', __FILE__), array('jquery'), '1.0.12');
        }

        public function bodyClass( $classes ) {
        	if( isset( $this->mrpData["listing_content_type"] ) && $this->mrpData["listing_content_type"] != "" ) {
	        	$classes[] = ( "mrp-listings-" . $this->mrpData["listing_content_type"] );
        	}

	        return $classes;
        }

        public function addMenu()
        {
            add_options_page('mrpWPIdx', 'myRealPage Plugin', 'manage_options', __FILE__, array(&$this, 'optionsPage'));
        }

        /**
         * Loads option values from WP, adding any that don't exist.
         **/

        public function loadOptions()
        {
            $options = array(self::DEBUG_OPT_NAME => false, self::GOOGLE_MAP_API_KEY => '', self::CONFIG_OPT_NAME => '');

            foreach ($options as $name => $default) {
                $opt = get_option($name);
                if ($opt == false || $opt == '') {
                    $this->options[$name] = $default;
                    add_option($name, $default);
                } else {
                    $this->options[$name] = $opt;
                }
            }

            // if the request contains the right parameter, enable debugging for this request only
            if (isset($_REQUEST["__mrpdebug"]) && $_REQUEST["__mrpdebug"]) {
                $this->options[self::DEBUG_OPT_NAME] = true;
            }
        }

        /**
         * Adds button to default HTML editor.
         **/

        public function addHTMLButton()
        {
            $button = '<input type="button" id="mrp-shortcode" class="ed_button" title="myRealPage shortcodes" value="Listing Shortcodes" onClick="return mrp_openSC(this);"/>';
            echo $this->getButtonAction($button);
        }

        private function getButtonAction($button)
        {
            return "<script type=\"text/javascript\">"
            . "jQuery(document).ready(function(){"
            . "jQuery(\"#ed_toolbar\").append('$button');"
            . "});"
            . "</script>";
        }

        /**
         * Adds button to TinyMCE editor (Visual)
         **/

        public function addTinyMCEButton($buttons)
        {
            array_push($buttons, "mrpShortCode");
            return $buttons;
        }

        public function addTinyMCEPlugin($plugins)
        {
            $plugins['mrplisting'] = plugins_url('/tinymce/editor.js', __FILE__);
            return $plugins;
        }

        public function loadAdminScripts()
        {
            wp_enqueue_script('mrp-sc-editor');
        }

        /**
         * @todo: remove from the plugin
         **/
        public function addRewrites($wp_rewrite)
        {
            return $wp_rewrite->rules;
        }

        /**
         * Add the extension query variable in so we can pass it around.
         **/
        public function addQueryVars($current)
        {
            $current[] = 'extension';
            return $current;
        }

        /**
         * Header changes.
         **/
        public function addHeader()
        {
            if (isset($this->mrpData["head"]) && !empty($this->mrpData["head"])) {
                echo $this->mrpData["head"];
                $regex = isset($this->config["replaceable_titles"]) ? $this->config["replaceable_titles"] : "";
				if( $regex != "" && preg_match($regex, $_SERVER['REQUEST_URI']) && isset($this->mrpData["title"]) ) {
                	echo("<meta name=\"description\" content=\"" . $this->mrpData["description"] . '"/>');
                }
            }
        }

        /**
        * Yoast filters
        **/
        public function unsetYoastTagOnDetails($data) {
			if( isset($this->synthetic_page) && $this->synthetic_page ) {
				return false;
			}
			return $data;
		}
        public function changeYoastDescription($desc) {
	        $regex = isset($this->config["replaceable_titles"]) ? $this->config["replaceable_titles"] : "";
			// should we really only care about the synthetic pages anyways??
			if( isset($this->synthetic_page) && $this->synthetic_page /*&& $regex != "" && preg_match($regex, $_SERVER['REQUEST_URI'])  */ && isset($this->mrpData["title"]) ) {
            	return $this->mrpData["title"];
            }
            return $desc;
        }
		
		public function changeYoastTitle($title) {
	        $regex = isset($this->config["replaceable_titles"]) ? $this->config["replaceable_titles"] : "";
			// should we really only care about the synthetic pages anyways??
			if( isset($this->synthetic_page) && $this->synthetic_page /* && $regex != "" && preg_match($regex, $_SERVER['REQUEST_URI']) */ && isset($this->mrpData["title"]) ) {
            	return $this->mrpData["title"];
            }
            return $title;
        }

		public function changeYoastCanonicalLink($link) {
	        global $wp;
			$regex = isset($this->config["replaceable_titles"]) ? $this->config["replaceable_titles"] : "";
			// should we really only care about the synthetic pages anyways??
			if( isset($this->synthetic_page) && $this->synthetic_page /* && $regex != "" && preg_match($regex, $_SERVER['REQUEST_URI']) */ && isset($this->mrpData["title"]) ) {
				$out = home_url( $wp->request );
				if( stristr( $out, '/SearchResults.form' ) ) {
					$out = str_ireplace( '/SearchResults.form', '/', $out );
				}
            	$out = substr($out,-1) === "/" ? $out : ( $out . "/");
				if( isset($_GET["_pg"])) {
					$out .= "?_pg=" . $_GET["_pg"];
				}
				return $out;
            }
			if( stristr( $link, '/SearchResults.form/' ) ) {
				$link = str_ireplace( '/SearchResults.form/', '/', $link );
			}
			if( stristr( $link, '/SearchResults.form' ) ) {
				$link = str_ireplace( '/SearchResults.form', '/', $link );
			}
			
            return $link;
        }

        public function canonicalLink($canonical) {
        	// future place for tweaking YOAST canonical URLs if necessary
        	//error_log( 'CANONICAL CALLED ' . $canonical );
	        return $canonical;
        }

        public function customTheTitle($title, $id = null)
        {
        	if( $id == PHP_INT_MAX ) { // we have our synthetic page

        		// special case for /evow/, we also make sure that we are not in listing details, in which case other rules apply (i.e. customTitle() check)
        		if( preg_match( '/.*\/evow\/.*/i', $_SERVER['REQUEST_URI'] ) && $this->customTitle($title) == $title ) {
	        		return 'Found Listings';
        		}

        		// special case for navigation:
				if( preg_match( '/.*\/searchresults\.form.*/i', $_SERVER['REQUEST_URI']) ) {
	    			if( $_GET['_pg'] != '' ) {
	        			return $title . ' [p.' . $_GET['_pg'] . ']';
	    			}
	    			else {
	    				return $title . '';// ' [results]';
	    			}
				}

				// make sure we are responsible for the title as well
				$regex = isset($this->config["replaceable_titles"]) ? $this->config["replaceable_titles"] : "";
				if( $regex != "" && preg_match($regex, $_SERVER['REQUEST_URI']) && isset($this->mrpData["title"]) ) {
	        		return $this->mrpData["title"];
        		}
	        	//error_log( "SYNTHETIC TITLE!!!: " . $this->mrpData["title"] );
        	}
            return $title;
        }


        public function customTitle($title)
        {
            $regex = isset($this->config["replaceable_titles"]) ? $this->config["replaceable_titles"] : "";
            if (isset($this->mrpData["title"]) && $regex != "" && preg_match($regex, $_SERVER['REQUEST_URI'])) {
                return $this->mrpData["title"];
            } else {
                return $title;
            }
        }

        /* bill hacking */
        /* we need to skip the "embed" (do js embed) logic if we have a POST
          request, which will come in from the "quick search" widget, and also
          if we have an 'extended' URL on our hands, like '/SearchResults.form...'
          (unless of course it's /Search.form, in which case the user may have clicked
          on "Modify Search" button)
          This should support users initiating search from "quick search" widgets, browsing
          results, then clicking on "Modify Search" button and ending up with embedded form
          again
        */
        private function skipEmbed()
        {
            if ($_SERVER['REQUEST_METHOD'] == 'POST' ||
                ($_SERVER['REQUEST_URI'] &&
                    $this->isManagedUrl($_SERVER["REQUEST_URI"]) &&
                    strstr($_SERVER['REQUEST_URI'], '/Search.form') == false)
            ) {
                return true;
            } else {
                return false;
            }
        }

        public function nocacheHeaders()
        {
	        header( "Cache-Control: no-store, no-cache, must-revalidate, proxy-revalidate, max-age=0" );
	        header( "Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT" );
        }

        /*
        this function is necessary to move ibf_xxx type parmeters from init_attr to perm_attr
        for the idx browse to function properly. This mistake was made early on and currently there
        would be many shortcodes with the params set in the init_attr instead of perm_attr
        this is a retrofit fix.
        */
        public function fixIdxBrowseAttrs( $attrs ) {
			if( empty($attrs) ) {
				return $attrs;
			}
			$initAttrs = isset($attrs["init_attr"]) ? $attrs["init_attr"] : "";
			if( empty($initAttrs) ) {
				return $attrs;
			}
			$initAttrsArray = explode( ",", $initAttrs );
			if( empty($initAttrsArray) ) {
				return $attrs;
			}
			
			$permAttrs = isset($attrs["perm_attr"]) ? $attrs["perm_attr"] : "" ;
			
			$initAttrsOut = [];
			
			foreach( $initAttrsArray as $attr ) {
				if( strpos( $attr, "ibf_" ) == 0 ) {
					if( !empty($permAttrs) ) {
						$permAttrs = $permAttrs . ",";
					}
					$permAttrs = $permAttrs . $attr;
				}
				else {
					array_push($initAttrsOut, $attr );
				}
			}
			//error_log( "To fix init_attr " . print_r($initAttrsArray,true));
			//error_log( "new init attr " . join(",", $initAttrsOut ) );
			//error_log( "new perm attr " . $permAttrs );
			
			$attrs["init_attr"] = join(",", $initAttrsOut );
			$attrs["perm_attr"] = $permAttrs;
			
			return $attrs;
		}

        public function replacedWP($wp)
        {
            global $wp_query, $post;

            // if the admin is currently loaded, we don't do any work
            if (is_admin()) {
                return;
            }

            // error_log( "replacedWP" . print_r( $post, true ) );

            // ensure that we use synthetic page for the post if present
			if( /* !isset( $post ) && */ isset( $this->synthetic_page) ) {
				// a double ensurance; for some reason our synthetic post
				// was not made global; let's set it here
				$synthetic = $this->synthetic_page->detectPost([]);
				$post = $synthetic[0];
			}

            // check whether we have an MRP shortcode, and process it
            $this->debug( "Has shortcode: " . ( is_object($post) && property_exists($post, 'post_content') && has_shortcode($post->post_content, 'mrp') ? "Yes" : "No" ) );
            if ( isset($post) && has_shortcode($post->post_content, 'mrp')) {

            	// add trailing slash if necessary; otherwise listing details don't work
				$permalinkStructure = get_option("permalink_structure");
                if ( $_SERVER['REQUEST_METHOD'] == 'GET' && $permalinkStructure && substr($permalinkStructure, -1) == "/") {

                	$parts = explode('?', $_SERVER['REQUEST_URI'], 2);
                	if (substr($parts[0], -1) !== '/') {
                		error_log( 'TRAILING SLASH FIX: ' . $_SERVER['REQUEST_URI'] );
					    $uri = $parts[0].'/'.(isset($parts[1]) ? '?'.$parts[1] : '');
					    header('Location: '.$uri, true, 302);
					    die();
					}
                }


                // extract just the 'mrp' shortcode, parse attributes and create a context object
                $hit = preg_match('/' . get_shortcode_regex(array('mrp')) . '/', $post->post_content, $matches);
                $this->debug("Shortcode found: " . ($hit ? "yes" : "no" ));
                $attrs = shortcode_parse_atts($matches[0]);

                unset($attrs[0]);
                unset($attrs[1]);

                $cleanAttributes = function ($value) {
                    return preg_replace('/[\[\]]/', "", $value);
                };
                $attrs = array_map($cleanAttributes, $attrs);
                $this->debug("Parsed Shortcode: " . print_r($attrs, true));
                //error_log("Parsed Shortcode: " . print_r($attrs, true));
                $attrs = $this->fixIdxBrowseAttrs( $attrs );
                $attrs = $attrs +
                    array(
                        "pageName"        => $this->getPageName(),
                        "extension"       => $this->getExtension($wp_query),
                        "debug"           => $this->getOption(self::DEBUG_OPT_NAME),
                        "googleMapApiKey" => $this->getOption(self::GOOGLE_MAP_API_KEY)
                    );

				//error_log( "$attrs: ". print_r( $attrs, true ) );
                $context = new \MRPIDX\Context($attrs);

                //error_log( "ATTS: " . print_r( $attrs, true ) );
                if( isset($attrs["searchform_def"]) && $attrs["searchform_def"] != "" &&
                	$attrs["searchform_def"] != "idx.browse" &&
                	( !isset($attrs["extension"]) || $attrs["extension"] == "" )) {
	                // no remote call on search form IDX
	              	return;
                }


                $client = new \MRPIDX\InlineClient($this->logger, $context);
                $client->processInline();
                if ($client->isRedirect()) {
                    // handle redirection
                    $client->outputHeaders();
                    die();
                }

                // populate ourselves for when we actually have to produce content
                $this->mrpData = array(
                    "title" => $client->getInlineContent("title"),
                    "body"  => $client->getInlineContent("body"),
                    "head"  => $client->getInlineContent("head"),
                    "description"  => $client->getInlineContent("description"),
                    "listing_content_type" => $client->getInlineContent("listing_content_type")
                );

                $this->debug("Parsed Content: " . print_r($this->mrpData, true));
                //$this->debug("Response Headers: " . print_r($client->getHeaders(), true));
                $client->outputRegularHeaders();
                $this->nocacheHeaders();

            }
        }

        private function getExtension($wp_query)
        {
            $extension = isset( $wp_query->query_vars['extension'] ) ? trim($wp_query->query_vars['extension']) : "";
            if (!strlen($extension)) {
                // no extension as a query var, so parse it from the current URI
                list($slug, $extension) = $this->processManagedUrl($_SERVER["REQUEST_URI"]);
            }
            return $extension;
        }

        /**
         * Main functionality - extracts data from shortcode, uses the inline client
         * to fetch the required data from MRP and displays the returned content.
         **/
        public function replaceContent($attrs, $content = '')
        {
        	global $wp_query;
        	$ext = $this->getExtension($wp_query);
            if (isset($attrs["searchform_def"]) && $attrs["searchform_def"] != "" &&
            		$attrs["searchform_def"] != "idx.browse" &&
                	( !isset($ext) || $ext == "" )) {
                // create a client for operating within the new (local) context
                //$client = new \MRPIDX\InlineClient($this->logger, new \MRPIDX\Context($attrs));
                //return $client->getEmbeddedFormJS();
                //error_log( "replaceContent $attrs: ". print_r( $attrs, true ) );
                //error_log( "replaceContent: bypass for searchform_def" );

                $script1= "\n<script src='//" . \MRPIDX\InlineClient::RES_SERVER .
                	"/wps/rest/" . $attrs["account_id"] . "/l/recip/tmpl2.js'></script>\n";
                $script2 = "<script src='//" . \MRPIDX\InlineClient::RES_SERVER .
                	"/wps/js/ng/v2/listings/listings-wp-button.js' id='idx-button-script' data-account='" .
                		$attrs["account_id"] . "' data-init-attr='" . ($attrs["init_attr"] ? $attrs["init_attr"] : "" ) . "'></script>\n";

                return $script1 . $script2;

            } else {
                $content = $this->mrpData["body"];
                return $content;
            }
        }

        public function outputLogs()
        {
            $content = '';
            // if we have errors, put those into the body
            $errors = $this->logger->getErrors(true);
            if (strlen($errors)) {
                $content .= "<!-- MRP WPIDX ERRORS -->\n";
                $content .= "<!-- $errors -->\n";
            }

            // if we have debug messages, put those into the body
            $debug = $this->logger->getNotifications(true);
            if (strlen($debug)) {
                $content .= "<!-- MRP WPIDX DEBUG -->\n";
                $content .= "<!-- PHP VERSION: " . phpversion() . " -->\n";
                $content .= "<!-- $debug -->\n";
            }
            echo $content;
        }

        public function handleRequest($wp_query)
        {
            global $wpdb;
            $uri  = $_SERVER["REQUEST_URI"];

            $this->logger->debug( "This is managed URL: ". $uri . "|" . $this->isManagedUrl($uri) );
            error_log( "This is managed URL: ". $uri . "|" . $this->isManagedUrl($uri) );

            // redirect URLs with "/l/" from the old plugin
			if( strstr( $uri, "/l/" ) && !strstr( $uri, "/wps/" ) && !strstr( strtolower($uri), "/unibox.search" ) ) {
				header('Location: ' . str_replace( "/l/", "/", $uri ) );
				die();
			}

            // nothing to do if this isn't a managed URL
            if (!$this->isManagedUrl($uri) || strstr($uri, "/gmform15/")) {
                return;
            }

            // strip off the extension part, and grab our page name from the slug
            list($pagename, $extension) = $this->processManagedUrl($uri);
            error_log( "handleRequest: " . $pagename . ":" . $extension );

            $searchname = $pagename;

            // in case we get a subpage, i.e. something/somewhere as $pagename, use the last segment
            if( strripos( "$pagename", '/' ) ) {

	            $searchname = substr( $pagename, strripos( "$pagename", '/' ) + 1 );
	            //error_log( "SEARCHNAME: ". $searchname );

	            // ensure we don't have false nested URLs, like '/foo/listings/listings/listings/listing-details.xyz
				if( preg_match( "@($searchname\/){1,}@", $pagename . '/', $matches ) ) {
					// if we matched $matches[0] != $matches[1], i.e. listings/listings != listings
					// do a replace and redirect
					if( $matches[0] != $matches[1] ) {
						$redir = str_replace( $matches[0], $matches[1], $uri );
						error_log( 'REDIRECTING URL WITH BAD NESTING: ' . $uri );
						header( 'Location: ' . $redir );
						die();
					}
				}
			}

            // find the page, based on page name
            //$query  = $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_name=%s", $searchname);
            //$result = $wpdb->get_results($query, OBJECT_K);

            $this->logger->debug( "searchname: " . $searchname );
            $this->logger->debug( "pagename: " . $pagename );
            //$this->logger->debug( "result: " . print_r( $result, true ) );

            $page_by_slug = null;
            if( $pagename ) {
                $page_by_slug = get_page_by_path( $pagename, OBJECT, 'page' );
                if( !$page_by_slug ) {
                    $page_by_slug = get_page_by_path( $pagename, OBJECT, 'post' );
                    if( !$page_by_slug ) {
                        // looking up pages by path is the correct way to go about it as pages may be some/page
                        // however, it seems in some cases pages displaying under /some/page/ are not actually lookup-able
                        // by the path; here is our fallback on the post_name in such cases; in this last case
                        // there cannot be /some/post and other/post as the page is looked up by "post" ;
                        $this->logger->debug( "Falling back onto manual search." );
                        $query  = $wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_name=%s", $searchname);
                        $found = $wpdb->get_results($query, OBJECT_K);
                        if( count($found) ) {
                            $page_by_slug = reset($found);
                            $this->logger->debug( "found page via direct SQL and : " . $searchname );
                        }
                    }
                }
            }
            
            $this->logger->debug( "page_by_slug: " . print_r( $page_by_slug, true ) );
            //error_log( "page_by_slug: " . print_r( $page_by_slug, true ) );

            //if (count($result)) {
			if( $page_by_slug ) {

				$result = $page_by_slug;

                // generate content for this page using the "parent" page
                require_once("fakepage.php");
                //$result     = reset($result); // first element in array
                $requestUri = $_SERVER["REQUEST_URI"];
                $slug       = substr($requestUri, 1);

                if (stripos($requestUri, '?')) {
                    $slug = substr($slug, 0, stripos($requestUri, '?') - 1);
                }
                //error_log( "DB RESULT: " . print_r( $result, true ) );
                $context = array(
                    "post_parent" => $result->ID,
                    "post_type"   => $result->post_type
                );

                $content = $result->post_content;

                /*
                this is a TODO: we should create a setting to make details pages "greedy", then we
                strip all other content except for our shortcode when crafting the synthetic page for the details.
                Uncomment below 2 lines to enable greedy
                */
				// extract just the 'mrp' shortcode, parse attributes and create a context object
                $hit = preg_match('/' . get_shortcode_regex(array('mrp')) . '/', $result->post_content, $matches);
                if( $hit) {
					$attrs = shortcode_parse_atts($matches[0]);
					if( isset($attrs["greedy"]) && stripos($attrs["greedy"], "true") !== false) {
						$content = $matches[0];
					}
				}


                $synthetic = new FakePage($slug, $result->post_title, $content, $context );
                $this->synthetic_page = $synthetic;

                return $synthetic;
            } else {

                // @todo: necessary? if the permalink structure needs it, add a trailingslash
                $permalinkStructure = get_option("permalink_structure");
                if ($permalinkStructure && substr($permalinkStructure, -1) == "/") {
                    $pagename = trailingslashit($pagename);
                }

                set_query_var('extension', $extension);
                set_query_var('pagename',  $pagename);
                set_query_var('name',      $pagename);
            }
        }

        /*
         * @param string|array $metadata - Always null for post metadata.
         * @param int $object_id - Post ID for post metadata
         * @param string $meta_key - metadata key.
         * @param bool $single - Indicates if processing only a single $metadata value or array of values.
         * @return Original or Modified $metadata.
         */
        public function getPostMetadata($meta_value, $object_id, $meta_key, $single)
        {
            global $post;

            // check for synthetic page, and use parent post's meta data
            // NOTE: WordPress gives us the absolute value of the post ID in $object_id,
            //       and we are expecting -1 - use $post->ID as well, as it is more reliable
            if ($post && $object_id == PHP_INT_MAX && $post->ID == PHP_INT_MAX && $post->post_parent) {
                return get_metadata('post', $post->post_parent, $meta_key, $single);
            }

            return $meta_value;
        }

        private function processManagedUrl($url)
        {
        	if (stripos($url, '?')) {
                $url = substr($url, 0, stripos($url, '?'));
            }

            //error_log( "processManagedUrl: ". $url );

            $regexes = $this->config && isset($this->config["managed_urls"]) ? $this->config["managed_urls"] : array();
            foreach ($regexes as $regex => $cachable) {
                // modify the regex to break out the slug and extension (if present)
                $regex = "/^(?P<slug>.+?)(?P<extension>$regex)/i";
                if (preg_match($regex, $url, $matches)) {
                	$ext = $matches["extension"];
                	if( strpos( $ext, "/l/" ) == 0 ) {
	                	$ext = str_replace( "/l/", "/", $ext );
                	}
                	//error_log( "processed extension: ". $ext );

                    return array(
                        $this->stripLeadingSlash($matches["slug"]),
                        $this->stripLeadingSlash($ext)
                    );
                }
            }
            return false;
        }

        private function stripLeadingSlash($str)
        {
            if (substr($str, 0, 1) == "/") {
                return substr($str, 1);
            }
            return $str;
        }

        private function isManagedUrl($url)
        {
            $regexes = $this->config && isset($this->config["managed_urls"]) ? $this->config["managed_urls"] : array();
            foreach ($regexes as $regex => $cached) {
                if ($regex && preg_match("/$regex/i", $url)) {
                	$this->debug( "Matched managed URL expression: ". $regex );
                	//error_log( "Matched managed URL expression: ". $regex );

		            // check that exactly this page exists and has a shortcode
		            // e.g. /mls-search/ may be confused with /mls-R786555/ managed URL
		            $urlpathonly = strtok($url,'?');
		            //error_log( "URL PATH: " . $urlpathonly );
		            $page_by_slug = get_page_by_path( $urlpathonly, OBJECT, 'page' );
		            //error_log( 'LOOKED UP SLUG DOUBLE CHECKING MANAGED URL: ' . print_r( $page_by_slug, true ) );
		            if( !$page_by_slug ) {
			            $page_by_slug = get_page_by_path( $urlpathonly, OBJECT, 'post' );
			            //error_log( 'LOOKED UP SLUG 2 LOOKED UP SLUG DOUBLE CHECKING MANAGED URL: ' . print_r( $page_by_slug, true ) );
			            if( !$page_by_slug ) {
							return true;
						}
					}

					if ( isset($page_by_slug) /* && has_shortcode($page_by_slug->post_content, 'mrp') */) {
						//error_log( "BAILING FROM SEEMINGLY MANAGED URL WHICH EXISTS AS PAGE / POST: " . $urlpath );
						return false; // we thought we were managed URL; but a real page exists with shortcode
					}
                }
            }
            return false;
        }

        public function evowAndRecipHandler()
        {
            $requestUri = $_SERVER['REQUEST_URI'];

            // do not allow URLs containing /1234.search/ it messes up google indexing
            if( preg_match( '@^.+/\d+\.search/.*@', $requestUri ) ) {
            	$redir = preg_replace( '@^(.+)/\d+\.search/(.*)@', '$1/', $requestUri );
	            //error_log( "PREDEF REQUEST URI HIT : " . $requestUri );
	            //error_log( "PREDEF REQUEST REDIRECT : " . $redir );
	            header("HTTP/1.1 301 Moved Permanently");
	            header('Location: ' . $redir );
                die();

            }

            // issue a redirect if we're seeing /wps/evow/ACCOUNT_ID/ExternalView.form?
            if (preg_match('@^/wps/evow/\d+/ExternalView.form?@', $requestUri)) {
                preg_match('@^/wps/evow/(.*)@', $requestUri, $matches);
                if (isset($matches[1])) {
                    header('Location: /evow-' . $matches[1]);
                    die();
                }
            }

            // issue a redirect if we are seeing /wps/recip/XX/idx.search
            // this may happen if a vow search is loaded for editing
            // Also: only GET check, because 'POST' is used for actual searching
            if ( $_SERVER['REQUEST_METHOD'] == 'GET' && preg_match('@^/wps/recip/\d+/(.+.search|search.form)@i', $requestUri) &&
            	!preg_match('@^/wps/recip/\d+/idx.browse@i', $requestUri) ) {

                preg_match('@^/wps/recip/(.*)@', $requestUri, $matches);
                if (isset($matches[1])) {
                	//header("HTTP/1.1 301 Moved Permanently");
                    header('Location: /recip-' . $matches[1]);
                    die();
                }
            }

            // empty '/recip-xxx' or /recip-xxx/ -> redirect to /recip-xxx/idx.search
            if( preg_match( '@^/recip\-(\d+)[/]{0,1}$@', $requestUri, $matches ) ) {
	            //error_log( "----------- HITT 000" );
	            header('Location: /recip-' . $matches[1] . "/idx.search/" );
                die();
            }

            if (preg_match('@^/evow-\d+@', $requestUri)) {
                require_once('fakepage.php');
                $slug = substr($requestUri, 1);
                if (stripos($requestUri, '?')) {
                    $slug = substr($slug, 0, stripos($requestUri, '?') );
                }
                preg_match('@^/evow-(\d+)/.+@', $requestUri, $matches);
                if (isset($matches[1])) {
                    // generate shortcode for fake page
                    new FakePage($slug, '  ', '[mrp context=evow account_id=' . $matches[1] . ']');
                } else {
                    new FakePage($slug, '  ', '<p>Malformed URL (no account ID given)</p>');
                }
            }

            else if( preg_match('@^/recip-(\d+)/idx\.browse[/]{0,1}@', $requestUri, $matches) ) {

	            //error_log( "--------------- HITT BROWSE" );
	            require_once('fakepage.php');
                $slug = substr($requestUri, 1);
                if (stripos($requestUri, '/?')) {
                    $slug = substr($slug, 0, stripos($requestUri, '/?') );
                }
                if (stripos($requestUri, '?')) {
                    $slug = substr($slug, 0, stripos($requestUri, '?') );
                }
				if (isset($matches[1])) {
                    // generate shortcode for fake page
                    new FakePage($slug, '  ', '[mrp context=recip account_id=' . $matches[1] . ' searchform_def=idx.browse]');
                } else {
                    new FakePage($slug, '  ', '<p>Malformed URL (no account ID given)</p>');
                }
            }
            else if (preg_match('@^/recip-\d+@', $requestUri)) {
	            //error_log( "--------------- HITT" );
                require_once('fakepage.php');
                $slug = substr($requestUri, 1);
                if (stripos($requestUri, '/?')) {
                    $slug = substr($slug, 0, stripos($requestUri, '/?') );
                }
                if (stripos($requestUri, '?')) {
                    $slug = substr($slug, 0, stripos($requestUri, '?') );
                }
                preg_match('@^/recip-(\d+)/.+@', $requestUri, $matches);
                if (isset($matches[1])) {
                    // generate shortcode for fake page
                    new FakePage($slug, '  ', '[mrp context=recip account_id=' . $matches[1] . ']');
                } else {
                    new FakePage($slug, '  ', '<p>Malformed URL (no account ID given)</p>');
                }
            }
        }

        public function directProxy($wp)
        {
            // get current request URI and determine if we need to proxy it
            $requestUri = $_SERVER['REQUEST_URI'];

            if( preg_match( '/^\/wps\/evow\//', $requestUri ) ) {
	            return;
            }

            // no proxying to do, so we're done
            if (!preg_match('/^(\/wps\/|\/mrp\-js\-listings\/|\/gmform15\/|\/recip\-[0-9]+\/idx\.search)/', $requestUri)) {
                return;
            }

            //error_log( "INLINE ROOT IN PROXY: " . $_SERVER['HTTP_MRPINLINEROOT'] );


			$context = new \MRPIDX\Context(
                array(
                    "debug"           => $this->options[self::DEBUG_OPT_NAME],
                    "pageName"        => $this->getPageName(),
                    "googleMapApiKey" => $this->getOption(self::GOOGLE_MAP_API_KEY)

                )
            );
            $client = new MRPIDX\InlineClient($this->logger, $context);

			preg_match('@^/recip-(\d+)/.*@', $requestUri, $matches);
	        if (isset($matches[1])) {
	        	$requestUri = "/wps/-/noframe~1,tmpl~v2/recip/" . $matches[1] . "/idx.search";
	        	$inqueryString = $_SERVER['QUERY_STRING'];
				if( $inqueryString ) {
					$requestUri .= "?" . $inqueryString;
				}
	        	//error_log( "IDX_SEARCH URL: " . $requestUri );
            }

            //error_log("Direct Proxying: " . $requestUri);

            //  use raw POST data from stdin rather than the $_POST superglobal
            $client->proxy(
                $requestUri,
                $_SERVER['REQUEST_METHOD'] == 'POST' ? file_get_contents("php://input") : array(),
                $this->cache
            );

            exit();
        }

        /**
         * Ouputs HTML and handles form processing on options page within the WP admin.
         **/
        public function optionsPage()
        {
            // check for options update
            $field = 'mrp_submit_hidden';
            if (isset($_POST[$field]) && $_POST[$field] == 'Y') {
                update_option(self::DEBUG_OPT_NAME, $_POST[self::DEBUG_OPT_NAME]);
                update_option(self::GOOGLE_MAP_API_KEY, $_POST[self::GOOGLE_MAP_API_KEY]);
                $this->loadOptions();
            }

            // refresh remote config button pressed
            $field = 'mrp_refresh_config';
            if (isset($_POST[$field]) && $_POST[$field] == 'Y') {
                // reload the remote configuration
                $this->updateConfig();
            }

            // clear cache button pressed
            $field = 'mrp_clear_cache';
            if (isset($_POST[$field]) && $_POST[$field] == 'Y') {
                $this->cache && $this->cache->clear();
            }

            // download current logs to file
            $field = 'mrp_get_logs';
            if (isset($_GET[$field]) && $_GET[$field] == 'Y') {
                $logs = $this->logger->getMessages();
                ob_start();
                include(plugin_dir_path(__FILE__) . "/views/logs.php");
                ob_end_flush();
            }

            // clear logs
            $field = 'mrp_clear_logs';
            if (isset($_POST[$field]) && $_POST[$field] == 'Y') {
                $this->logger && $this->logger->clear();
            }

            // output current settings page
            ob_start();
            include(plugin_dir_path(__FILE__) . "/views/settings.php");
            ob_end_flush();
        }

        /**
         * Convenience method for debugger messages.
         *
         * @param $message
         */
        private function debug($message)
        {
            if ($this->getOption(self::DEBUG_OPT_NAME) && $this->logger) {
                $this->logger->debug($message);
            }
        }

        private function getPageName()
        {
            global $post;

			$url = $_SERVER["REQUEST_URI"];

            // extract the page name based on either the post permalink, or if this is an MRP-managed
            // URL, remove the extension first
            $pageName = "";

            if( isset( $post ) ) {
            	$pageName = substr(str_replace($this->blogURI, '', get_permalink($post->ID)), 1);
            }

            if ($this->isManagedUrl($url)) {
                list($pageName, $extension) = $this->processManagedUrl($url);
                //error_log( $pageName . ":" . $extension . ":" . $url );
            }


            if ($pageName != null && substr($pageName, -1) == '/') {
                $pageName = substr($pageName, 0, strlen($pageName) - 1);
            }
            return $pageName;
        }

        private function activateCron()
        {
            wp_schedule_event(time(), "hourly", "mrpidx_hourly_event_hook");
        }

        private function deactivateCron()
        {
            wp_clear_scheduled_hook("mrpidx_hourly_event_hook");
        }

        private function defaultConfig()
        {
            // default configuration
            $config = array();
            $config["version"] = "0.0";
            $config["managed_urls"] = array(
			    '\/externalview\.form'           => false,
			    '\/evow\/.*'                     => false,
			    '\/browse\/.*'                   => false,
			    '^\/wps\/'                       => false,
			    '\/[0-9]+\.search.*'             => false,
			    '\/[0-9]+\.vowsearch.*'          => false,
			    '\/vowcategory\.form.*'          => false,
			    '\/idx\.search'                  => false,
			    '\/listing\..*'                    => false,
			    '\/searchresults\.form'          => false,
			    '\/unibox\.search'               => false,
			    '\/search\.form'                 => false,
			    '\/details-[0-9]+'               => false,
			    '\/mls-[0-9a-zA-Z]+'               => false,
			    '\/photos-[0-9]+'                => false,
			    '\/videos-[0-9]+'                => false,
			    '\/floor-plans-[0-9]+'           => false,
			    '\/map-[0-9]+'                   => false,
			    '\/print-[0-9]+'                 => false,
			    '\/listingdetails\.form'         => false,
			    '\/listingphotos\.form'          => false,
			    '\/listingvideos\.form'          => false,
			    '\/listingfloorplans\.form'      => false,
			    '\/listinggooglemap\.form'       => false,
			    '\/listingwalkscore\.form'       => false,
			    '\/gmform15\/(js|dist|font)\/.*' => true
			);
            // regex for URL patterns where we do title replacement
            $config["replaceable_titles"] = '@.*/(listing\..+|details\-|mls\-|photos\-|videos\-|map\-|walkscore\-|'
                . 'print\-|ListingPrint\.form|ListingWalkScore\.form|'
                . 'ListingVideos\.form|ListingPhotos\.form|'
                . 'ListingDetails\.form|ListingGoogleMap\.form|'
                . 'VowLanding\.form|VowSaveSearch\.form|'
                . 'VowCategory\.form|.+\.vowsearch).*$@';

            return $config;
        }

        /**
         * Performs remote fetch of config file, and subsequent updates. Written as a public function so it can be
         * called via WP's cron mechanism.
         */
        public function updateConfig()
        {
        	// fetch the file
            $client = new \MRPIDX\HTTP\Client( "http://" . MRPIDX\InlineClient::SERVER . self::CONFIG_LOCATION);
            $client->makeRequest();
            $response = $client->getResponse();

            // we only update on a 200
            if ($response->getResponseCode() == 200) {
                $content = $response->getContent();
                $json = json_decode($content,true);
                if (json_last_error() != JSON_ERROR_NONE) {
                    //$this->logger->warn("Config JSON is invalid: " . self::CONFIG_LOCATION);
                    //error_log("Config JSON is invalid: " . self::CONFIG_LOCATION . " : " . json_last_error() );
                    return;
                }
                $config = $json;
                //$config  = get_object_vars($json);

                // if the config we downloaded is newer, update
                $current = isset($this->config["version"]) ? $this->config["version"] : "0.0";
                if (isset($config["version"]) && version_compare($current, $config["version"]) < 0) {
                    $this->config = $config;
                    update_option(self::CONFIG_OPT_NAME, $content);
                    $this->logger->debug("Updating config from " . $current . " to " . $config["version"]);
                }
                else {
	                //error_log( "Config update skipped: version same or lower: " . $config["version"] );
                }
            } else {
                $this->logger->warn("Could not update configuration from: " . self::CONFIG_LOCATION);
            }
        }
    }

    $mrp = new MRPListing();
}

// ---------------------------------------------------------------------
// ---------------------- production specific --------------------------
// ---------------------------------------------------------------------
require 'plugin-update-checker.php';
$myUpdateChecker = Puc_v4_Factory::buildUpdateChecker(
    'https://raw.githubusercontent.com/myrealpagedev/myRealPage-wordpress-plugin/master/details.json',
    __FILE__, //Full path to the main plugin file or functions.php.
    'myRealPage-wordpress-plugin'
);

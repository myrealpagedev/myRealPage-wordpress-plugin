<?php

/**
 * Database cache for proxied resources. Uses the transients API.
 */

namespace MRPIDX;

class DBCache
{
    protected $logger;
    protected $tableName;
    protected $expiry;
    protected $cacheableResources;
    protected $dbEnabled;

    public function __construct($logger, $config = array())
    {
        $this->dbEnabled = false;
        $this->logger = $logger;
        $this->tableName = "mrpidxcache";
        $this->checkDb();

        // extract cacheable regexes
        $this->cacheableResources = array();
        if (isset($config["managed_urls"])) {
            foreach ($config["managed_urls"] as $regex => $cacheable) {
                if ($cacheable) {
                    $this->cacheableResources[] = "/$regex/";
                }
            }
        }
    }

    public function isCacheable($uri)
    {    
        // no resource regex to check
        if (empty($this->cacheableResources)) {
            return false;
        }

        // @todo: build a hash table of URIs and whether they are cacheable so we only go through the loop once?
        // check regexes one by one, if we find a match, we can cache
        $uri = $this->processUri($uri);
        foreach ($this->cacheableResources as $regex) {
            if (preg_match($regex . "i", $uri)) {
                return true;
            }
        }
        return false;
    }

    public function clear()
    {
        global $wpdb;
        $this->logger->debug("Clearing cache.");
        $wpdb->query("DELETE FROM {$this->tableName};");
    }

    public function hasItem($uri)
    {
        return $this->getItem($uri) !== false;
    }

    public function setItem($uri, $content)
    {
        global $wpdb;
        $this->logger->debug("Caching resource for URI: " . $uri);
        if (!$this->isCacheable($uri)) {
            $this->logger->warn("URI is not cacheable: $uri.");
        }
        $wpdb->replace(
            $this->tableName,
            array("hash" => md5($uri), "updated" => date("Y-m-d H:i:s", time()), "content" => $content, "uri" => $uri)
        );
    }

    public function getItem($uri)
    {
        global $wpdb;

        // no need to hit the DB if we can't cache this URI
        if (!$this->isCacheable($uri)) {
            return false;
        }
        
        if (isset($_REQUEST["nowebcache"]) && $_REQUEST["nowebcache"] ) {
        	error_log( "Cache skip based on NOWEBCACHE parameter: " . $uri );
			return false;
		}
		
		if( issset( $_SERVER['HTTP_REFERER'] ) && stripos( $_SERVER['HTTP_REFERER'], 'nowebcache=' ) ) {
			error_log( "Cache skip based on NOWEBCACHE referer parameter: " . $uri );
			return false;
		}

        $hash   = md5($uri);
        $sql    = $wpdb->prepare("SELECT * FROM {$this->tableName} WHERE hash=%s", $hash);
        $result = $wpdb->get_results($sql, OBJECT_K);
        if (isset($result[$hash])) {
            $result = $result[$hash];
            $this->logger->debug(
                "Cache hit for URI: $uri [Updated: {$result->updated}]"
            );
            return array(
                "uri"     => $uri,
                "content" => $result->content,
                "updated" => $result->updated,
                "hash"    => $result->hash
            );
        } else {
            $this->logger->debug("Cache miss for URI: " . $uri);
            return false;
        }
    }

    public function getCacheUsage()
    {
        global $wpdb;

        // has the table been created?
        $created = $wpdb->get_var("SHOW TABLES LIKE '" . $this->tableName . "'") === $this->tableName;

        // how many objects are cached?
        $count = $wpdb->get_var("SELECT COUNT(*) FROM " . $this->tableName);
        $count = $count === null ? 0 : $count;

        // what is the last update time of the cache?
        $updated = $wpdb->get_var("SELECT MAX(updated) FROM " . $this->tableName);
        $updated = $updated === null ? "-" : $updated;

        return array('created' => $created ? 'Yes' : 'No', 'object_count' => $count, 'last_updated' => $updated);
    }

    public function getUris()
    {
        global $wpdb;
        $uris = array();
        $sql = $wpdb->prepare("SELECT uri FROM }$this->tableName}");
        $result = $wpdb->get_results($sql, OBJECT_K);
        foreach ($result as $row) {
            $uris[] = $row["uri"];
        }
        return $uris;
    }

    private function checkDb()
    {
        global $wpdb;

        // add the WP-defined prefix for this blog
        $this->tableName = $wpdb->prefix . $this->tableName;

        if ($wpdb->get_var("SHOW TABLES LIKE '" . $this->tableName . "'") === $this->tableName) {
            // table exists
            $this->dbEnabled = true;
        } else {
            $this->dbEnabled = $this->dbCreateTable();
        }
    }

    private function dbCreateTable()
    {
        global $wpdb;
        $charset_collate = '';

        if (!empty($wpdb->charset)) {
            $charset_collate = "DEFAULT CHARACTER SET {$wpdb->charset}";
        }

        if (!empty($wpdb->collate)) {
            $charset_collate .= " COLLATE {$wpdb->collate}";
        }

        $sql = "CREATE TABLE {$this->tableName} "
            . "(hash     VARCHAR(32)   NOT NULL PRIMARY KEY, "
            . "updated  TIMESTAMP DEFAULT NOW(), "
            . "content  LONGBLOB, "
            . "uri      TEXT NOT NULL) "
            . "$charset_collate;";
        return $wpdb->query($sql);
    }

    private function processUri($uri)
    {
        // ensure we're only looking at the path portion of the URI
        return parse_url($uri, PHP_URL_PATH);
    }
}

<?php

namespace MRPIDX;

class Logger
{

    const LEVEL_WARN = "warn";
    const LEVEL_INFO = "info";
    const LEVEL_ERROR = "error";
    const LEVEL_DEBUG = "debug";

    const MESSAGES_MAX = 100;

    protected $tableName = "mrpidx_log";

    protected $errors;
    protected $notifications;

    protected $name;
    protected $debugEnabled;
    protected $dbEnabled;

    public function __construct($name, $debugEnabled = false)
    {
        $this->name = $name;
        $this->debugEnabled = $debugEnabled;
        $this->errors = array();
        $this->notifications = array();

        // create logging table if required, and determine if we can use database logging
        $this->checkDb();
    }

    public function clear()
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$this->tableName};");
    }

    public function getMessages(array $filters = array(), $limit = 100)
    {
        global $wpdb;

        $levels = array();
        if (isset($filters["level"])) {
            // specific level
            $levels = array(mysql_real_escape_string($filters["level"]));
        } elseif (isset($filters["levels"])) {
            // list of levels
            $levels = array_map("mysql_real_escape_string", $filters["levels"]);
        }

        $since = isset($filters["since"]) ? mysql_real_escape_string($filters["since"]) : "2000-01-01 00:00:00";
        $limit = intval($limit);
        $limit = $limit > 0 ? $limit : 100;
        if (count($levels)) {
            $quoteEscape = function ($a) {
                return "'$a'";
            };
            $query = sprintf(
                "SELECT * FROM {$this->tableName} WHERE created >= '%s' AND level IN (%s) ORDER BY id DESC LIMIT %d",
                $since,
                implode(", ", array_map($quoteEscape, $levels)),
                $limit
            );
        } else {
            $query = sprintf(
                "SELECT * FROM {$this->tableName} WHERE created >= '%s' ORDER BY id DESC LIMIT %d",
                $since,
                $limit
            );
        }
        return $wpdb->get_results($query, OBJECT);
    }

    public function log($level, $message)
    {
        if ($level == self::LEVEL_ERROR) {
            // add to the list of errors
            $this->errors[] = $message;
        } else {
            // add to the list of notifications
            $this->notifications[] = $message;
        }

        // if we're set up for logging to the database, do so
        if ($this->dbEnabled) {
            $this->dbAddMessage($level, $message);
        }
    }

    public function debugEnabled()
    {
        return $this->debugEnabled;
    }

    public function setDebugEnabled($debugEnabled)
    {
        $this->debugEnabled = $debugEnabled;
        return $this;
    }

    public function debug($message)
    {
        if ($this->debugEnabled) {
            $this->log(self::LEVEL_DEBUG, $message);
        }
    }

    public function info($message)
    {
        $this->log(self::LEVEL_INFO, $message);
    }

    public function error($message)
    {
        $this->log(self::LEVEL_ERROR, $message);
    }

    public function warn($message)
    {
        $this->log(self::LEVEL_WARN, $message);
    }

    public function getErrors($asString = false)
    {
        if ($asString) {
            return implode("\n", $this->errors);
        } else {
            return $this->errors;
        }
    }

    public function getNotifications($asString = false)
    {
        if ($asString) {
            return implode("\n", $this->notifications);
        } else {
            return $this->notifications;
        }
    }

    private function checkDb()
    {
        global $wpdb;

        // add the WP-defined prefix for this blog
        $this->tableName = $wpdb->prefix . $this->tableName;

        if ($wpdb->get_var("SHOW TABLES LIKE '" . $this->tableName . "'") === $this->tableName) {
            // table exists
            $this->dbEnabled = true;

            // trim table, if needed (current rows exceed 30% of our limit)
            if ($wpdb->get_var("SELECT COUNT(*) FROM $this->tableName") > self::MESSAGES_MAX*1.30) {
                $temp = $wpdb->prefix . "templog";
                $wpdb->query(
                    "CREATE TEMPORARY TABLE $temp AS (SELECT * FROM {$this->tableName} ORDER BY id DESC LIMIT "
                    . self::MESSAGES_MAX
                    . ");"
                );
                $wpdb->query("TRUNCATE TABLE {$this->tableName};");
                $wpdb->query("INSERT INTO {$this->tableName} SELECT * FROM $temp;");
                $wpdb->query("DROP TABLE $temp;");
            }
        } else {
            $this->dbEnabled = $this->dbCreateTable();
        }
    }

    private function dbAddMessage($level, $message)
    {
        global $wpdb;

        if (!$this->dbEnabled) {
            return;
        }

        $wpdb->insert($this->tableName, array("level" => strtoupper($level), "message" => $message));
    }

    private function dbCreateTable()
    {
        global $wpdb;

        $query = <<<EOQ
    CREATE TABLE {$this->tableName}
    (id      INTEGER AUTO_INCREMENT PRIMARY KEY,
     created TIMESTAMP DEFAULT NOW(),
     level   ENUM('WARN', 'INFO', 'ERROR', 'DEBUG'),
     message TEXT);
EOQ;
        return $wpdb->query($query);
    }
}
<?php

echo("<pre>\n");
if (!isset($logs) || !count($logs)) {
    echo("No logs found");
    exit();
}

foreach ($logs as $entry) {
    extract(get_object_vars($entry));
    echo("ENTRY: $id $level $created\n");
    echo($message . "\n\n");
}
echo("</pre>");
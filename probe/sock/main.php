<?php

// Can TypePHP fetch over HTTP? `models pull <url>` (FR-10) depends on it.
function main(): void
{
    echo "allow_url_fopen=" . (ini_get("allow_url_fopen") ? "on" : "off") . "\n";
    $body = @file_get_contents("http://127.0.0.1:8099/probe.txt");
    if ($body === false) {
        echo "http_fetch=FAIL\n";
        return;
    }
    echo "http_fetch=OK len=" . strlen($body) . " body=" . $body . "\n";
    echo "copy_url=";
    if (@copy("http://127.0.0.1:8099/probe.txt", "probe_dl.txt")) {
        echo "OK size=" . filesize("probe_dl.txt") . "\n";
        unlink("probe_dl.txt");
    } else {
        echo "FAIL\n";
    }
}

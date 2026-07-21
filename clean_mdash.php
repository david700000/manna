<?php
$files = array_merge(glob(__DIR__ . "/app/Notifications/*.php"), [__DIR__ . "/app/Services/BrevoMailService.php"]);
foreach ($files as $f) {
    if (file_exists($f)) {
        $c = file_get_contents($f);
        $c = str_replace("&mdash;", "-", $c);
        file_put_contents($f, $c);
    }
}
echo "Replaced mdash";


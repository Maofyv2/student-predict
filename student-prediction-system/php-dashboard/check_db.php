<?php
require_once 'bootstrap.php';

echo "tbl_surveys columns:\n";
$res = db()->query("SHOW COLUMNS FROM tbl_surveys");
while ($r = $res->fetch_assoc()) {
    echo " - " . $r['Field'] . " (" . $r['Type'] . ")\n";
}

echo "tbl_students columns:\n";
$res = db()->query("SHOW COLUMNS FROM tbl_students");
while ($r = $res->fetch_assoc()) {
    echo " - " . $r['Field'] . " (" . $r['Type'] . ")\n";
}

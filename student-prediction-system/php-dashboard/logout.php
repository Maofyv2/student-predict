<?php
require_once __DIR__ . '/bootstrap.php';

session_destroy();
redirect_to('index.php');

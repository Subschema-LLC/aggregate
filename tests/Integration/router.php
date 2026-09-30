<?php

/*
 * Router for PHP's built-in web server in the fresh-install test. It behaves
 * like public/.htaccess: existing files are served as they are, except
 * /aggregate.js, and everything else goes to the front controller.
 */

$root = $_SERVER['DOCUMENT_ROOT'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && $path !== '/aggregate.js' && is_file($root.$path)) {
    return false;
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root.'/index.php';

return require $root.'/index.php';

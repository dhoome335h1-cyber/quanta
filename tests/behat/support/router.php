<?php
/** Front controller adapter for the loopback-only acceptance server. */
declare(strict_types=1);

$docroot = getenv('QUANTA_BEHAT_ROOT');
if (extension_loaded('quanta_db') || !$docroot || !is_file($docroot . '/.quanta-behat')) {
    http_response_code(500);
    exit('The disposable acceptance site is not configured.');
}
$host = 'behat.test';
chdir($docroot);
require $docroot . '/src/boot.php';

<?php
/** CLI-only fixture and storage operations using the real Quanta API. */
declare(strict_types=1);

use Quanta\Common\Environment;
use Quanta\Common\Localization;
use Quanta\Common\Node;
use Quanta\Common\NodeFactory;
use Quanta\Common\UserFactory;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
if (extension_loaded('quanta_db')) {
    throw new RuntimeException('Use a PHP configuration without quanta_db for the acceptance suite.');
}
$root = $argv[1] ?? '';
$operation = $argv[2] ?? '';
if (!is_file($root . '/.quanta-behat')) {
    throw new RuntimeException('Refusing to operate outside a disposable acceptance site.');
}
chdir($root);
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['HTTP_HOST'] = 'behat.test';
$_SERVER['REQUEST_URI'] = '/home/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
ini_set('session.save_path', $root . '/sessions');

require $root . '/src/modules/environment/classes/Common/DataContainer.class.php';
require $root . '/src/modules/environment/classes/Common/Environment.class.php';
require $root . '/src/modules/environment/classes/Common/Logger.class.php';
$env = new Environment('behat.test', '/home/', $root);
require $root . '/src/autoload.php';
function t($string, $replace = array())
{
    return Localization::t($string, $replace);
}
$env->load();
if (!is_file(CLASS_MAP_FILE)) {
    $env->mapClasses();
}
$env->startSession();
$env->hook('boot');
$env->setData('language', Localization::LANGUAGE_NEUTRAL);

switch ($operation) {
    case 'seed':
        UserFactory::buildUser($env, 'administrator', array(
            'title' => 'Acceptance administrator',
            'password' => 'behat-test-password',
            'roles' => array('admin'),
            'email' => 'admin@example.invalid',
        ));
        $result = array('seeded' => UserFactory::load($env, 'administrator')->exists);
        break;

    case 'create':
        $node = NodeFactory::buildNode($env, $argv[3], 'pages', array(
            'title' => $argv[4],
            'body' => '<p>Content saved by the acceptance test</p>',
            'status' => Node::NODE_STATUS_PUBLISHED,
            'permissions' => (object) array('node_view' => 'anonymous'),
        ), Localization::LANGUAGE_NEUTRAL);
        $result = array('exists' => $node->exists, 'title' => $node->getTitle());
        break;

    case 'update':
        $node = NodeFactory::load($env, $argv[3]);
        if (!$node->exists) {
            throw new RuntimeException('Cannot update a missing fixture node.');
        }
        $node->setTitle($argv[4]);
        $node->save();
        $result = array('title' => $node->getTitle());
        break;

    case 'delete':
        $node = NodeFactory::load($env, $argv[3]);
        if (!$node->exists) {
            throw new RuntimeException('Cannot delete a missing fixture node.');
        }
        $node->delete();
        $result = array('deleted' => true);
        break;

    case 'load':
        $node = NodeFactory::load($env, $argv[3]);
        $result = array('exists' => $node->exists, 'title' => $node->getTitle());
        break;

    default:
        throw new InvalidArgumentException('Unknown fixture operation: ' . $operation);
}
$env->closeSession();
echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";

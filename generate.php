<?php
namespace QueueWatcher;
require_once __DIR__ . '/vendor/autoload.php';
require_once './packages/bhl/pdfgenerator/src/PDFGenerator.php';
require_once './packages/bhl/pdfgenerator/src/ForceJustify.php';
ini_set('memory_limit','1024M');

use PDODb;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json;
use BHL\PDFGenerator\MakePDF;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$config = new Config('config/config.json');
$pdfgen = new MakePDF($config);

// if (!file_exists(dirname($config->get('logging.filename')))) {
// 	mkdir(dirname($config->get('logging.filename')));
// }

$id = null;
if (isset($argv[1])) {
	$id = $argv[1];
}

if (!$id) {
	print "ERROR: ID is required\n";
	die;
}
$pdfgen->generate_article_pdf($id);
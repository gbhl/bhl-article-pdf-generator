<?php

// ******************************************
// Generate one PDF
//
// Get a PartID number on the URL and generate
// the PDF. Makes lots of output.
// ******************************************

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
$pdfgen = new MakePDF($config, false); // False = not verbose

ini_set("memory_limit", $config->get('max_memory'));

$id = null;
$pg = false;
$md = false;
if (isset($argv[1])) {
	$id = $argv[1];
}
if (isset($argv[2])) {
	if ($argv[2] == 'page') { $pg = true; }
	if ($argv[2] == 'metadata') { $md = true; }
}
if (isset($argv[3])) {
	if ($argv[3] == 'page') { $pg = true; }
	if ($argv[3] == 'metadata') { $md = true; }
}
if (!$id) {
	print "ERROR: ID is required\n";
	print "USAGE: php generate.php ID (page|metadata)\n";
	die;
}
if (!$pg && !$md) {
	print "ERROR: Page or Metadata change required\n";
	print "USAGE: php generate.php ID (page|metadata)\n";
	die;
}
print "Generating PDF with changed".($pg ? " pages " : "").($md ? " metadata " : "")."\n";

$pdfgen->generate_article_pdf($id, $pg, $md);

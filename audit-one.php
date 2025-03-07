<?php
namespace BHLSegmentPDFAudit;

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
$id = 0;
if (isset($argv[1])) {
	$id = $argv[1];
}
if (!$id) {
	print "ID is requried\n";
	die;
} 
$config = new Config('config/config.json');
ini_set("memory_limit", $config->get('max_memory'));

$pdfgen = new MakePDF($config, true);

	print "-------- $id ---------\n";
	// Construct the filename
	$L1 = substr((string)$id, 0, 1);
	$L2 = substr((string)$id, 1, 1);
	$filename = $config->get('paths.output').'/'.$L1.'/'.$L2.'/bhl-segment-'.$id.($config->get('image.desaturate') ? '-grey' : '').'.pdf';

	//  Check that the file actually exists
	if (!file_exists($filename)) {
		print "SegmentID $id: Output file not found\n";
		die;
	}

	// Get the number of pages from BHL's metadata
	# build a filename
	$cache_path = $config->get('cache.paths.json').'/'.'segment-'.$id.'.json';
	
	# if it's not in the cache, get it and put it there
	# note: Error results can get saved to the cache. 
	if (!file_exists($cache_path)) {
		$url = 'https://www.biodiversitylibrary.org/api3?op=GetPartMetadata&id='.$id.'&format=json&pages=t&names=t&apikey='.$config->get('bhl.api_key');
		file_put_contents($cache_path, file_get_contents($url));
	}
	# read from the cache
	$object = json_decode(file_get_contents($cache_path), true, 512, JSON_OBJECT_AS_ARRAY);
	$json_pages = count($object['Result'][0]['Pages']);

	// Get the number of pages in the PDF
	$parser = new \Smalot\PdfParser\Parser();
	$pdf = $parser->parseFile($filename);
	$pdf_pages = count($pdf->getPages());

	//	Check the number of pages, ensure it's the same as the number of pages in BHL's metadata
	if ($json_pages <> $pdf_pages-1) {
		print "SegmentID {$id}: Pagecount doesn't match (API={$json_pages}, PDF={$pdf_pages})\n";
		die;
	}

	// Get the text on the last page
	$text = $pdf->getPages()[$pdf_pages-1]->getText();
	$text = preg_replace('/\s+/',' ', $text);
	// Does it contain the boilerplate BHL text?
	if (!preg_match('/This document was created from content at the Biodiversity Heritage Library/',$text)) {
		print "SegmentID {$id}: Pagecount doesn't match (API={$json_pages}, PDF={$pdf_pages})\n";
		die;
	}
	// Other checks?
	print "PDF is good.\n";

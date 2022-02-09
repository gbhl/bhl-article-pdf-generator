<?php
namespace QueueWatcher;
require_once __DIR__ . '/vendor/autoload.php';
require_once './packages/bhl/pdfgenerator/src/PDFGenerator.php';
require_once './packages/bhl/pdfgenerator/src/ForceJustify.php';

use PDODb;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use BHL\PDFGenerator\MakePDF;

$config = new Config('config/config.json');
$pdfgen = new MakePDF($config);
$limit = 500000;

ini_set("memory_limit", $config->get('max_memory'));

$connection = new AMQPStreamConnection(
	$config->get('mq.hostname'), 
	$config->get('mq.port'),
	$config->get('mq.username'),
	$config->get('mq.password')
);
$channel = $connection->channel();
$channel->queue_declare($config->get('mq.queue_name'), true, true, false, true);
$channel->basic_qos(null, 1, null);

$process_messsage = function($msg){
	global $pdfgen;
	global $channel;
	global $config;
	
	$body = explode('|', trim($msg->body));
	if (!isset($body[3])) { $body[3] = ''; }
	$id = $body[2];
	try {
		// Generate the PDF
		$pdfgen->generate_article_pdf($id, ($body[3] == 'page'), ($body[3] == 'metadata'));
		$msg->ack();
	} catch (\Exception $e) {
		# Publish the ID to the error queue. THIS IS A HACK. I think.
		$channel->basic_publish($msg, '', $config->get('mq.error_queue_name'));
		$msg->ack();
	}
};

$channel->basic_consume($config->get('mq.queue_name'), '', false, false, false, false, $process_messsage);

$count = 0;
while (count($channel->callbacks)) {
	print "Sleeping 3 sec...\n";
	sleep(1);
	$channel->wait();
	if ($count++ >= $limit) { break; }
}

$channel->close();
$connection->close();

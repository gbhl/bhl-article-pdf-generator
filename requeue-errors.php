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
$limit = 10000;

ini_set("memory_limit", $config->get('max_memory'));

$connection = new AMQPStreamConnection(
	$config->get('mq.hostname'), 
	$config->get('mq.port'),
	$config->get('mq.username'),
	$config->get('mq.password')
);
print "Connected.\n";
$channel = $connection->channel();
$channel->queue_declare($config->get('mq.error_queue_name'), true, true, false, true);
$channel->basic_qos(null, 1, null);
print_r($channel);

$process_messsage = function($msg){
	global $pdfgen;
	global $channel;
	global $config;

	$body = explode('|', trim($msg->body));
	if (!isset($body[3])) { $body[3] = ''; }
	print "Requeueing ID $body[2]\n";
	# Publish the message back to the main queue
	$channel->basic_publish($msg, '', $config->get('mq.queue_name'));
	$msg->ack();
};

$channel->basic_consume($config->get('mq.error_queue_name'), '', false, false, false, false, $process_messsage);

$count = 1;
while (count($channel->callbacks)) {
	sleep(.1);
	$channel->wait();
	if ($count++ >= $limit) { break; }
}

$channel->close();
$connection->close();


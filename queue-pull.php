<?php
namespace QueueWatcher;
require_once __DIR__ . '/vendor/autoload.php';
require_once './packages/bhl/pdfgenerator/src/PDFGenerator.php';
require_once './packages/bhl/pdfgenerator/src/ForceJustify.php';
ini_set('memory_limit','1024M');

use PDODb;
use Noodlehaus\Config;
use Noodlehaus\Parser\Json;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use BHL\PDFGenerator\MakePDF;

$config = new Config('config/config.json');
$pdfgen = new MakePDF($config);

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
	
	$message = explode('|', trim($msg->body));
	$id = $message[2];
	$pdfgen->generate_article_pdf($id);
	$msg->ack();
};

$channel->basic_consume($config->get('mq.queue_name'), '', false, false, false, false, $process_messsage);

while (count($channel->callbacks)) {
	$channel->wait();
}
register_shutdown_function('shutdown', $channel, $connection);

function shutdown($channel, $connection) {
	$channel->close();
	$connection->close();
}


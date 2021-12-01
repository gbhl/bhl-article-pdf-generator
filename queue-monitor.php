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
$pdfgen->generate_article_pdf(184719);

die;


print "Connecting...\n";
$connection = new AMQPStreamConnection(
	$config['mq']['hostname'], $config['mq']['port'], 
	$config['mq']['username'], $config['mq']['password']
);
$channel = $connection->channel();
print "Declaring Queue...\n";

$channel->queue_declare($config['mq']['queue_name'], true, true, false, true);
$channel->basic_qos(null, 1, null);
$channel->basic_consume($config['mq']['queue_name'], '', false, false, false, false, 'process_messsage');
 
while (count($channel->callbacks)) {
    $channel->wait();
}
register_shutdown_function('shutdown', $channel, $connection);

function process_messsage($msg){
		$message = explode('|', trim($msg->body));
		print "Processing segment {$message[2]}...\n";
		// print_r($message);
		$cmd = "php generate.php {$message[2]} --force";
		system($cmd);
		$msg->ack();
		die;
}

function shutdown($channel, $connection) {
	$channel->close();
	$connection->close();
}


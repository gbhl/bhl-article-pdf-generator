<?php
namespace QueueWatcher;
require_once __DIR__ . '/vendor/autoload.php';

print "Don't run this. Really.\n";
die;

use Noodlehaus\Config;
use Noodlehaus\Parser\Json;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$config = new Config('config/config.json');

ini_set("memory_limit", $config->get('max_memory'));

$connection = new AMQPStreamConnection(
	$config->get('mq.hostname'), 
	$config->get('mq.port'),
	$config->get('mq.username'),
	$config->get('mq.password')
);
$channel = $connection->channel();
$channel->queue_declare($config->get('mq.queue_name'), true, true, false, true);

$fh = fopen('All-Segment-IDs.txt','r');
while ($id = fgets($fh)) {
	$id = trim($id);
	$data = 'put|segment|'.$id.'|page';
	$msg = new AMQPMessage($data, array('delivery_mode' => 2));
	$channel->basic_publish($msg, '', $config->get('mq.queue_name'));
	echo ' [>] "' . $data . '" sent' . PHP_EOL;
}
$channel->close();
$connection->close();


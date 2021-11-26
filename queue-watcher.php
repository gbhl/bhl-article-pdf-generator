<?php
require_once __DIR__ . '/vendor/autoload.php';
// define('AMQP_DEBUG', true);

# does the config file exist?
if (!file_exists('config.php')) {
	die('config.php file not found.'."\n");
}
require_once('config.php');
ini_set('memory_limit','1024M');

use PhpAmqpLib\Connection\AMQPStreamConnection;

print "Connecting...\n";
$connection = new AMQPStreamConnection(
	$config['mq']['hostname'], $config['mq']['port'], 
	$config['mq']['username'], $config['mq']['password']
);
$channel = $connection->channel();
print "Declaring Queue...\n";

$channel->queue_declare($config['mq']['queue_name'], true, true, false, true);
echo ' * Waiting for messages. To exit press CTRL+C', "\n";

$channel->basic_qos(null, 1, null);
$channel->basic_consume($config['mq']['queue_name'], '', false, false, false, false, 'process_messsage');
 
while (count($channel->callbacks)) {
    $channel->wait();
}
register_shutdown_function('shutdown', $channel, $connection);


function process_messsage($msg){
		print_r($msg->body);
		$message = explode('|', $msg->body);
		print_r($message);
		$cmd = "php generate.php {$message[2]} --force";
		print $cmd."\n";
		`echo '$cmd' >> commands.sh`; 
		// exec($cmd);

		$msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']);
		die;
}

function shutdown($channel, $connection) {
	$channel->close();
	$connection->close();
}


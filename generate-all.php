<?php
# does the config file exist?
if (!file_exists('config.php')) {
	die('config.php file not found.'."\n");
}
require_once('config.php');

$filename = null;
if (!isset($argv[1])) {
	die('Filename is required.'."\n");
}
$filename = $argv[1];

if (!file_exists($filename)) {
	die('Filename not found.'."\n");
}

$fh = fopen($filename, 'r');
// Skip the first line
$id = fgets($fh);
while ($id = trim(fgets($fh))) {

	if (!file_exists($config['paths']['output']."/bhl-segment-$id.pdf")) {
		$cmd = "php generate.php ".$id;
		print "$cmd\n";
	}
}
fclose($fh);

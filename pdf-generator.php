<?php

// ******************************************
// BHL PDF Generator Multi-Processing
//
// Manage multple child processes for 
// pushing through the BHL PDF message queue.
// ******************************************

$max = 8;
$child_script = "queue-pull.php";

if (isset($argv[1])) {
	$max = $argv[1];
}

function count_children($child_script) {
	$ret =  `ps awx | fgrep {$child_script} | fgrep -v grep | wc -l`;
	return (int)trim($ret);
}

function log_message($m) {
	$fh = fopen('log/coordinator.log', 'a');
	$dt = new DateTime();
	$dt = $dt->format('Y-m-d H:i:s');
	fwrite($fh, "[{$dt}] {$m}\n");
	fclose($fh);
}

function clear_cache() {
	log_message("Clearing cache...");
	`find ./cache -mtime +2 -exec rm -fr {} \; > /dev/null 2>&1`;
	`find ./cache -mtime +2 -exec rm -fr {} \; > /dev/null 2>&1`;
	`find ./cache -mtime +2 -exec rm -fr {} \; > /dev/null 2>&1`;
	`find ./cache -mtime +2 -exec rm -fr {} \; > /dev/null 2>&1`;
	`find ./cache -mtime +2 -exec rm -fr {} \; > /dev/null 2>&1`;
	log_message("Finished clearing cache.");
}

while (true) {
	$count = count_children($child_script);
	if ($count < $max) {
		log_message("Parent spawning child...");
		clear_cache();
		$command =  PHP_BINARY." {$child_script} > /dev/null 2>&1 &";
		`$command`;
	}
	sleep(5);
}

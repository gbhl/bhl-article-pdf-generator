<?php

// ******************************************
// BHL PDF Generator Multi-Processing
//
// Manage multple child processes for 
// pushing through the BHL PDF message queue.
// ******************************************

$max = 4;
$child_script = "queue-pull.php";

function count_children($child_script) {
	$ret =  `ps awx | fgrep {$child_script} | fgrep -v grep | wc -l`;
	return (int)trim($ret);
}

$fh = fopen('log/coordinator.log', 'a');
while (true) {
	$count = count_children($child_script);
	if (file_exists('cancel.txt')) {
		break;
	}
	if ($count <= $max) {
		$dt = new DateTime();
		$dt = $dt->format('Y-m-d H:i:s');
		fwrite($fh, "[{$dt}] Parent spawning child...\n");
		$command =  PHP_BINARY." {$child_script} > log/queue-pull-".time().".txt 2>&1 &";
		`$command`;
	}
	sleep(5);
}

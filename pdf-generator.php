<?php

// ******************************************
// BHL PDF Generator Multi-Processing
//
// Manage multple child processes for 
// pushing through the BHL PDF message queue.
// ******************************************

$max = 4;
$child_script = "queue-pull.php";


$command =  PHP_BINARY." {$child_script} > /dev/null 2>&1 &";

function count_children($child_script) {
	$ret =  `ps awx | fgrep {$child_script} | fgrep -v fgrep | wc -l`;
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
		`$command`;
	}
	sleep(5);
}

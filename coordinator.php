<?php
$max = 4;
$child_script = "sleeper.php";
$command =  PHP_BINARY." {$child_script} > /dev/null 2>&1 &";

function count_children($child_script) {
	$ret =  `ps awx | fgrep {$child_script} | fgrep -v fgrep | wc -l`;
	return (int)trim($ret);
}

while (true) {
	$count = count_children($child_script);
	print "$count < $max\n";
	if (file_exists('cancel.txt')) {
		break;
	}
	if ($count < $max) {
		print "Parent spawning child...\n";
		`$command`;
	}
	sleep(1);
}

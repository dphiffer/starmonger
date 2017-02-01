<?php

$root = __DIR__;
require_once "$root/setup.php";

dbug('Starting download');
set_time_limit(0);

$problems = check_setup();
if (!empty($problems)) {
	echo "There are some issues you’ll need to sort out before you can start using\nStarmonger:\n\n";
	foreach ($problems as $problem) {
		echo "* $problem\n";
	}
	exit;
}

$status = $twitter->get('application/rate_limit_status');
$rate_limited = false;

if (!empty($status->resources)) {
	$favorites_list = '/favorites/list';
	if (!empty($status->resources->favorites->$favorites_list)) {
		if (!empty($status->resources->favorites->$favorites_list->remaining)) {
			dbug("Downloading oldest favorites");
			archive_oldest_favorites($verbose);
			dbug("Downloading newest favorites");
			archive_newest_favorites($verbose);
		} else {
			$rate_limited = true;
			$reset = date('Y-m-d H:i:s', $status->resources->favorites->$favorites_list->reset);
			echo "Rate limit reached, check again at $reset\n";
		}
	}
} else {
	$rate_limited = true;
}

dbug('Finished download');

?>

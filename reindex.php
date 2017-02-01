<?php

include 'setup.php';
$problems = check_setup();

if (!empty($problems)) {
	show_header('setup');
	echo "There are some issues you’ll need to sort out before you can start using Starmonger:\n<ol>\n";
	foreach ($problems as $problem) {
		echo "<li>$problem</li>\n";
	}
	echo "</ol>";
	show_footer();
	exit;
}

query("
	DELETE FROM twitter_favorite_search
");

$total = query("
	SELECT COUNT(id) AS total
	FROM twitter_favorite
");

$twitter_favorite_search = $db->prepare("
	INSERT INTO twitter_favorite_search
	(id, user, content)
	VALUES (?, ?, ?)
");

$total = $total[0]->total;
$curr = 0;

while ($curr < $total) {
	echo "Indexing: $curr<br>\n";
	$favs = query("
		SELECT *
		FROM twitter_favorite
		ORDER BY id
		LIMIT ?, 500
	", array($curr));
	$curr += count($favs);
	
	$db->beginTransaction();
	foreach ($favs as $fav) {
		$status = json_decode($fav->json);
		$user = strtolower($status->user->screen_name);
		$content = tweet_content($status);
		$twitter_favorite_search->execute(array(
			$status->id,
			$user,
			$content
		));
	}
	$db->commit();
}

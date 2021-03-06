<?php

function dbug($msg) {
	global $argv;
	if (! is_scalar($msg)) {
		$msg = print_r($msg, true);
		$msg = trim($msg);
	}
	if (! empty($argv) &&
	    in_array('--verbose', $argv)) {
		echo "$msg\n";
	}
	if (! empty($_GET['verbose'])) {
		echo "<pre>$msg\n</pre>";
	}
}

function setup_db() {
	global $config, $db;
	$filename = $config->database_path;
	$curr_db_version = 3;
	if (!file_exists($filename)) {
		$db = new PDO("sqlite:$filename");
		$db->query("
			CREATE TABLE twitter_meta (
				name VARCHAR(255) PRIMARY KEY,
				value TEXT
			);
		");
		$db->query("
			CREATE TABLE twitter_favorite (
				id INTEGER PRIMARY KEY,
				href VARCHAR(255),
				user VARCHAR(255),
				content TEXT,
				json TEXT,
				protected INT,
				created_at DATETIME,
				saved_at DATETIME
			);
		");
		$db->query("
			CREATE INDEX twitter_favorite_index ON twitter_favorite (
				id, created_at, saved_at
			);
		");
		$db->query("
			CREATE VIRTUAL TABLE twitter_favorite_search USING FTS3 (
				id, user, content, tokenize=porter
			);
		");
		$db->query("
			CREATE TABLE twitter_media (
				tweet_id INT,
				href VARCHAR(255),
				redirect VARCHAR(255),
				path VARCHAR(255),
				saved_at DATETIME
			);
		");
		$db->query("
			CREATE INDEX twitter_media_index ON twitter_media (
				tweet_id, path
			);
		");
		meta_set('db_version', $curr_db_version);
	} else {
		$db = new PDO("sqlite:$filename");
		if (! $db) {
			 echo "Uh oh, we couldn't open the database file.";
			 exit;
		}
	}

	$db_version = meta_get('db_version');
	switch ($db_version) {
		case null:
				db_migrate(1);
		case 1:
				db_migrate(2);
		case 2:
				db_migrate(3);
	}

	return $db;
}

function setup_twitter() {
	global $config;
	$twitter = null;
	if (class_exists('TwitterOAuth')) {
		$twitter = new TwitterOAuth(
			$config->twitter_consumer_key,
			$config->twitter_consumer_secret,
			$config->twitter_access_token,
			$config->twitter_access_token_secret
		);
		$twitter->host = 'https://api.twitter.com/1.1/';
	}
	return $twitter;
}

function setup_account($force_download = false) {
	global $twitter;
	$min_duration = 24 * 60 * 60; // update once per day
	$last_updated = meta_get('twitter_account_last_updated');
	$now = time();
	if ($force_download ||
	    empty($last_updated) ||
	    $now - $last_updated > $min_duration) {
		$account = $twitter->get('account/settings');
		if (empty($account->errors)) {
			meta_set('twitter_account', json_encode($account));
			meta_set('twitter_account_last_updated', $now);
		}
	} else {
		$account = meta_get('twitter_account');
		$account = json_decode($account);
		if (! $account) {
			return setup_account(true);
		}
	}
	return $account;
}

function setup_timezone() {
	global $account;
	$timezone = meta_get('timezone');
	if (empty($timezone)) {
		if (!empty($account->time_zone->tzinfo_name)) {
			$timezone = $account->time_zone->tzinfo_name;
			meta_set('timezone', $timezone);
		} else {
			$timezone = 'America/New_York';
		}
	}
	date_default_timezone_set($timezone);
}

function archive_oldest_favorites() {
	global $twitter;
	$params = array(
		'count' => 200,
		'tweet_mode' => 'extended'
	);
	$oldest_id = meta_get('oldest_id', 0);
	if ($oldest_id) {
		$params['max_id'] = $oldest_id - 1;
	}
	$favs = $twitter->get("favorites/list", $params);
	if (is_array($favs)) {
		save_favorites($favs);
		if (empty($favs)) {
			meta_set('oldest_id', 0);
		} else {
			$len = count($favs);
			meta_set('oldest_id', $favs[$len - 1]->id);
		}
	}
}

function archive_newest_favorites() {
	global $twitter;
	$params = array(
		'count' => 200,
		'tweet_mode' => 'extended'
	);
	$favs = $twitter->get("favorites/list", $params);
	if (is_array($favs)) {
		save_favorites($favs);
	}
}

function save_favorites($favs) {
	global $db;
	$ids = array();
	foreach ($favs as $status) {
		$ids[] = addslashes($status->id);
	}
	$ids = implode(', ', $ids);
	$query = $db->query("
		SELECT id
		FROM twitter_favorite
		WHERE id IN ($ids)
	");
	$existing_ids = $query->fetchAll(PDO::FETCH_COLUMN);

	$db->beginTransaction();
	$twitter_favorite = $db->prepare("
		INSERT INTO twitter_favorite
		(id, href, user, content, json, created_at, saved_at)
		VALUES (?, ?, ?, ?, ?, ?, ?)
	");
	$twitter_favorite_search = $db->prepare("
		INSERT INTO twitter_favorite_search
		(id, user, content)
		VALUES (?, ?, ?)
	");
	foreach ($favs as $status) {
		if (in_array($status->id, $existing_ids)) {
			// TODO: update existing records, to account for new faves/RTs and updated usernames
			continue;
		}
		$user = strtolower($status->user->screen_name);
		$href = "https://twitter.com/$user/statuses/$status->id";
		$content = tweet_content($status);
		$json = json_encode($status);
		$created_at = strtotime($status->created_at);
		$twitter_favorite->execute(array(
			$status->id,
			$href,
			$user,
			$content,
			$json,
			date('Y-m-d H:i:s', $created_at),
			date('Y-m-d H:i:s')
		));
		$twitter_favorite_search->execute(array(
			$status->id,
			$user,
			$content
		));
		$profile_image = tweet_profile_image($status);
	}
	$db->commit();
}

function query($sql, $params = null) {
	global $db;
	if (empty($params)) {
		$params = array();
	}
	$query = $db->prepare($sql);
	$error = $db->errorInfo();
	$query->execute($params);
	return $query->fetchAll(PDO::FETCH_OBJ);
}

function check_setup() {
	global $config, $db, $twitter, $account;
	$root = __DIR__;
	$issues = array();
	if (!file_exists("$root/twitteroauth/twitteroauth/twitteroauth.php")) {
		if (!file_exists("$root/.git")) {
			$issues[] = 'Download and unzip <a href="https://github.com/abraham/twitteroauth/archive/master.zip">twitteroauth</a> library into this directory';
		} else {
			$issues[] = "To automatically download <a href=\"https://github.com/abraham/twitteroauth\">twitteroauth</a> dependency, type the following into the command line:<br><code><pre>cd $root\ngit submodule init\ngit submodule update</pre></code>";
		}
	} else {
		require_once "$root/twitteroauth/twitteroauth/twitteroauth.php";
	}
	if (!file_exists("$root/config.php")) {
		$issues[] = 'Rename config-example.php to config.php end edit with your Twitter API credentials';
	} else {
		require_once "$root/config.php";
		$config = (object) $config;
		if (!file_exists(dirname($config->database_path))) {
			$issues[] = 'The database path directory doesn’t exist';
		} else if (!is_writable(dirname($config->database_path))) {
			$issues[] = 'The database path directory doesn’t allow write permissions';
		} else {
			$db = setup_db();
		}
	}
	$twitter = setup_twitter();
	if (!empty($twitter)) {
		$account = setup_account();
		if (empty($account)) {
			$issues[] = "There was a problem connecting to Twitter.";
		} else if (!empty($account->errors)) {
			$error = $account->errors[0];
			$issues[] = "There was a problem connecting to Twitter: $error->message";
		} else {
			setup_timezone();
		}
	}
	if (empty($issues)) {
		return null;
	} else {
		return $issues;
	}
}

function show_header($body_class = '') {
	global $db, $rate_limited;
	$q = '';
	if (!empty($_GET['q'])) {
		$q = htmlentities($_GET['q'], ENT_COMPAT, 'UTF-8');
		list($count) = query("
			SELECT COUNT(*) AS count
			FROM twitter_favorite_search
			WHERE twitter_favorite_search MATCH ?
		", array($_GET['q']));
		$count = number_format($count->count);
	} else if ($body_class == 'setup') {
		$count = 'setup';
	} else {
		list($count) = query("
			SELECT COUNT(*) AS count
			FROM twitter_favorite
		");
		$count = number_format($count->count);
	}
	$title = "<span class=\"star\">&#9733;</span> <span class=\"text\">$count</span>";
	$page_title = "&#9733; $count";
	$title_hover = 'Home';
	if (!empty($q)) {
		$page_title = "$q $page_title";
	} else if (empty($_GET['max_id']) && !empty($db)) {
		$title_hover = '';
	}
	if (!empty($rate_limited)) {
		$page_title .= ' (API rate limited)';
	}
?>
<!DOCTYPE html>
<html>
	<head>
		<meta charset="utf-8">
		<title><?php echo $page_title; ?></title>
		<link rel="stylesheet" href="styles.css">
	</head>
	<body class="<?php echo $body_class; ?>">
		<div id="page">
			<header>
				<h1><a href="./" title="<?php echo $title_hover; ?>"><?php echo $title; ?></a></h1>
				<form action="./">
					<input type="text" name="q" value="<?php echo $q; ?>">
					<input type="submit" value="Search">
				</form>
			</header>
	<?php
}

function show_footer($min_id = null, $max_id = null) {
	global $status, $rate_limited;
?>
			<footer>
				<?php if (!empty($min_id) || !empty($max_id)) { ?>
					<span class="earlier"><?php echo get_earlier_link($max_id); ?></span>
					<span class="later"><?php echo get_later_link($min_id); ?></span>
				<?php } ?>
				<span class="credit">
					<a href="https://github.com/dphiffer/starmonger">Starmonger</a> by
					<a href="http://phiffer.org/">Dan Phiffer</a></span>
				</span>
				<?php
				
				if (!empty($rate_limited)) {
					$favorites_list = '/favorites/list';
					$reset = date('M j, Y, g:i:s a', $status->resources->favorites->$favorites_list->reset);
					echo "<div id=\"rate-limit\"><a href=\"https://dev.twitter.com/docs/rate-limiting/1.1\">Twitter API rate limit</a> in effect, expires $reset</div>";
				}
				
				?>
			</footer>
		</div>
	</body>
</html>
<?php
}

function tweet_content($status, $quoted = false) {
	if (! empty($status->full_text)) {
			$text = $status->full_text;
	} else if (! empty($status->text)) {
			$text = $status->text;
	}

	$extended_content = tweet_extended_content($status);

	// Ony quote one level deep
	if (! $quoted) {
		$quoted_content = tweet_quoted_content($status);
	}

	$entities = array();
	$entity_types = array('hashtags', 'urls', 'user_mentions');
	foreach ($entity_types as $entity_type) {
		foreach ($status->entities->$entity_type as $entity) {
			$entity->type = $entity_type;
			$index = $entity->indices[0];
			$entities[$index] = $entity;
		}
	}
	if (! empty($status->entities->media)) {
		foreach ($status->entities->media as $entity) {
			$entity->type = 'media';
			$index = $entity->indices[0];
			$entities[$index] = $entity;
		}
	}
	ksort($entities);
	$pos = 0;
	$content = '';
	foreach ($entities as $index => $entity) {
		$content .= mb_substr($text, $pos, $entity->indices[0] - $pos, 'utf8');
		$pos = $entity->indices[1];
		if ($entity->type == 'hashtags') {
			$content .= "<a href=\"https://twitter.com/search?q=%23$entity->text&src=hash\" class=\"entity\">#<span class=\"text\">$entity->text</span></a>";
		} else if ($entity->type == 'urls') {
			if ($status->quoted_status) {
				$quoted_username = $status->quoted_status->user->screen_name;
				$quoted_id = $status->quoted_status->id;
				$quoted_url = strtolower("https://twitter.com/$quoted_username/status/$quoted_id");
				if ($quoted_url == strtolower($entity->expanded_url)) {
					continue;
				}
			}
			$content .= "<a href=\"$entity->expanded_url\" title=\"$entity->expanded_url\">$entity->display_url</a>";
		} else if ($entity->type == 'user_mentions') {
			$content .= "<a href=\"https://twitter.com/$entity->screen_name\" class=\"entity\" title=\"$entity->name\">@<span class=\"text\">$entity->screen_name</span></a>";
		} else if ($entity->type == 'media') {
			if (empty($extended_content)) {
				$media_url = local_media($status->id, "{$entity->media_url}:large");
				$content .= "<a href=\"$entity->expanded_url\" class=\"media\"><img src=\"$media_url\" alt=\"\"></a>";
			}
		}
	}
	$content .= mb_substr($text, $pos, strlen($text) - $pos, 'utf8');
	
	if ($extended_content) {
		$content .= " $extended_content";
	}
	if ($quoted_content) {
		if ($status->display_text_range) {
			$start = $status->display_text_range[0];
			$end = $status->display_text_range[1];
		}
		$content .= $quoted_content;
	}

	$content = preg_replace('/\n+/', "\n", $content);
	$content = nl2br($content);
	return $content;
}

function tweet_extended_content($status) {
	$extended_content = '';
	if (! empty($status->extended_entities) &&
			! empty($status->extended_entities->media)) {
		foreach ($status->extended_entities->media as $entity) {
			if ($entity->type == 'photo') {
				$media_url = local_media($status->id, "{$entity->media_url}:large");
				$extended_content .= "<a href=\"$entity->expanded_url\" class=\"media\"><img src=\"$media_url\" alt=\"\"></a>";
			} else if ($entity->type == 'animated_gif') {
				$poster_url = local_media($status->id, "{$entity->media_url}:large");
				$video_url = local_media($status->id, $entity->video_info->variants[0]->url);
				$extended_content .= "<div class=\"media animated-gif\">";
				$extended_content .= "<video src=\"$video_url\" poster=\"$poster_url\" id=\"gif-$status->id-video\" preload=\"none\" loop></video>";
				$extended_content .= "<a href=\"$video_url\" id=\"gif-$status->id-toggle\"><span class=\"label\">gif</span></a>";
				$extended_content .= "<script>var t = document.getElementById('gif-$status->id-toggle'); t.addEventListener('click', function(e) { e.preventDefault(); document.getElementById('gif-$status->id-video').play(); t.className = 'playing'; });</script>";
				$extended_content .= "</div>";
			} else if ($entity->type == 'video') {
				$poster_url = local_media($status->id, "{$entity->media_url}:large");
				$video_urls = array();
				foreach ($entity->video_info->variants as $variant) {
					if ($variant->content_type != 'video/mp4') {
						continue;
					}
					$video_urls[$variant->bitrate] = $variant->url;
				}
				if (! empty($video_urls)) {
					ksort($video_urls);
					$video_url = array_pop($video_urls);
					$video_url = local_media($status->id, $video_url);
					$extended_content .= "<div class=\"media video\">";
					$extended_content .= "<video src=\"$video_url\" poster=\"$poster_url\" preload=\"none\" controls></video>";
					$extended_content .= "</div>";
				}
			}
		}
	}
	return $extended_content;
}

function tweet_quoted_content($status) {
	$quoted_content = '';

	if (! empty($status->quoted_status)) {
		$is_quoted = true;
		$name = $status->quoted_status->user->name;
		$screen_name = $status->quoted_status->user->screen_name;
		$permalink = tweet_permalink($status->quoted_status);
		$quote_user = "<div class=\"user\">" .
			"<a href=\"https://twitter.com/$screen_name\">" .
				"<span class=\"name\">{$name}</span> " .
				"<span class=\"screen_name\">@$screen_name</span>" .
			"</a>" .
			" <span class=\"meta\"> &middot; $permalink</span>" .
		"</div>";
		$quoted_content = tweet_content($status->quoted_status, $is_quoted);
		$quoted_content = "$quote_user $quoted_content";
		$quoted_content = "<div class=\"quoted-status\">$quoted_content</div>";
	}

	return $quoted_content;
}

function tweet_permalink($status) {
	$screen_name = $status->user->screen_name;
	$id = $status->id;
	$url = "https://twitter.com/$screen_name/status/$id";
	$timestamp = strtotime($status->created_at);
	$date_time = date('M j, Y, g:i a', $timestamp);
	$time_diff = time() - $timestamp;
	if ($time_diff < 60) {
		$label = 'just now';
	} else if ($time_diff < 60 * 60) {
		$label = floor($time_diff / 60) . 'min';
	} else if ($time_diff < 60 * 60 * 24) {
		$label = floor($time_diff / (60 * 60)) . 'hr';
	} else {
		$label = date('M j', $timestamp);
	}
	return "<a href=\"$url\" title=\"$date_time\">$label</a>";
}

function get_earlier_link($max_id = null) {
	$link_text = "&larr; <span class=\"text\">earlier</span>";
	$max_id = get_earlier_id($max_id);
	if (empty($max_id)) {
		return $link_text;
	}
	$url = get_url_with_max_id($max_id);
	return "<a href=\"$url\" class=\"entity\">$link_text</a>";
}

function get_later_link($min_id = null) {
	$link_text = "<span class=\"text\">later</span> &rarr;";
	$max_id = get_later_id($min_id);
	if (empty($max_id)) {
		return $link_text;
	}
	$url = get_url_with_max_id($max_id);
	return "<a href=\"$url\" class=\"entity\">$link_text</a>";
}

function get_url_with_max_id($max_id) {
	$url = '?';
	foreach ($_GET as $key => $value) {
		if ($key != 'max_id') {
			$url .= urlencode($key) . '=' . urlencode($value) . '&amp;';
		}
	}
	$url .= "max_id=$max_id";
	return $url;
}

function get_earlier_id($max_id) {
	$search = '';
	$params = array($max_id);
	if (!empty($_GET['q'])) {
		$search = "AND twitter_favorite_search MATCH ?";
		$params[] = $_GET['q'];
	}
	$earlier = query("
		SELECT id
		FROM twitter_favorite_search
		WHERE id < ?
		$search
		ORDER BY id DESC
		LIMIT 1
	", $params);
	if (empty($earlier)) {
		return null;
	} else {
		$earlier = $earlier[0];
		return $earlier->id;
	}
}

function get_later_id($min_id) {
	$search = '';
	$params = array($min_id);
	if (!empty($_GET['q'])) {
		$search = "AND twitter_favorite_search MATCH ?";
		$params[] = $_GET['q'];
	}
	$later = query("
		SELECT id
		FROM twitter_favorite_search
		WHERE id > ?
		$search
		ORDER BY id
		LIMIT 20
	", $params);
	if (empty($later)) {
		return null;
	} else {
		$later = array_pop($later);
		return $later->id;
	}
}

function long_enough_since_last_check() {
	$min_duration = 5 * 60; // 5 minutes
	$now = time();
	$last_check = meta_get('last_check_for_new_favorites');
	if (empty($last_check) || $now - intval($last_check) > $min_duration) {
		meta_set('last_check_for_new_favorites', $now);
		return true;
	} else {
		return false;
	}
}

function meta_get($name) {
	global $_meta_cache;
	if (empty($_meta_cache)) {
		$_meta_cache = array();
		$twitter_meta = query("
			SELECT name, value
			FROM twitter_meta
		");
		foreach ($twitter_meta as $meta) {
			$_meta_cache[$meta->name] = $meta->value;
		}
	}
	$value = null;
	if (isset($_meta_cache[$name])) {
		$value = $_meta_cache[$name];
	}
	return $value;
}

function meta_set($name, $value) {
	global $_meta_cache;
	query("
		DELETE FROM twitter_meta
		WHERE name = ?
	", array($name));
	query("
		INSERT INTO twitter_meta
		(name, value)
		VALUES (?, ?)
	", array($name, $value));
	$_meta_cache[$name] = $value;
}

function db_migrate($version) {
	global $db;

	if ($version == 1) {
		query("
			ALTER TABLE twitter_favorite
			ADD COLUMN protected INT
		");
	} else if ($version == 2) {
		query("
			CREATE TABLE twitter_media (
				tweet_id INT,
				href VARCHAR(255),
				path VARCHAR(255),
				saved_at DATETIME
			);
		");
		query("
			CREATE INDEX twitter_media_index ON twitter_media (
				tweet_id, path
			);
		");
	} else if ($version == 3) {
		// Clear out the table, since it will have tons of duplicate rows. There was
		// a bug in profile image function.
		query("
			DELETE FROM twitter_media
		");
		query("
			ALTER TABLE twitter_media
			ADD COLUMN redirect VARCHAR(255)
		");
	}
	meta_set('db_version', $version);
}

function can_display_tweet($tweet) {
	if ($tweet->user->protected) {
		query("
			UPDATE twitter_favorite
			SET protected = 1
			WHERE id = ?
		", array($tweet->id));
		return false;
	}
	return true;
}

function local_media($tweet_id, $remote_url) {
	$path = local_media_get_cached($tweet_id, $remote_url);
	if ($path) {
		return $path;
	}
	if (! preg_match('#//(.+)$#', $remote_url, $matches)) {
		return $remote_url;
	}
	$path = 'data/media/' . $matches[1];
	if (preg_match('/(\.\w+):\w+$/', $path, $matches)) {
		// Don't save files that end with '.jpg:large', instead use '.jpg:large.jpg'
		$path .= $matches[1];
	}
	if (file_exists($path)) {
		local_media_set_cached($tweet_id, $remote_url, $path);
		return $path;
	}
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $remote_url);
	curl_setopt($ch, CURLOPT_HEADER, 0);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 8);
	$data = curl_exec($ch);
	$info = curl_getinfo($ch);
	curl_close($ch);
	if ($info['http_code'] < 200 ||
			$info['http_code'] > 299) {
		dbug($info);
		return false;
	}
	$dir = dirname($path);
	if (! file_exists($dir)) {
		mkdir($dir, 0755, true);
	}
	if (! file_exists($dir)) {
		return false;
	}
	file_put_contents($path, $data);

	local_media_set_cached($tweet_id, $remote_url, $path);

	return $path;
}

function local_media_get_cached($tweet_id, $remote_url) {
	$cached = query("
		SELECT *
		FROM twitter_media
		WHERE tweet_id = ?
			AND href = ?
	", array($tweet_id, $remote_url));
	if (! empty($cached)) {
		$media = $cached[0];
		if (! empty($media->redirect) &&
				$media_redirect != $remote_url) {
			return local_media($tweet_id, $media->redirect);
		}
		if (file_exists($media->path)) {
			return $media->path;
		} else {
			query("
				DELETE FROM twitter_media
				WHERE tweet_id = ?
					AND href = ?
			", array($tweet_id, $remote_url));
			return null;
		}
	}
}

function local_media_set_cached($tweet_id, $remote_url, $path) {
	$now = date('Y-m-d H:i:s');
	query("
		INSERT INTO twitter_media
		(tweet_id, href, path, saved_at)
		VALUES (?, ?, ?, ?)
	", array($tweet_id, $remote_url, $path, $now));
}

function tweet_profile_image($tweet) {
	$url = str_replace('_normal', '_bigger', $tweet->user->profile_image_url);
	$path = local_media($tweet->id, $url);
	if (! $path) {
		$updated_user = load_user_profile($tweet->user->id);
		$url = str_replace('_normal', '_bigger', $updated_user->profile_image_url);
		$path = local_media($tweet->id, $url);
		$orig_url = str_replace('_normal', '_bigger', $tweet->user->profile_image_url);
		if ($orig_url != $url) {
			$now = date('Y-m-d H:i:s');
			query("
				INSERT INTO twitter_media
				(tweet_id, path, href, redirect, saved_at)
				VALUES (?, ?, ?, ?, ?)
			", array($tweet->id, $orig_url, $url, $now));
		}
	}
	return $path;
}

function load_user_profile($id) {
	global $twitter;
	$rsp = $twitter->get('users/lookup', array(
		'user_id' => $id
	));
	if (! empty($rsp) && is_array($rsp)) {
		return $rsp[0];
	}
}

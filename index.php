<?php

$root = __DIR__;
require_once "$root/setup.php";

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

if ($config->inline_download) {
  echo '<!--';
  require_once  "$root/download.php";
  echo '-->';
}

$params = array();
$where = '';

if (!empty($_GET['max_id'])) {
  $where = 'WHERE twitter_favorite.id <= ?';
  $params = array($_GET['max_id']);
}

if (!empty($_GET['q'])) {
  $params[] = $_GET['q'];
  if (empty($where)) {
    $where = 'WHERE ';
  } else {
    $where .= 'AND ';
  }
  $where .= "
    twitter_favorite_search MATCH ?
    AND twitter_favorite_search.id = twitter_favorite.id
  ";
  $favs = query("
    SELECT twitter_favorite.id AS id,
           twitter_favorite.user AS user,
           twitter_favorite.json AS json,
           twitter_favorite.href AS href,
           twitter_favorite.created_at AS created_at
    FROM twitter_favorite,
         twitter_favorite_search
    $where
    ORDER BY twitter_favorite.created_at DESC
    LIMIT 20
  ", $params);
} else {
  $favs = query("
    SELECT *
    FROM twitter_favorite
    $where
    ORDER BY created_at DESC
    LIMIT 20
  ", $params);
}

$min_id = null;
$max_id = null;

show_header();

foreach ($favs as $index => $fav) {
  if ($index == 0) {
    $min_id = $fav->id;
  }
  $date = date('M j, Y, g:i a', strtotime($fav->created_at));
  $tweet = json_decode($fav->json);
  $content = tweet_content($tweet);
  echo "
    <article id=\"tweet-$fav->id\" class=\"tweet\">
      <div class=\"content\">
        <div class=\"user\">
          <a href=\"https://twitter.com/$fav->user\">
            <img src=\"{$tweet->user->profile_image_url}\" class=\"profile_image\">
            <span class=\"name\">{$tweet->user->name}</span>
            <span class=\"screen_name\">@$fav->user</span>
          </a>
        </div>
        <div class=\"text\">
          $content
        </div>
        <div class=\"meta\">
          <a href=\"$fav->href\">$date</a>
        </div>
      </div>
    </article>
  ";
}

if (!empty($fav)) {
  $max_id = $fav->id - 1;
} else {
  echo "<h2>Nothing found.</h2>";
}

show_footer($min_id, $max_id);

?>

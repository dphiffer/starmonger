<?php

$config = array(
  
  /*
   * 1. Choose where the SQLite database file lives
   *
   * The default is fine setting if you don't mind other people downloading your
   * data. Make sure to check that the permissions on the directory allow the
   * web server's user (usually 'www-data' or 'apache') has write permissions.
   */
  'database_path' => 'data/starmonger.db',
   
  /*
   * 2. Go to https://dev.twitter.com/apps and click 'Create a new application'
   *
   * Name - choose something unique, maybe your Twitter username
   * Description - anything is fine, maybe 'My personal Twitter archiver'
   * Website - anything is fine, you can use your Twitter profile URL
   * Callback URL - leave this blank
   *
   * After agreeing to the terms, passing the CAPTCHA test, you should see
   * the following 'Consumer key' and 'Consumer secret' values under
   * 'OAuth settings'.
   */
  'twitter_consumer_key' => 'XXXXXXXXXXXXXXXXXXXXXXXXXXXX',
  'twitter_consumer_secret' => 'XXXXXXXXXXXXXXXXXXXXXXXXXXXX',
  
  /*
   * 3. From your application page, click 'Create my access token'
   *
   * You should see a green message flash onto the top of the page momentarily.
   * If you scroll down, you'll probably find there is no access token
   * information available to you yet. Just reload the page in a minute or two
   * and you should eventually see the 'Access token' and 'Access token secret'
   * at the bottom of your application page. Enter those below.
   */
  'twitter_access_token' => 'XXXXXXXXXXXXXXXXXXXXXXXXXXXX',
  'twitter_access_token_secret' => 'XXXXXXXXXXXXXXXXXXXXXXXXXXXX'
   
);

?>

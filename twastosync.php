#!/usr/bin/php
<?php

  // twastosync v0.7a0
  //
  // Copyright (c) 2019-2023, Yahe
  // All rights reserved.
  //
  // Usage:
  // > ./twastosync.php
  //
  // This application is released under the BSD license.
  // See the LICENSE file for further information.

  // ========== STOP EDITING HERE IF YOU DO NOT KNOW WHAT YOU ARE DOING ==========

  // some composer magic
  require_once(__DIR__."/vendor/autoload.php");

  // we use the TwitterOAuth class
  use Abraham\TwitterOAuth\TwitterOAuth;

  // we use the unchroot library
  require_once(__DIR__."/../unchroot/unchroot.phs");

  // include the configuration
  require_once(__DIR__."/config.php");

  // static definitions
  define("LINE_BREAK",          "<br />");  // line break in description used for line breaks
  define("MAX_TWEET_LENGTH",    280);       // maximum tweet length
  define("MAX_TWEET_SUFFIX",    " [...]");  // suffix to append to long tweets
  define("MENTION_PREFIX",      "@");       // do not sync mentions
  define("NOT_FILTER_PREFIX",   "!");       // NOT filter string prefix
  define("PARAGRAPH_DELIMITER", "</p><p>"); // paragraph delimiter in description used for line breaks
  define("SUCCESS_CODE",        200);       // success return code

  function main($arguments) {
    if (disallow_concurrency(LOCK_FILE)) {
      try {
        // read whole file into memory, we don't care about memory usage
        $content = file_get_contents(MASTODON_FEED);
        if (false !== $content) {
          // read the status from the file
          $status = false;
          if (!is_file(STATUS_FILE)) {
            $status = [];
          } else {
            $status = file(STATUS_FILE, FILE_IGNORE_NEW_LINES);
            if (false !== $status) {
              $status = array_combine($status, $status);
            }
          }

          // only proceed if we have a new status or the status could be read from file
          if (false !== $status) {
            // parse the entries from the feed
            $entries = [];
            $rssfeed = @simplexml_load_string($content);
            if (false !== $rssfeed) {
              if (property_exists($rssfeed, "channel") && property_exists($rssfeed->channel, "item")) {
                // iterate through all feed items
                foreach ($rssfeed->channel->item as $item) {
                  if (property_exists($item, "description")) {
                    // retrieve description from parsed XML
                    $description = html_entity_decode((string)$item->description, ENT_QUOTES | ENT_HTML5);

                    // replace line breaks
                    $description = str_ireplace(LINE_BREAK,          PHP_EOL,         $description);
                    $description = str_ireplace(PARAGRAPH_DELIMITER, PHP_EOL.PHP_EOL, $description);

                    // cleanup the description
                    $description = strip_tags($description);

                    if (((null === FILTER_STRING) ||
                         ((0 === stripos(FILTER_STRING, NOT_FILTER_PREFIX)) &&
                          (false === stripos($description, substr(FILTER_STRING, strlen(NOT_FILTER_PREFIX))))) ||
                         ((0 !== stripos(FILTER_STRING, NOT_FILTER_PREFIX)) &&
                          (false !== stripos($description, FILTER_STRING)))) &&
                        (false === stripos($description, MENTION_PREFIX))) {
                      // add entry to entries list
                      $entries[] = $description;
                    }
                  }
                }
              }
            }
            $entries = array_reverse($entries);

            // use TwitterOAuth to create connection
            $connection = new TwitterOAuth(API_KEY, API_SECRET_KEY, ACCESS_TOKEN, ACCESS_TOKEN_SECRET);

            // set timeouts
            $connection->setTimeouts(30, 30);

            // iterate through all entries and tweet them if we don't know them
            $newstatus = false;
            foreach ($entries as $entry) {
              $hash = hash("sha256", $entry, false);

              // check if this is a known entry
              if (!array_key_exists($hash, $status)) {
                // remove the filter string
                if ((null !== FILTER_STRING) && REMOVE_FILTER_STRING) {
                  $entry = str_replace(FILTER_STRING, "", $entry);
                }

                // trim the entry
                if (TRIM_MESSAGE) {
                  $entry = trim($entry);
                }

                // enforce the maximum tweet length
                if (MAX_TWEET_LENGTH < strlen($entry)) {
                  $entry = substr($entry, 0, MAX_TWEET_LENGTH-strlen(MAX_TWEET_SUFFIX)).MAX_TWEET_SUFFIX;
                }

                // execute the  POST request
                $result = $connection->post("statuses/update", ["status" => $entry]);

                // check the return code
                if (SUCCESS_CODE === $connection->getLastHttpCode()) {
                  // add new entry to the status
                  $status[$hash] = $hash;
                  $newstatus     = true;

                  print("TWEETED: $entry\n");
                } else {
                  print("FAILED: $entry\n");
                }
              }
            }

            // store the new status
            if ($newstatus) {
              if (false === file_put_contents(STATUS_FILE, implode("\n", $status))) {
                print("ERROR: new status could not be stored");
              }
            }
          } else {
            print("ERROR: Status file could not be read\n");
          }
        } else {
          print("ERROR: Mastodon Feed could not be read\n");
        }
      } finally {
        allow_concurrency(LOCK_FILE);
      }
    } else {
      print("ERROR: Parallel execution is not supported\n");
    }
  }

  main($argv);


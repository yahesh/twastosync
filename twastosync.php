#!/usr/bin/env php
<?php

  // twastosync v0.8a1
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
  require_once(__DIR__."/../vendor/autoload.php");

  // we use the TwitterOAuth class
  use Abraham\TwitterOAuth\TwitterOAuth;

  // we use the unchroot library
  require_once(__DIR__."/../unchroot/unchroot.phs");

  // include the configuration
  require_once(__DIR__."/config.php");

  // static definitions
  define("ALT_TEXT",                 "alt_text");              // alt-text field name
  define("CHANNEL",                  "channel");               // channel field name
  define("DESCRIPTION",              "description");           // description field name
  define("HORIZONTAL_LINE",          "<hr />");                // horizontal line in description used for content warnings
  define("ITEM",                     "item");                  // item field name
  define("LINE_BREAK",               "<br />");                // line break in description used for line breaks
  define("MAX_TWEET_LENGTH",         280);                     // maximum tweet length
  define("MAX_TWEET_SUFFIX",         " [...]");                // suffix to append to long tweets
  define("MEDIA",                    "media");                 // media field name
  define("MEDIA_CONTENT_PREFIX",     "<media:content");        // media content prefix we need to replace
  define("MEDIA_CONTENT_SUFFIX",     "</media:content>");      // media content suffix we need to replace
  define("MEDIA_DESCRIPTION_PREFIX", "<media:description");    // media description prefix we need to replace
  define("MEDIA_DESCRIPTION_SUFFIX", "</media:description>");  // media description suffix we need to replace
  define("MEDIA_ID",                 "media_id");              // media-id field name
  define("MEDIA_IDS",                "media_ids");             // media-ids field name
  define("MEDIA_RATING_PREFIX",      "<media:rating");         // media rating prefix we need to replace
  define("MEDIA_RATING_SUFFIX",      "</media:rating>");       // media rating suffix we need to replace
  define("MEDIACONTENT",             "mediacontent");          // mediacontent field name
  define("MEDIACONTENT_PREFIX",      "<mediacontent");         // replaced media content prefix
  define("MEDIACONTENT_SUFFIX",      "</mediacontent>");       // replaced media content suffix
  define("MEDIADESCRIPTION",         "mediadescription");      // mediadescription field name
  define("MEDIADESCRIPTION_PREFIX",  "<mediadescription");     // replaced media description prefix
  define("MEDIADESCRIPTION_SUFFIX",  "</mediadescription>");   // replaced media description suffix
  define("MEDIARATING",              "mediarating");           // mediarating field name
  define("MEDIARATING_PREFIX",       "<mediarating");          // replaced media rating prefix
  define("MEDIARATING_SUFFIX",       "</mediarating>");        // replaced media rating suffix
  define("MENTION_PREFIX",           "@");                     // do not sync mentions
  define("NOT_FILTER_PREFIX",        "!");                     // NOT filter string prefix
  define("PARAGRAPH_DELIMITER",      "</p><p>");               // paragraph delimiter in description used for line breaks
  define("STATUS",                   "status");                // status field name
  define("SUCCESS_CODE",             200);                     // success return code
  define("TEXT",                     "text");                  // text field name
  define("TWITTER_MEDIA_METHOD",     "media/upload");          // twitter API method to upload a media file
  define("TWITTER_METADATA_METHOD",  "media/metadata/create"); // twitter API method to add alt text to a media file
  define("TWITTER_STATUS_METHOD",    "statuses/update");       // twitter API method to post a status
  define("URL",                      "url");                   // url field name

  function main($arguments) {
    if (force_unroot(PROCESS_USER, PROCESS_GROUP)) {
      if (disallow_concurrency(LOCK_FILE)) {
        try {
          // read whole file into memory, we don't care about memory usage
          $content = file_get_contents(MASTODON_FEED);
          if (false !== $content) {
            // read the status from the file
            $knownlist = false;
            if (!is_file(STATUS_FILE)) {
              $knownlist = [];
            } else {
              $knownlist = file(STATUS_FILE, FILE_IGNORE_NEW_LINES);
              if (false !== $knownlist) {
                $knownlist = array_combine($knownlist, $knownlist);
              }
            }

            // only proceed if we have a new known-list or the known-list could be read from file
            if (false !== $knownlist) {
              // allow SimpleXML to parse media info
              $content = str_replace([MEDIA_CONTENT_PREFIX, MEDIA_CONTENT_SUFFIX, MEDIA_DESCRIPTION_PREFIX, MEDIA_DESCRIPTION_SUFFIX, MEDIA_RATING_PREFIX, MEDIA_RATING_SUFFIX],
                                     [MEDIACONTENT_PREFIX,  MEDIACONTENT_SUFFIX,  MEDIADESCRIPTION_PREFIX,  MEDIADESCRIPTION_SUFFIX,  MEDIARATING_PREFIX,  MEDIARATING_SUFFIX],
                                     $content);

              // parse the entries from the feed
              $entries = [];
              $rssfeed = @simplexml_load_string($content);
              if (false !== $rssfeed) {
                if (property_exists($rssfeed, CHANNEL) && property_exists($rssfeed->channel, ITEM)) {
                  // iterate through all feed items
                  foreach ($rssfeed->channel->item as $item) {
                    $status = null;
                    $media  = [];

                    if (property_exists($item, DESCRIPTION)) {
                      // retrieve description from parsed XML
                      $tmp = html_entity_decode((string)$item->description, ENT_QUOTES | ENT_HTML5);

                      // replace line breaks
                      $tmp = str_ireplace([HORIZONTAL_LINE, LINE_BREAK, PARAGRAPH_DELIMITER],
                                          [PHP_EOL.PHP_EOL, PHP_EOL,    PHP_EOL.PHP_EOL    ],
                                          $tmp);

                      // cleanup the description
                      $tmp = strip_tags($tmp);

                      if (((null === FILTER_STRING) ||
                           ((0 === stripos(FILTER_STRING, NOT_FILTER_PREFIX)) &&
                            (false === stripos($tmp, substr(FILTER_STRING, strlen(NOT_FILTER_PREFIX))))) ||
                           ((0 !== stripos(FILTER_STRING, NOT_FILTER_PREFIX)) &&
                            (false !== stripos($tmp, FILTER_STRING)))) &&
                          (false === stripos($tmp, MENTION_PREFIX))) {
                        // add entry to entries list
                        $status = $tmp;
                      }
                    }

                    if (property_exists($item, MEDIACONTENT)) {
                      foreach ($item->mediacontent as $mediacontent) {
                        $tmp = [MEDIACONTENT => null, MEDIADESCRIPTION => null, MEDIARATING => null];

                        if (property_exists($mediacontent->attributes(), URL)) {
                          $tmp[MEDIACONTENT] = html_entity_decode((string)$mediacontent->attributes()->url, ENT_QUOTES | ENT_HTML5);
                        }
                        if (property_exists($mediacontent, MEDIADESCRIPTION)) {
                          $tmp[MEDIADESCRIPTION] = html_entity_decode((string)$mediacontent->mediadescription, ENT_QUOTES | ENT_HTML5);
                        }
                        if (property_exists($mediacontent, MEDIARATING)) {
                          $tmp[MEDIARATING] = html_entity_decode((string)$mediacontent->mediarating, ENT_QUOTES | ENT_HTML5);
                        }

                        if (null !== $tmp[MEDIACONTENT]) {
                          $media[] = $tmp;
                        }
                      }
                    }

                    if (null !== $status) {
                      $entries[] = [STATUS => $status, MEDIA  => $media];
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
              $newknownlist = false;
              foreach ($entries as $entry) {
                $hash = hash("sha256", $entry[STATUS], false);

                // check if this is a known entry
                if (!array_key_exists($hash, $knownlist)) {
                  // we assume that we will succeed
                  $success = true;

                  // remove the filter string
                  if ((null !== FILTER_STRING) && REMOVE_FILTER_STRING) {
                    $entry[STATUS] = str_replace(FILTER_STRING, "", $entry[STATUS]);
                  }

                  // trim the entry
                  if (TRIM_MESSAGE) {
                    $entry[STATUS] = trim($entry[STATUS]);
                  }

                  // enforce the maximum tweet length
                  if (MAX_TWEET_LENGTH < strlen($entry[STATUS])) {
                    $entry[STATUS] = substr($entry[STATUS], 0, MAX_TWEET_LENGTH-strlen(MAX_TWEET_SUFFIX)).MAX_TWEET_SUFFIX;
                  }

                  // assume we succeed with the upload
                  $media_ids = [];
                  foreach ($entry[MEDIA] as $media) {
                    if ($success) {
                      // execute the media upload POST request
                      $result  = $connection->upload(TWITTER_MEDIA_METHOD, [MEDIA => $media[MEDIACONTENT]]);
                      $success = (SUCCESS_CODE === $connection->getLastHttpCode());
                      if ($success) {
                        // append media id to list
                        $media_ids[] = $result->media_id_string;

                        // check if we need to set an alt-text
                        if (null !== $media[MEDIADESCRIPTION]) {
                          // execute the alt-text creation POST request
                          $result  = $connection->post(TWITTER_METADATA_METHOD, [MEDIA_ID => $result->media_id_string, ALT_TEXT => [TEXT => $media[MEDIADESCRIPTION]]]);
                          $success = (SUCCESS_CODE === $connection->getLastHttpCode());
                        }
                      }
                    }
                  }

                  if ($success) {
                    // execute the status POST request
                    $result  = $connection->post(TWITTER_STATUS_METHOD, [STATUS => $entry[STATUS], MEDIA_IDS => implode(",", $media_ids)]);
                    $success = (SUCCESS_CODE === $connection->getLastHttpCode());
                  }

                  // check the return code
                  if ($success) {
                    // add new entry to the known-list
                    $knownlist[$hash] = $hash;
                    $newknownlist     = true;

                    print("TWEETED: ".$entry[STATUS]."\n");
                  } else {
                    print("FAILED: ".$entry[STATUS]."\n");
                  }
                } else {
                  print("SKIPPED: ".$entry[STATUS]."\n");
                }
              }

              // store the new known-list
              if ($newknownlist) {
                if (false === file_put_contents(STATUS_FILE, implode("\n", $knownlist))) {
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
    } else {
      print("ERROR: Privileges could not be dropped\n");
    }
  }

  exit(main($argv));


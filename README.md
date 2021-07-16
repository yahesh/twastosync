# twastosync

This script can be used to sync Mastodon toots to Twitter. It uses the [TwitterOAuth](https://twitteroauth.com/) library to access the official Twitter API.

## Preparation
1. Log in to [twitter.com](https://twitter.com/) and visit [developer.twitter.com/en/apps](https://developer.twitter.com/en/apps).
2. Click on `Create New App` and enter the requested, mandatory information, agree to the Twitter Developer Agreement and click on `Create your Twitter application`.
3. Visit the `Keys and tokens` tab and note the `API key` and `API secret key` values in the `Consumer API keys` section for the configuration of this script.
4. Scroll down to the `Access token & access token secret` section and click on `Create`. Note the `Access token` and `Access token secret` values for the configuration of this script.
5. Clone the required library `git clone https://github.com/yahesh/unchroot`.
6. Enter the folder in which you downloaded this script with a command shell and execute `composer require abraham/twitteroauth` to install the TwitterOAuth library.
7. Copy `config.php.default` to `config.php` and configure the script.

## Usage
```
./twastosync.php
```

## License
This application is released under the MIT license.
See the [LICENSE](LICENSE) file for further information.

## Copyright
Copyright (c) 2019-2021, Yahe

All rights reserved.

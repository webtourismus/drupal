<?php

declare(strict_types=1);

/**
 * This file is included very early. See autoload.files in composer.json and
 * https://getcomposer.org/doc/04-schema.md#files
 */

use Dotenv\Dotenv;

/**
 * Load .env settings from either the current dir (typically on "live" environment)
 * or the parent dir (typically on "dev" environment). If both files are found (typically dev),
 * values from the parent dir overrule values from the current dir.
 */
$dotenv = Dotenv::createUnsafeMutable([__DIR__, __DIR__ . '/..'], '.env', FALSE);
$dotenv->safeLoad();
/**
 * ENV is assumed to be set in Drupal's settings.php, so provide a safe default.
 */
if (empty($_ENV['ENV'])) {
  $_ENV['ENV'] = 'live';
  putenv("ENV={$_ENV['ENV']}");
}
/**
 * PROJECT_NAME is used for composer deployment and maintenance scripts.
 * This setting is mandatory for "live" environments, but on "dev" environments we can safely compute it.
 */
if (empty($_ENV['PROJECT_NAME'])) {
  if ($_ENV['ENV'] === 'dev') {
    preg_match('#^/var/www/vhosts/dev1\.webtourismus\.at/([a-z0-9][a-z0-9_-]*)\.dev1\.webtourismus\.at$#', __DIR__, $dotEnvMatches);
    if (!empty($dotEnvMatches[1])) {
      $_ENV['PROJECT_NAME'] = $dotEnvMatches[1];
      putenv("PROJECT_NAME={$_ENV['PROJECT_NAME']}");
    }
  }
}

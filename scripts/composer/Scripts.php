<?php

declare(strict_types=1);

namespace Webtourismus\Composer;

require_once('load.environment.php');

use Composer\Config;
use Composer\Script\Event;
use DrupalFinder\DrupalFinder;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

class Scripts
{
  private static function verifyDevEnvironment(Event $event) {
    if ($_ENV['ENV'] !== 'dev') {
      $io = $event->getIO();
      $io->writeError("This operation is only allowed on 'dev' environments, but the current environment is '{$_ENV['ENV']}'.");
      exit(1);
    }
  }

  public static function createDefaultFiles(Event $event)
  {
    $drupalFinder = new DrupalFinder();
    $drupalFinder->locateRoot(getcwd());
    $drupalRoot = $drupalFinder->getDrupalRoot();
    $fs = new Filesystem();

    if (!$fs->exists( "{$drupalRoot}/sites/default/settings.php") && $fs->exists("{$drupalRoot}/sites/default/default.settings.php")) {
      $fs->copy("{$drupalRoot}/sites/default/default.settings.php", "{$drupalRoot}/sites/default/settings.php");
      $fs->chmod("{$drupalRoot}/sites/default/settings.php", 0664);
      $fs->appendToFile("{$drupalRoot}/sites/default/settings.php", file_get_contents('scripts/scaffold/drupal_settings/append.default.settings.php.txt'));
    }
    if (!$fs->exists("{$drupalRoot}/sites/default/files")) {
      $oldmask = umask(0);
      $fs->mkdir("{$drupalRoot}/sites/default/files", 0775);
      umask($oldmask);
    }
    if (!$fs->exists("{$drupalRoot}/webcam/.htaccess")) {
      if (!$fs->exists("{$drupalRoot}/webcam/.")) {
        $oldmask = umask(0);
        $fs->mkdir("{$drupalRoot}/webcam", 0775);
        umask($oldmask);
      }
      $fs->copy('scripts/scaffold/webcam/.htaccess', "{$drupalRoot}/webcam/.htaccess");
      $fs->chmod("{$drupalRoot}/webcam/.htaccess", 0664);
    }
    if (!$fs->exists("redirect_domains/.htaccess") && !empty($_ENV['LIVE_DOMAIN'])) {
      if (!$fs->exists("{$drupalRoot}/redirect_domains/.")) {
        $oldmask = umask(0);
        $fs->mkdir("redirect_domains", 0775);
        umask($oldmask);
      }
      $htAccess = file_get_contents('scripts/scaffold/redirect_domains/.htaccess.txt');
      $htAccess = str_replace('___PLACEHOLDER_LIVE_DOMAIN___', $_ENV['LIVE_DOMAIN'], $htAccess);
      $fs->dumpFile("redirect_domains/.htaccess", $htAccess);
      $fs->chmod("redirect_domains/.htaccess", 0664);
    }
  }

  public static function installDrupal(Event $event)
  {
    self::verifyDevEnvironment($event);

    Config::disableProcessTimeout();
    $drupalFinder = new DrupalFinder();
    $drupalFinder->locateRoot(getcwd());
    $drupalRoot = $drupalFinder->getDrupalRoot();
    $io = $event->getIO();
    $fs = new Filesystem();

    /**
     * @var $dotenv Dotenv\Dotenv
     */
    $dotenv->required('ENV')->allowedValues(['dev']);
    $dotenv->required('DB_HOST')->notEmpty();
    $dotenv->required('DB_PORT')->isInteger();
    $dotenv->required('DB_NAME')->notEmpty();
    $dotenv->required('DB_USER')->notEmpty();
    $dotenv->required('DB_PASS')->notEmpty();

    if (!file_exists("{$drupalRoot}/sites/default/settings.php")) {
      $io->writeError('"install-drupal" needs a pre-exisiting settings.php.');
      exit(1);
    }

    /**
     * Install a default minimal profile
     * - with primary language EN (we do this even for "german only" sites, to avoid problems with config translation)
     * - with pre-set account data for the special user ID #1
     */
    system("./vendor/bin/drush site:install minimal --locale=en --account-name=entwicklung --account-mail=entwicklung@webtourismus.at --no-interaction", $cliError);
    if ($cliError) {
      $io->writeError('Drupal installation failed.');
      exit(1);
    }
    $defaultUsers = [
      [
        'name' => 'webtourismus',
        'mail' => 'office@webtourismus.at',
        'roles' => [
          'administrator',
          'editor',
        ],
      ],
      [
        'name' => 'entwicklung_editor',
        'mail' => 'entwicklung_editor@webtourismus.at',
        'roles' => [
          'editor',
        ],
      ],
    ];
    foreach ($defaultUsers as $user) {
      system("./vendor/bin/drush user:create {$user['name']} --mail={$user['mail']}", $cliError);
      foreach ($user['roles'] as $role) {
        system("./vendor/bin/drush user:role:add {$role} {$user['name']}", $cliError);
      }
    }
  }

  public static function subtheme(Event $event)
  {
    self::verifyDevEnvironment($event);

    /**
     * @FIXME
     *
     * Vorlarge kopiert von UIkit theme, anpassen
     */
    $drupalFinder = new DrupalFinder();
    $drupalFinder->locateRoot(getcwd());
    $drupalRoot = $drupalFinder->getDrupalRoot();
    $io = $event->getIO();
    $fs = new Filesystem();
    $finder = new Finder();

    $subName = 'wt';
    $args = $event->getArguments();
    if (!empty($args)) {
      $input = $args[0];
      $stripChars = preg_replace('/[^a-zA-Z\_\s]*/', '', $input);
      $stripSpace = preg_replace('/\s+/', '_', $stripChars);
      $subName = strtolower($stripSpace);

      if ($args[0] != $subName) {
        if (!$io->askConfirmation("Subtheme name will be simplified from \"{$args[0]}\" to \"{$subName}\". Do you want to continue with this name?")) {
          $io->writeError('Subtheme generation cancelled.');
          exit(1);
        }
      }
    }

    $parentTheme = $drupalRoot . '/themes/contrib/wtbase';
    $subDir = $drupalRoot . '/themes/custom/' . $subName;
    $fs->mirror(getcwd() . '/STARTERKIT', $subDir);

    // Rename STARTERKIT.* files
    $finder = new Finder();
    $finder->files()->name('/STARTERKIT/')->in($subDir);

    foreach ($finder as $file) {
      $location_segments = explode('/', $file->getRealPath());
      $old_filename = array_pop($location_segments);
      $location = implode('/', $location_segments) . '/';

      $new_filename = preg_replace('/STARTERKIT/', $subName, $old_filename);

      $fs->rename($file->getRealPath(), $location . $new_filename);
    }

    // Activate subtheme .info file.
    $finder = new Finder();
    $finder->files()->name('*.ymltmp')->in('../' . $subName);

    foreach ($finder as $file) {
      $location_segments = explode('/', $file->getRealPath());
      $old_filename = array_pop($location_segments);
      $location = implode('/', $location_segments) . '/';

      $new_filename = preg_replace('/ymltmp/', 'yml', $old_filename);

      $fs->rename($file->getRealPath(), $location . $new_filename);
    }

    $finder->files()->contains('/STARTERKIT/')->in('../' . $subName);

    foreach ($finder as $file) {
      $old_contents = file_get_contents($file->getRealPath());

      $new_contents = preg_replace('/STARTERKIT/', $subName, $old_contents);

      file_put_contents($file->getRealPath(), $new_contents);
    }
  }
}

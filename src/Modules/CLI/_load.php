<?php
declare(strict_types=1);

/*
Plugin Name: WP Sync DB CLI
GitHub Plugin URI: hrsetyono/wp-sync-db-cli
Description: An extension to WP Sync DB, allows you to execute migrations using a function call or via WP-CLI
Author: Pixel Studio
Version: 1.0b1
Plugin URI: https://github.com/hrsetyono/wp-sync-db-cli
Author URI: https://pixelstudio.id
Network: True
*/

use WPSDB\Modules\CLI\WPSDB_CLI;
use WPSDB\Modules\CLI\WPSDBCLI;

if (function_exists('wp_sync_db_cli_loaded')) {
  // If the deprecated plugin wp-sync-db-media-files is installed
  add_action('admin_notices', function (): void {
    echo '<div class="notice notice-warning is-dismissible"><p>' . __('The new version of <code>WP Sync DB</code> now includes the cli module, we have automatically disabled the now deprecated <code>WP Sync DB CLI</code> plugin, you can delete it.', 'wp-sync-db-media-files') . '</p></div>';
  });

  // Disable the plugin
  $url = plugins_url();
  $path = parse_url($url);
  deactivate_plugins('wp-sync-db-cli/wp-sync-db-cli.php');
}

/**
 * Initialize the WP Sync DB CLI module once plugins are loaded.
 *
 * Registers the WP-CLI command if WP_CLI is defined and initializes the CLI class.
 *
 * @since 1.0
 * @return void
 */
function wp_sync_db_module_cli_loaded(): void
{
  if (! class_exists(\WPSDB\WPSDB_Base::class)) return;

  // register with wp-cli if it's running, and command hasn't already been defined elsewhere
  if (defined('WP_CLI') && WP_CLI && ! class_exists('WPSDBCLI')) {
    WP_CLI::add_command('wpsdb', WPSDBCLI::class);
  }

  global $wpsdb_cli;
  $wpsdb_cli = new WPSDB_CLI(__FILE__);
}

/**
 * Fires the CLI module initialization after WordPress has finished loading all plugins.
 *
 * @since 1.0
 * @param callable $function_to_add The callback function to invoke.
 * @param int      $priority        The execution priority (20).
 * @param int      $accepted_args   Number of arguments the callback accepts.
 */
add_action('plugins_loaded', 'wp_sync_db_module_cli_loaded', 20);

function wpsdb_migrate($profile)
{
  global $wpsdb_cli;
  if (empty($wpsdb_cli)) {
    return new WP_Error('wpsdb_cli_error', __('WP Sync DB CLI class not available', 'wp-sync-db-cli'));
  }

  return $wpsdb_cli->cli_migration($profile);
}

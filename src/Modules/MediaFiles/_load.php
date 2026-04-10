<?php
declare(strict_types=1);

/*
Origin: http://github.com/wp-sync-db/wp-sync-db-media-files
*/

use WPSDB\Modules\MediaFiles\WPSDB_Media_Files;

if (function_exists('wp_sync_db_media_files_loaded')) {
  // If the deprecated plugin wp-sync-db-media-files is installed
  add_action('admin_notices', function (): void {
    echo '<div class="notice notice-warning is-dismissible"><p>' . __('The new version of <code>WP Sync DB</code> now includes the media module, we have automatically disabled the now deprecated <code>WP Sync DB Media Files</code> plugin, you can delete it.', 'wp-sync-db-media-files') . '</p></div>';
  });

  // Disable the plugin
  $url = plugins_url();
  $path = parse_url($url);
  deactivate_plugins('wp-sync-db-media-files/wp-sync-db-media-files.php');
}

/**
 * Initialize the WP Sync DB Media Files module once plugins are loaded.
 *
 * Checks for the WPSDB_Base class and instantiates the media files module.
 *
 * @since 1.0
 * @return void
 */
function wp_sync_db_module_media_files_loaded(): void
{
  if (! class_exists(\WPSDB\WPSDB_Base::class)) return;

  global $wpsdb_media_files;
  $wpsdb_media_files = new WPSDB_Media_Files(__FILE__);
}

/**
 * Fires the Media Files module initialization after WordPress has finished loading all plugins.
 *
 * @since 1.0
 * @param callable $function_to_add The callback function to invoke.
 * @param int      $priority        The execution priority (20).
 * @param int      $accepted_args   Number of arguments the callback accepts.
 */
add_action('plugins_loaded', 'wp_sync_db_module_media_files_loaded', 20);

<?php
/**
 * InsightX Backup, bundled into InsightX AllHub (menu "Backup") since
 * Offload 0.3.0. Formerly the separate insightx-backup plugin (0.1.31): the
 * code is that plugin's, unchanged except where it means "this plugin's own
 * folder", which is now all of InsightX AllHub.
 *
 * Every option, cron hook, storage folder and WP-CLI command keeps its old
 * name, so a site that ran the standalone plugin carries on as it was.
 * Loaded by insightx-allhub.php only when the standalone plugin is not
 * active (both at once would declare everything twice).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ISX_VERSION', '0.1.31-offload' );
// The plugin file WordPress knows: import and Reset Hub keep it in
// active_plugins (plugin_basename( ISX_FILE )).
define( 'ISX_FILE', ISXM_PLUGIN_DIR . 'insightx-allhub.php' );
define( 'ISX_PATH', plugin_dir_path( __FILE__ ) );
define( 'ISX_URL', plugin_dir_url( __FILE__ ) );
// Never deleted or overwritten by an import, never chosen as storage.
define( 'ISX_PLUGIN_ROOT', untrailingslashit( ISXM_PLUGIN_DIR ) );

// Not hosted on wordpress.org, so WP never fetches a language pack for this
// plugin — load the bundled languages/*.mo ourselves. English is the source
// language; any locale without a .mo falls back to it.
add_action(
	'init',
	function () {
		load_plugin_textdomain( 'insightx-backup', false, dirname( plugin_basename( ISX_FILE ) ) . '/modules/backup/languages' );
	}
);

/**
 * Where in-progress job data and finished local backups live. Defaults to the
 * plugin's own storage/ dir, but an admin can point it elsewhere from Settings
 * (see ISX_Admin::ajax_storage_dir_save()) so backups survive a plugin
 * delete/reinstall or land on a bigger disk. Falls back to the default when the
 * saved path isn't usable — but never rewrites the option to say so.
 *
 * Two things here used to make in-flight jobs vanish with "Job not found":
 *
 *  1. The check asked whether the *parent* was writable. For the usual kind of
 *     custom path (/home/user/isx-storage) the parent is the account's home
 *     directory, which PHP normally cannot write to — so a perfectly good,
 *     writable storage directory failed the test.
 *  2. Failing the test called delete_option(). A getter that runs on every
 *     single request silently discarding the admin's setting meant
 *     ISX_STORAGE_PATH flipped back to the default mid-run, and every job
 *     already living under the custom path became unreachable.
 *
 * So: test the directory itself, and leave the option alone. Only
 * ISX_Admin::ajax_storage_dir_save() — where the admin is actually asking for a
 * change — may write it.
 *
 * @return string
 */
/**
 * Default storage: wp-content/insightx-backup, OUTSIDE the plugin folder.
 * WordPress deletes and replaces the plugin folder on every update, so the
 * old default (the plugin's own storage/ dir) lost every local backup and
 * any in-flight job whenever the plugin was updated.
 */
define( 'ISX_DEFAULT_STORAGE_PATH', untrailingslashit( WP_CONTENT_DIR ) . '/insightx-backup' );
/** Pre-0.1.25 default, still searched for jobs and moved over once. */
define( 'ISX_LEGACY_STORAGE_PATH', untrailingslashit( ISX_PATH . 'storage' ) );

require_once ISX_PATH . 'includes/functions-isx-storage.php';

function isx_resolve_storage_path() {
	$default = ISX_DEFAULT_STORAGE_PATH;
	$custom  = get_option( 'isx_storage_path', '' );
	if ( ! is_string( $custom ) || $custom === '' ) {
		return $default;
	}

	$custom = untrailingslashit( $custom );
	if ( isx_storage_path_conflict( $custom ) !== '' ) {
		// Saved before this check existed — never use it.
		$GLOBALS['isx_storage_path_fallback'] = $custom;
		return $default;
	}
	if ( is_dir( $custom ) && is_writable( $custom ) ) {
		return $custom;
	}

	// Not there yet (a fresh save, or a disk that came back after a reboot) —
	// try to create it before giving up on it.
	if ( ! is_dir( $custom ) && wp_mkdir_p( $custom ) && is_writable( $custom ) ) {
		return $custom;
	}

	$GLOBALS['isx_storage_path_fallback'] = $custom;
	return $default;
}
define( 'ISX_STORAGE_PATH', isx_resolve_storage_path() );

/**
 * Deny-all protection for the storage root (the backups/ subdir manages its
 * own rules — see ISX_Backups::dir()).
 */
function isx_protect_storage() {
	if ( ! is_dir( ISX_STORAGE_PATH ) ) {
		wp_mkdir_p( ISX_STORAGE_PATH );
	}
	$htaccess = ISX_STORAGE_PATH . '/.htaccess';
	if ( ! file_exists( $htaccess ) || trim( (string) @file_get_contents( $htaccess ) ) === 'Deny from all' ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
		file_put_contents( $htaccess, isx_htaccess_deny_all() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	$index = ISX_STORAGE_PATH . '/index.php';
	if ( ! file_exists( $index ) ) {
		file_put_contents( $index, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

/**
 * Move backups and logs from the old in-plugin default to the new one.
 *
 * Only possible when the old folder survived the update (manual/FTP/git
 * installs) — WordPress' own updater has already deleted it by the time this
 * code runs. Repeats until nothing is left to move; jobs still in flight
 * stay where they are (ISX_Job searches both locations).
 */
function isx_migrate_legacy_storage() {
	if ( ISX_STORAGE_PATH !== ISX_DEFAULT_STORAGE_PATH || get_option( 'isx_storage_migrated' ) ) {
		return;
	}
	$left = 0;
	foreach ( array( 'backups', 'logs' ) as $sub ) {
		$from = ISX_LEGACY_STORAGE_PATH . '/' . $sub;
		if ( ! is_dir( $from ) ) {
			continue;
		}
		$to = ISX_DEFAULT_STORAGE_PATH . '/' . $sub;
		wp_mkdir_p( $to );
		foreach ( (array) glob( $from . '/*' ) as $file ) {
			$name = basename( (string) $file );
			if ( ! is_file( $file ) || $name === 'index.php' ) {
				continue;
			}
			if ( file_exists( $to . '/' . $name ) || ! @rename( $file, $to . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$left++;
			}
		}
	}
	isx_protect_storage();
	if ( $left === 0 ) {
		update_option( 'isx_storage_migrated', 1, false );
	}
}
isx_migrate_legacy_storage();

/**
 * Surface the fallback above, which is otherwise completely invisible: backups
 * quietly start landing somewhere other than where the admin pointed them.
 */
add_action(
	'admin_notices',
	function () {
		if ( empty( $GLOBALS['isx_storage_path_fallback'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>InsightX Backup:</strong> %s</p></div>',
			sprintf(
				/* translators: %s: storage folder path */
				esc_html__( 'Storage folder %s is unavailable (missing or not writable) — using the default folder instead. Please fix it on the settings page', 'insightx-backup' ),
				'<code>' . esc_html( $GLOBALS['isx_storage_path_fallback'] ) . '</code>'
			)
		);
	}
);

/**
 * Simple, explicit class loader (no reliance on any third-party autoloader).
 */
require_once ISX_PATH . 'includes/class-isx-logger.php';
require_once ISX_PATH . 'includes/class-isx-crypto.php';
require_once ISX_PATH . 'includes/class-isx-compress.php';
require_once ISX_PATH . 'includes/class-isx-serialize.php';
require_once ISX_PATH . 'includes/class-isx-archive.php';
require_once ISX_PATH . 'includes/class-isx-database.php';
require_once ISX_PATH . 'includes/class-isx-files.php';
require_once ISX_PATH . 'includes/class-isx-job.php';
require_once ISX_PATH . 'includes/class-isx-backups.php';
require_once ISX_PATH . 'includes/class-isx-destinations.php';
require_once ISX_PATH . 'includes/class-isx-s3-client.php';
require_once ISX_PATH . 'includes/class-isx-export.php';
require_once ISX_PATH . 'includes/class-isx-import.php';
require_once ISX_PATH . 'includes/class-isx-reset.php';
require_once ISX_PATH . 'includes/class-isx-admin.php';

// The background driver hook must be registered on every request — including
// wp-cron.php runs, which are NOT is_admin() — otherwise a scheduled tick has
// no callback to invoke and a job would only ever advance while a browser
// tab is actively polling it.
add_action( 'isx_cron_step', array( 'ISX_Admin', 'cron_step' ) );
add_action( 'isx_scheduled_backup', array( 'ISX_Admin', 'run_scheduled_backup' ) );

if ( is_admin() ) {
	ISX_Admin::boot();
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ISX_PATH . 'includes/class-isx-cli.php';
	// Registered on cli_init rather than directly here — All-in-One WP
	// Migration's own changelog notes moving their command registration from
	// plugins_loaded to cli_init specifically to fix ordering issues, so we
	// follow the same, already-proven-safe hook.
	add_action(
		'cli_init',
		function () {
			WP_CLI::add_command( 'isx', 'ISX_CLI_Command' );
		}
	);
}

/**
 * Deny-all rules for a storage directory.
 *
 * The previous one-liner was "Deny from all" — Apache 2.2 syntax, which on a
 * 2.4 server without mod_access_compat is not just ignored but a hard 500 for
 * everything in the directory. Emit both dialects, each guarded by the module
 * that understands it.
 *
 * Note this does nothing at all on nginx, which never reads .htaccess — the
 * unguessable file names from ISX_Backups::store() are what actually protect
 * backups there.
 *
 * @return string
 */
function isx_htaccess_deny_all() {
	return "<IfModule mod_authz_core.c>\n"
		. "\tRequire all denied\n"
		. "</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n"
		. "\tOrder allow,deny\n"
		. "\tDeny from all\n"
		. "</IfModule>\n";
}

/**
 * Activation: prepare a protected storage directory for in-progress jobs.
 */
function isx_activate() {
	isx_protect_storage();
}

/**
 * Deactivation: stop the scheduled-backup cron so it doesn't keep firing
 * (and failing to find its own hook) after the plugin is off.
 */
function isx_deactivate() {
	wp_clear_scheduled_hook( 'isx_scheduled_backup' );
}

/**
 * Sites coming from the standalone plugin: deactivating it cleared the
 * scheduled-backup event. Put it back from the saved schedule.
 */
add_action(
	'admin_init',
	function () {
		$schedule = (array) get_option( 'isx_schedule', array() );
		if ( ! empty( $schedule['enabled'] ) && ! wp_next_scheduled( 'isx_scheduled_backup' ) ) {
			$interval = in_array( $schedule['interval'] ?? '', array( 'daily', 'weekly', 'monthly' ), true ) ? $schedule['interval'] : 'weekly';
			wp_schedule_event( time() + HOUR_IN_SECONDS, $interval, 'isx_scheduled_backup' );
		}
	}
);

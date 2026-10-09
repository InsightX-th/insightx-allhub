<?php
/**
 * Loads Backup unless the separate InsightX Backup plugin is still active.
 *
 * That plugin, if active, has already loaded (insightx-backup sorts before
 * insightx-offload) and declared every ISX_* constant, class and function:
 * loading the bundled copy as well would be a fatal "cannot redeclare". So
 * skip it and ask for the old plugin to be removed — its backups, schedule
 * and settings carry straight over.
 *
 * @return bool Whether the bundled module was loaded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'ISX_VERSION' ) || class_exists( 'ISX_Admin', false ) ) {
	add_action(
		'admin_notices',
		function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-warning"><p><strong>InsightX Offload:</strong> '
				. esc_html__( 'Backup is now part of InsightX Offload (menu InsightX Offload → Backup). Deactivate and delete the separate InsightX Backup plugin — your backups, schedule and settings carry over.', 'insightx-offload' )
				. '</p></div>';
		}
	);
	return false;
}

require_once __DIR__ . '/backup.php';
return true;

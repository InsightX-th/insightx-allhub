<?php
/** Regression suite: php tests/migrate-checkpoints.php (no external services). */
require __DIR__ . '/bootstrap.php';

group( 'Durable migration checkpoints' );
t( 'Migration has a resumable transfer/rewrite/finalize worker', class_exists( 'ISXM_Migrate_Queue' ) );
if ( ! class_exists( 'ISXM_Migrate_Queue' ) ) {
    exit( 1 );
}
require __DIR__ . '/migrate-support.php';

$job = migration_fixture( 101, false );
migration_step();
$job = ISXM_Job::get( 'migrate' );
t( 'Already migrated path stops at 100 attachments', $job->processed === 100 && $job->migration['stage'] === 'rewrite' );
$result = migration_drain();
t( 'Remaining attachment completes exactly once', $result['done'] && ISXM_Job::get( 'migrate' )->processed === 101 );
migration_step();
t( 'Crash after final checkpoint cannot restart listing from page one', ISXM_Job::get( 'migrate' )->processed === 101 );

$job = migration_fixture( 101, false );
$GLOBALS['isxm_t']['on_option_write'] = function ( $key, $value ) {
    if ( $key === 'isxs_job_migrate' && $value['processed'] === 100 ) throw new RuntimeException( 'injected exact boundary crash' );
};
try { migration_step(); } catch ( RuntimeException $e ) {}
unset( $GLOBALS['isxm_t']['on_option_write'] );
t( 'Crash on the 100th item commits the rewrite boundary atomically', ISXM_Job::get( 'migrate' )->migration['stage'] === 'rewrite' );
migration_step();
t( 'Resume rewrites before accepting attachment 101', ISXM_Job::get( 'migrate' )->processed === 100 );

$job = migration_fixture( 3 );
$job->processed = 1; $job->cursor = 'run||1'; $job->save();
$result = migration_drain();
t( 'Legacy cursor skips previously committed items', $result['done'] && ISXM_Job::get( 'migrate' )->processed === 3 );

$job = migration_fixture();
migration_step( -1 );
t( 'Budget expires before first item without skipping it', ISXM_Job::get( 'migrate' )->processed === 0 );
ISXM_Job::set_signal( 'migrate', 'pause' );
migration_step();
t( 'Pause is observed before another attachment', ISXM_Job::get( 'migrate' )->processed === 0 );
ISXM_Job::clear_signal( 'migrate' );

$GLOBALS['isxm_t']['on_option_write'] = function ( $key, $value ) {
    if ( $key === 'isxs_job_migrate' && $value['processed'] === 1 ) throw new RuntimeException( 'injected termination' );
};
try { migration_step(); } catch ( RuntimeException $e ) {}
unset( $GLOBALS['isxm_t']['on_option_write'] );
t( 'Termination after an item leaves a durable count and next index', ISXM_Job::get( 'migrate' )->processed === 1 && ISXM_Job::get( 'migrate' )->migration['index'] === 1 );
migration_drain();
t( 'Resume neither re-counts nor skips attachments', ISXM_Job::get( 'migrate' )->processed === 3 );

$job = migration_fixture( 1 );
$local = ISXM_TEST_UPLOADS . '/m1.jpg';
wp_mkdir_p( dirname( $local ) ); file_put_contents( $local, 'staged image' );
$old = 'https://example.com/wp-content/uploads/m1.jpg';
$new = ( new ISXM_Offload() )->remote_url( 1, 'm1.jpg' );
$db = $GLOBALS['wpdb'];
$db->db->exec( 'INSERT INTO wp_posts(ID,post_content,guid) VALUES (1,' . $db->db->quote( $old ) . ',' . $db->db->quote( $old ) . ')' );
$serialized = serialize( [ 'image' => $old ] );
$db->db->exec( 'INSERT INTO wp_options(option_name,option_value) VALUES (\'widget_test\',' . $db->db->quote( $serialized ) . ')' );
$db->db->exec( 'INSERT INTO wp_options(option_name,option_value) VALUES (\'isxs_job_migrate\',' . $db->db->quote( $serialized ) . ')' );
migration_step();
t( 'Transfer checkpoint retains local file and pending URL map', file_exists( $local ) && ISXM_Job::get( 'migrate' )->migration['pairs'] );
$db->fail_read = true;
$result = migration_step();
t( 'SQL read failure keeps rewrite checkpoint and local file', is_wp_error( $result ) && file_exists( $local ) && ISXM_Job::get( 'migrate' )->migration['rewrite']['after'] === 0 );
$db->fail_read = false; $db->fail_write = true;
$result = migration_step();
t( 'SQL write failure does not advance or delete local file', is_wp_error( $result ) && file_exists( $local ) && ISXM_Job::get( 'migrate' )->migration['rewrite']['after'] === 0 );
$db->fail_write = false;
$GLOBALS['isxm_t']['on_option_write'] = function ( $key, $value ) {
    if ( $key === 'isxs_job_migrate' && ( $value['migration']['stage'] ?? '' ) === 'finalize' ) throw new RuntimeException( 'injected before delete' );
};
try { migration_drain(); } catch ( RuntimeException $e ) {}
unset( $GLOBALS['isxm_t']['on_option_write'] );
t( 'Crash before finalize retains local file', file_exists( $local ) && ISXM_Job::get( 'migrate' )->migration['stage'] === 'finalize' );
migration_drain();
t( 'Finalize deletes local only after content and GUID rewrites', ! file_exists( $local ) && $db->get_var( 'SELECT post_content FROM wp_posts WHERE ID=1' ) === $new && $db->get_var( 'SELECT guid FROM wp_posts WHERE ID=1' ) === $new );
t( 'Serialized options remain valid', unserialize( $db->get_var( "SELECT option_value FROM wp_options WHERE option_name='widget_test'" ) )['image'] === $new );
t( 'Internal checkpoints retain source URL map untouched', $db->get_var( "SELECT option_value FROM wp_options WHERE option_name='isxs_job_migrate'" ) === $serialized );

$job = migration_fixture( 1 );
file_put_contents( $local, 'staged image' );
$db = $GLOBALS['wpdb'];
$db->db->exec( 'INSERT INTO wp_posts(ID,post_content,guid) VALUES (1,\'unrelated\',' . $db->db->quote( $old ) . ')' );
migration_step(); $db->fail_guid = true;
$result = migration_drain();
t( 'Failed GUID UPDATE cannot be masked by cache invalidation', is_wp_error( $result ) && $result->get_error_code() === 'isxs_guid_write' && file_exists( $local ) );
$db->fail_guid = false;
migration_drain();
t( 'GUID retry completes before local deletion', ! file_exists( $local ) && $db->get_var( 'SELECT guid FROM wp_posts WHERE ID=1' ) === $new );
$GLOBALS['isxm_t']['purged_posts'] = [];
ISXM_DB_Rewriter::update_attachment_guid( 1, [ [ 'old' => $old, 'new' => $new ] ] );
t( 'GUID replay purges the stale post cache', $GLOBALS['isxm_t']['purged_posts'] === [ 1 ] );

$job = migration_fixture( 1 );
$thumb = ISXM_TEST_UPLOADS . '/m1-100x100.jpg'; file_put_contents( $thumb, 'last thumbnail copy' );
$info = ISXM_Offload::get_record( 1 ); $info['files'][] = 'm1-100x100.jpg'; update_post_meta( 1, ISXM_Offload::META_KEY, $info );
$original_http = $GLOBALS['isxm_t']['http_provider'];
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( $original_http ) {
    parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
    return ( $q['prefix'] ?? '' ) === 'm1-100x100.jpg' ? migration_listing( [] ) : $original_http( $url, $args );
};
migration_drain();
t( 'A missing destination thumbnail never loses its last local copy', file_exists( $thumb ) );
unlink( $thumb );

$job = migration_fixture( 1 );
$GLOBALS['wpdb']->fail_read = true;
$result = migration_step();
t( 'Failed source-to-attachment SQL lookup cannot silently skip a source page', is_wp_error( $result ) && ISXM_Job::get( 'migrate' )->processed === 0 );
$GLOBALS['wpdb']->fail_read = false;
migration_drain();
t( 'Source lookup retry counts the original page once', ISXM_Job::get( 'migrate' )->processed === 1 );

$job = migration_fixture( 1, false );
file_put_contents( $local, 'delivery-only local copy' );
migration_step();
$settings = get_option( 'isxs_settings' );
$settings['deliver_enabled'] = false;
$settings['persist_urls'] = true; // Enabling later does not retroactively rewrite the prior batch.
update_option( 'isxs_settings', $settings ); ISXM_Settings::flush_cache();
migration_drain();
t( 'Disabling delivery between phases preserves files not covered by a rewrite', file_exists( $local ) );
unlink( $local );

$job = migration_fixture( 2, false );
delete_post_meta( 1, ISXM_Offload::META_KEY );
$original_http = $GLOBALS['isxm_t']['http_provider'];
$GLOBALS['isxm_t']['http_provider'] = function ( $url, $args ) use ( $original_http ) {
    if ( strpos( $url, 'list-type' ) === false ) return [ 'response' => [ 'code' => 404 ], 'body' => 'missing source' ];
    return $original_http( $url, $args );
};
migration_drain();
$job = ISXM_Job::get( 'migrate' );
t( 'Missing source records one error and continues to next attachment', $job->processed === 2 && $job->error_count === 1 );

$job = migration_fixture( 1, false );
$searched_prefix = null;
$GLOBALS['isxm_t']['http_provider'] = function ( $url ) use ( &$searched_prefix ) {
    parse_str( parse_url( $url, PHP_URL_QUERY ), $q );
    $searched_prefix = $q['prefix'] ?? '';
    return migration_listing( [ 'm1.jpg' ] );
};
ISXM_Sync::primary_exists_on_destination( ISXM_Offload::get_record( 1 ) );
t( 'Destination verification narrows listing to the exact primary key', $searched_prefix === 'm1.jpg' );

group( 'Runner lease and dispatch' );
$GLOBALS['wpdb'] = new ISXM_Migration_Test_DB();
t( 'First worker acquires runner lease', isxm_call( 'ISXM_Background', 'lock' ) );
t( 'Second worker cannot acquire a live lease', ! isxm_call( 'ISXM_Background', 'claim', ISXM_Background::RUNNER_LOCK, 360 ) );
ISXM_Background::renew_runner_lock();
t( 'Renew retains ownership', ISXM_Background::runner_lock_held() );
$GLOBALS['wpdb']->db->exec( "UPDATE wp_options SET option_value='" . time() . "|replacement' WHERE option_name='isxs_bg_lock'" );
$lost = false;
try { ISXM_Background::renew_runner_lock(); } catch ( ISXM_Lease_Lost $e ) { $lost = true; }
t( 'Expired worker detects takeover before checkpoint', $lost );
isxm_call( 'ISXM_Background', 'unlock' );
t( 'Old worker cannot delete replacement lease', ISXM_Background::runner_lock_held() );
$GLOBALS['wpdb']->db->exec( "UPDATE wp_options SET option_value='1' WHERE option_name='isxs_bg_lock'" );
t( 'Expired legacy timestamp lease can be acquired', isxm_call( 'ISXM_Background', 'lock' ) );
isxm_call( 'ISXM_Background', 'unlock' );
t( 'Owner can release own lease', ! ISXM_Background::runner_lock_held() );
set_transient( ISXM_Background::LOOPBACK_TRANSIENT, 'yes' );
$GLOBALS['isxm_t']['http_provider'] = function () { return new WP_Error( 'transport', 'injected dispatch failure' ); };
t( 'Dispatch failure is returned and enables browser fallback', ! ISXM_Background::dispatch() && get_transient( ISXM_Background::LOOPBACK_TRANSIENT ) === 'no' );
$GLOBALS['isxm_t']['http_provider'] = function () { return new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ); };
t( 'Ping timeout (busy site) is cached for one healthcheck tick, not a day', ! ISXM_Background::loopback_available( true ) && $GLOBALS['isxm_t']['transient_ttl'][ ISXM_Background::LOOPBACK_TRANSIENT ] === ISXM_Background::CRON_INTERVAL );
$GLOBALS['isxm_t']['http_provider'] = function () { return [ 'response' => [ 'code' => 403 ], 'body' => '' ]; };
t( 'Ping refused by the host is still cached for a day', ! ISXM_Background::loopback_available( true ) && $GLOBALS['isxm_t']['transient_ttl'][ ISXM_Background::LOOPBACK_TRANSIENT ] === DAY_IN_SECONDS );

group( 'Background integration with durable migration' );
$job = migration_fixture( 3 );
// Give source its own connection, as required by production precheck.
$connections = get_option( 'isxs_connections' );
$connections['minio'] = $connections['custom']; $connections['minio']['bucket'] = 'source';
update_option( 'isxs_connections', $connections );
$settings = get_option( 'isxs_settings' ); $settings['source_provider'] = 'minio';
update_option( 'isxs_settings', $settings ); ISXM_Settings::flush_cache();
$job->activity['test'] = true; $job->save();
migration_step(); // Saved v0.2.13 batch enters the compatibility route.
ISXM_Background::run();
$finished = ISXM_Job::get( 'migrate' );
t( 'Production runner reloads checkpoint and does not double-count', $finished->state === ISXM_Job::STATE_DONE && $finished->processed === 3, $finished->message );
t( 'Production runner releases its lease', ! ISXM_Background::runner_lock_held() );
$last = get_option( ISXM_Job::MIGRATE_LAST_OPTION );
t( 'Completed migration totals outlive the pruned record', is_array( $last ) && $last['processed'] === 3 && $last['total'] === 3, json_encode( $last ) );

group( 'Healthcheck drives the run when loopback is unavailable' );
$job = migration_fixture( 3 );
$connections = get_option( 'isxs_connections' );
$connections['minio'] = $connections['custom']; $connections['minio']['bucket'] = 'source';
update_option( 'isxs_connections', $connections );
$settings = get_option( 'isxs_settings' ); $settings['source_provider'] = 'minio';
update_option( 'isxs_settings', $settings ); ISXM_Settings::flush_cache();
set_transient( ISXM_Background::LOOPBACK_TRANSIENT, 'no' );
t( 'Fresh run is not stalled (old healthcheck would have ignored it)', ! ISXM_Job::get( 'migrate' )->is_stalled() );
( new ISXM_Background() )->handle_healthcheck();
$after = ISXM_Job::get( 'migrate' );
t( 'Healthcheck processes the run itself with no tab open', $after->processed > 0, $after->state . ' ' . $after->message );
t( 'Healthcheck runner releases its lease', ! ISXM_Background::runner_lock_held() );

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );

<?php
/** php -d memory_limit=256M tests/migrate-scale.php
 * Full 523,886-key cursor/resume exercise + 523,886-row SQL rewrite.
 * Storage responses are generated, not uploaded to a live bucket.
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrate-support.php';

group( '523,886 source objects / 90,654 attachments' );
$started = microtime( true );
$total = 523886; $attachments = 90654; $six = $total - 5 * $attachments;
$combined = in_array( '--with-rewrite', $argv, true );
if ( $combined ) echo "  Permanent URL rewrite ENABLED for every transfer batch\n";
$GLOBALS['wpdb'] = new ISXM_Migration_Test_DB();
$db = $GLOBALS['wpdb'];
isxm_test_configure( [], [ 'source_provider' => 'custom', 'source_prefix' => '', 'source_use_year_month' => false,
    'persist_urls' => $combined, 'remove_local' => false ] );
$db->db->beginTransaction();
$insert = $db->db->prepare( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (?,'_wp_attached_file',?)" );
for ( $id = 1; $id <= $attachments; $id++ ) $insert->execute( [ $id, sprintf( 'scale%06d.jpg', $id ) ] );
$db->db->commit();
if ( $combined ) {
    $insert_post = $db->db->prepare( 'INSERT INTO wp_posts(ID,post_content,guid) VALUES (?,?,?)' );
    for ( $id = 1; $id <= $attachments; $id += 1000 ) {
        $url = 'https://example.com/wp-content/uploads/' . sprintf( 'scale%06d.jpg', $id );
        $insert_post->execute( [ $id, serialize( [ 'image' => $url ] ), $url ] );
    }
}
$GLOBALS['isxm_t']['meta_provider'] = function ( $id, $key ) {
    $filename = sprintf( 'scale%06d.jpg', $id );
    if ( $key === '_wp_attached_file' ) return $filename;
    if ( $key === ISXM_Offload::META_KEY ) return [ 'bucket' => 'media', 'endpoint' => 'https://s3.example.test', 'base_key' => '', 'files' => [ $filename ] ];
    return '';
};
$list_calls = 0; $verify_calls = 0; $injected_listing = false;
$GLOBALS['isxm_t']['http_provider'] = function ( $url ) use ( $total, $six, &$list_calls, &$verify_calls, &$injected_listing ) {
    parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $q );
    if ( ! empty( $q['prefix'] ) ) { $verify_calls++; return migration_listing( [ $q['prefix'] ] ); }
    $list_calls++;
    $after = (int) ( $q['continuation-token'] ?? 0 );
    if ( $after === 5000 && ! $injected_listing ) {
        $injected_listing = true;
        return [ 'response' => [ 'code' => 403 ], 'body' => 'injected listing outage' ];
    }
    $end = min( $after + 200, $total ); $keys = [];
    for ( $i = $after; $i < $end; $i++ ) {
        if ( $i < $six * 6 ) { $id = intdiv( $i, 6 ) + 1; $variant = $i % 6; }
        else { $j = $i - $six * 6; $id = $six + intdiv( $j, 5 ) + 1; $variant = $j % 5; }
        $keys[] = sprintf( 'scale%06d', $id ) . ( $variant ? '-' . ( $variant * 100 ) . 'x100' : '' ) . '.jpg';
    }
    return migration_listing( $keys, $end < $total ? (string) $end : '' );
};
$next_crash = 7000; $crashes = 0; $max_checkpoint = 0;
$GLOBALS['isxm_t']['on_option_write'] = function ( $key, $value ) use ( &$next_crash, &$crashes, &$max_checkpoint ) {
    if ( $key !== 'isxs_job_migrate' ) return;
    $max_checkpoint = max( $max_checkpoint, strlen( serialize( $value ) ) );
    if ( $value['processed'] >= $next_crash ) {
        $next_crash += 7000; $crashes++;
        throw new RuntimeException( 'injected worker termination after durable write' );
    }
};
ISXM_Job::start( 'migrate', $total, 'run|' );
$slices = 0; $listing_errors = 0; $last_count = 0; $monotonic = true;
do {
    try { $result = migration_step( ++$slices % 71 === 0 ? -1 : 15 ); }
    catch ( RuntimeException $e ) { if ( strpos( $e->getMessage(), 'injected' ) !== 0 ) throw $e; continue; }
    $job = ISXM_Job::get( 'migrate' );
    $monotonic = $monotonic && $job->processed >= $last_count && $job->processed <= $total;
    $last_count = $job->processed;
    if ( is_wp_error( $result ) ) { $listing_errors++; continue; }
    if ( $slices % ( $combined ? 50000 : 2000 ) === 0 ) echo '  progress ' . $job->processed . '/' . $total . "\n";
    if ( $slices > ( $combined ? 1500000 : 20000 ) ) throw new RuntimeException( 'Run did not settle within expected slices.' );
} while ( is_wp_error( $result ) || ! $result['done'] );
t( 'All 523,886 object units committed exactly', $job->processed === $total && $monotonic );
t( 'Repeated worker terminations resumed', $crashes >= 70 );
t( 'Listing outage retried without losing committed work', $listing_errors === 1 );
t( 'Page checkpoints stay below 256 KiB', $max_checkpoint < 262144, (string) $max_checkpoint );
t( 'Verification is one narrow request per encountered attachment', $verify_calls < $attachments + 3000 );
t( 'No attachment errors', $job->error_count === 0 );
if ( $combined ) {
    t( 'Combined migration rewrites all 91 seeded posts and GUIDs',
        (int) $db->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%s3.example.test%' AND guid LIKE '%s3.example.test%'" ) === 91 );
}
printf( "  Transfer simulation: %.2fs; %d slices; %d list calls; %d verifications; %d injected terminations; checkpoint max %d bytes\n",
    microtime( true ) - $started, $slices, $list_calls, $verify_calls, $crashes, $max_checkpoint );

group( '523,886 database rows with resumable URL rewrite' );
$rewrite_started = microtime( true );
$GLOBALS['wpdb'] = new ISXM_Migration_Test_DB(); $db = $GLOBALS['wpdb'];
$db->db->beginTransaction();
$insert = $db->db->prepare( 'INSERT INTO wp_posts(ID,post_content) VALUES (?,?)' );
$old = 'https://source.test/image.jpg'; $new = 'https://destination.test/image.jpg';
for ( $i = 1; $i <= $total; $i++ ) {
    $content = $i % 10000 === 0 ? serialize( [ 'url' => $old ] ) : 'unrelated content';
    $insert->execute( [ $i, $content ] );
}
$db->db->commit();
$cursor = [ 'table' => 0, 'after' => 0 ]; $windows = 0; $replays = 0;
do {
    $result = ISXM_DB_Rewriter::replace_urls_window( [ [ 'old' => $old, 'new' => $new ] ], $cursor );
    if ( is_wp_error( $result ) ) throw new RuntimeException( $result->get_error_message() );
    $windows++;
    // Replay a successfully written window as if the worker died before
    // saving its cursor. The UPDATE must remain idempotent.
    if ( $windows % 103 === 0 ) {
        $replays++;
        $result = ISXM_DB_Rewriter::replace_urls_window( [ [ 'old' => $old, 'new' => $new ] ], $cursor );
    }
    $cursor = $result['cursor'];
} while ( ! $result['done'] );
$changed = (int) $db->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%destination.test%'" );
$old_left = (int) $db->get_var( "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%source.test%'" );
t( 'All matching large-table URLs replaced', $changed === 52 && $old_left === 0 );
t( 'Each SELECT returns at most 200 rows', $db->max_rows <= 200 );
t( 'Rewrite resumes through 2,622 bounded windows', $windows === 2622 );
t( 'Replaying windows preserves serialized data', unserialize( $db->get_var( 'SELECT post_content FROM wp_posts WHERE ID=10000' ) )['url'] === $new && $replays > 20 );
printf( "  SQL rewrite: %.2fs; %d windows; %d replayed windows; PHP peak %.1f MiB\n", microtime( true ) - $rewrite_started, $windows, $replays, memory_get_peak_usage( true ) / 1048576 );
echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );

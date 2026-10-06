<?php
/** php tests/media-library.php — the Media Library Storage / Bucket columns. */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrate-support.php';
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'selected' ) ) { function selected( $a, $b, $echo = true ) { return (string) $a === (string) $b ? ' selected="selected"' : ''; } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
require_once dirname( __DIR__ ) . '/includes/class-isxm-media-library.php';

function bucket_cell( $id ) {
    ob_start();
    ( new ISXM_Media_Library() )->render_column( 'isxs_bucket', $id );
    return ob_get_clean();
}
function record( $endpoint, $bucket ) {
    return [ 'bucket' => $bucket, 'endpoint' => $endpoint, 'base_key' => 'wp-content/uploads/2026/10/12345678/', 'files' => [ 'a.webp' ] ];
}

group( 'Bucket column' );
isxm_test_configure( [], [] );
$cols = ( new ISXM_Media_Library() )->add_column( [ 'title' => 'File' ] );
t( 'Bucket column sits right after Storage', array_keys( $cols ) === [ 'title', 'isxs_storage', 'isxs_bucket' ] );
$cases = [
    1 => [ 'https://c33ce09148913965fcd99e0bdaf526b2.r2.cloudflarestorage.com', 'sounddd-shop', 'Cloudflare R2 · sounddd-shop' ],
    2 => [ '', 'sounddd', 'Amazon S3 · sounddd' ],
    3 => [ 'https://s3.ap-southeast-1.amazonaws.com', 'sounddd', 'Amazon S3 · sounddd' ],
    4 => [ 'https://sgp1.digitaloceanspaces.com', 'space', 'DigitalOcean Spaces · space' ],
    5 => [ 'http://minio.local:9000', 'media', 'minio.local · media' ],
];
foreach ( $cases as $id => list( $endpoint, $bucket, $want ) ) {
    update_post_meta( $id, ISXM_Offload::META_KEY, record( $endpoint, $bucket ) );
    $cell = bucket_cell( $id );
    t( "Provider + bucket: $want", strpos( $cell, $want ) !== false, $cell );
}
t( 'Full key folder in the tooltip', strpos( bucket_cell( 1 ), 'title="sounddd-shop/wp-content/uploads/2026/10/12345678/"' ) !== false );
update_post_meta( 6, ISXM_Offload::META_KEY, record( 'https://evil.example', '<script>x</script>' ) );
t( 'Bucket name is escaped', strpos( bucket_cell( 6 ), '<script>' ) === false );
t( 'Not offloaded -> dash', strpos( bucket_cell( 99 ), '—' ) !== false );

group( 'Bucket filter' );
$GLOBALS['wpdb'] = new ISXM_Migration_Test_DB(); $db = $GLOBALS['wpdb'];
$db->db->exec( 'DROP TABLE wp_posts' );
$db->db->exec( "CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_content TEXT, guid TEXT, post_type TEXT DEFAULT 'attachment', post_status TEXT DEFAULT 'inherit')" );
isxm_test_configure( [], [] );
isxm_items_table( $db );
update_option( ISXM_Items::BACKFILLED_OPTION, '1' );
$r2 = 'https://abc.r2.cloudflarestorage.com';
for ( $i = 1; $i <= 6; $i++ ) {
    $db->db->exec( "INSERT INTO wp_posts(ID) VALUES ($i)" );
    ISXM_Items::put( $i, record( $i <= 4 ? $r2 : 'https://s3.ap-southeast-1.amazonaws.com', $i <= 4 ? 'sounddd-shop' : 'sounddd' ) );
}
$db->db->exec( "UPDATE wp_posts SET post_status='trash' WHERE ID=6" );
$opts = ISXM_Media_Library::bucket_options();
$first = reset( $opts );
t( 'Options: one per endpoint+bucket, live items only, busiest first', count( $opts ) === 2 && $first['provider'] === 'Cloudflare R2' && $first['count'] === 4 && end( $opts )['count'] === 1, json_encode( $opts ) );
ob_start(); ( new ISXM_Media_Library() )->render_filter( 'attachment' ); $html = ob_get_clean();
t( 'Dropdown renders "provider · bucket (count)"', strpos( $html, 'Cloudflare R2 · sounddd-shop (4)' ) !== false && strpos( $html, 'Amazon S3 · sounddd (1)' ) !== false, $html );

$GLOBALS['pagenow'] = 'upload.php';
$GLOBALS['isxm_is_admin'] = true;
$query = new class { public function is_main_query() { return true; } public function get( $k ) { return $k === 'post_type' ? 'attachment' : ''; } };
$run = function () use ( $db, $query ) {
    $c = ( new ISXM_Media_Library() )->filter_clauses( [ 'join' => '', 'where' => " AND wp_posts.post_type = 'attachment' AND wp_posts.post_status <> 'trash' " ], $query );
    return array_map( 'intval', $db->db->query( "SELECT wp_posts.ID FROM wp_posts {$c['join']} WHERE 1=1 {$c['where']} ORDER BY wp_posts.ID" )->fetchAll( PDO::FETCH_COLUMN ) );
};
$_GET = [ 'isxs_bucket' => array_keys( $opts )[0] ];
t( 'Filtering by the R2 bucket lists only its items', $run() === [ 1, 2, 3, 4 ], json_encode( $run() ) );
$_GET = [ 'isxs_bucket' => array_keys( $opts )[1] ];
t( 'Filtering by the S3 bucket lists only its items', $run() === [ 5 ], json_encode( $run() ) );
$_GET = [ 'isxs_bucket' => "x' OR 1=1 --" ];
t( 'A value that is not an option is ignored (no filter, no SQL)', count( $run() ) === 5 );
$_GET = [];

group( 'User profile picture filter' );
$GLOBALS['wpdb'] = new ISXM_Migration_Test_DB(); $db = $GLOBALS['wpdb'];
$db->db->exec( 'DROP TABLE wp_posts' );
$db->db->exec( "CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_content TEXT, guid TEXT, post_type TEXT DEFAULT 'attachment', post_status TEXT DEFAULT 'inherit')" );
isxm_test_configure( [], [] );
for ( $i = 1; $i <= 6; $i++ ) $db->db->exec( "INSERT INTO wp_posts(ID) VALUES ($i)" );
// 1: Nextend Social Login (user meta + attachment marker), 2: WP User Avatar (user meta only),
// 3: attachment marker only, 4: Simple Local Avatars, 5-6: product images.
$db->db->exec( "INSERT INTO wp_usermeta(user_id,meta_key,meta_value) VALUES (10,'wp_user_avatar','1'),(11,'wp_user_avatar','2'),(13,'simple_local_avatar'," . $db->db->quote( serialize( [ 'media_id' => 4, 'full' => 'x' ] ) ) . ")" );
$db->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES (1,'_wp_attachment_wp_user_avatar','10'),(3,'_wp_attachment_wp_user_avatar','12')" );
$GLOBALS['isxm_is_admin'] = true; $GLOBALS['pagenow'] = 'upload.php';
$run = function ( array $get ) use ( $db, $query ) {
    $_GET = $get;
    $c = ( new ISXM_Media_Library() )->filter_clauses( [ 'join' => '', 'where' => " AND wp_posts.post_type = 'attachment' AND wp_posts.post_status <> 'trash' " ], $query );
    $_GET = [];
    return array_map( 'intval', $db->db->query( "SELECT wp_posts.ID FROM wp_posts {$c['join']} WHERE 1=1 {$c['where']} ORDER BY wp_posts.ID" )->fetchAll( PDO::FETCH_COLUMN ) );
};
t( 'Profile pictures from every supported plugin', $run( [ 'isxs_avatar' => 'only' ] ) === [ 1, 2, 3, 4 ], json_encode( $run( [ 'isxs_avatar' => 'only' ] ) ) );
t( '"Without" leaves only site images', $run( [ 'isxs_avatar' => 'exclude' ] ) === [ 5, 6 ] );
t( 'Unknown value -> no filter', count( $run( [ 'isxs_avatar' => "'; DROP" ] ) ) === 6 );
t( 'Diagnostic count', ISXM_Media_Library::count_avatars() === 4 );
ob_start(); ( new ISXM_Media_Library() )->render_filter( 'attachment' ); $html = ob_get_clean();
t( 'Dropdown offered', strpos( $html, 'name="isxs_avatar"' ) !== false && strpos( $html, 'User profile pictures' ) !== false );

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );

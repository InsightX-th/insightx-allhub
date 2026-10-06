<?php
/** php tests/media-library.php — the Media Library Storage / Bucket columns. */
require __DIR__ . '/bootstrap.php';
if ( ! function_exists( 'wp_parse_url' ) ) { function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); } }
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

echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit( $GLOBALS['isxm_fail'] ? 1 : 0 );

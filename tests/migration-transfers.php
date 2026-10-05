<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/migrate-support.php';
$port = 19000 + getmypid() % 1000;
$proc = proc_open( [ 'python3', __DIR__ . '/parallel-s3.py', (string) $port ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'file', '/dev/null', 'w' ], 2 => [ 'file', '/dev/null', 'w' ] ], $pipes );
$dir = sys_get_temp_dir() . '/isxm-parallel-' . getmypid() . '/'; mkdir( $dir );
register_shutdown_function( function () use ( $proc, $dir ) { proc_terminate( $proc ); foreach ( glob( $dir . '*' ) ?: [] as $f ) @unlink( $f ); @rmdir( $dir ); } );
for ( $i=0; $i<50; $i++ ) { $sock = @fsockopen( '127.0.0.1', $port ); if ( $sock ) { fclose( $sock ); break; } usleep( 100000 ); }
if ( ! $sock ) throw new RuntimeException( 'Threaded fixture could not bind localhost.' );
isxm_test_configure( [ 'endpoint' => 'http://127.0.0.1:' . $port ], [ 'use_prefix' => false, 'use_year_month' => false, 'persist_urls' => false ] );
$client = new ISXM_Client();
$files = [];
for ( $i=0; $i<8; $i++ ) { $path = $dir . 'f' . $i; file_put_contents( $path, str_repeat( 'stream', 10000 ) ); $files[$i] = [ 'key' => 'f'.$i, 'path' => $path, 'mime' => 'image/jpeg', 'acl' => false ]; }
group( 'Bounded real network transfers' );
$start = microtime(true); $serial = $client->transfer_many( 'PUT', $files, 1 ); $serial_time = microtime(true)-$start;
$start = microtime(true); $parallel = $client->transfer_many( 'PUT', $files, 4, $dir ); $parallel_time = microtime(true)-$start;
t( 'Both sequential and parallel streamed uploads succeed', count( array_filter( $parallel, 'is_wp_error' ) ) === 0 && count( array_filter( $serial, 'is_wp_error' ) ) === 0 );
$stats = json_decode( file_get_contents( 'http://127.0.0.1:' . $port . '/__stats' ), true );
t( 'Observed exactly four active transfers', $stats['peak'] === 4 );
t( 'Every request has a valid SigV4 signature and payload hash', $stats['invalid'] === 0 );
t( 'Parallel beats sequential under fixture latency', $parallel_time < $serial_time * .7, sprintf('serial %.2fs parallel %.2fs', $serial_time, $parallel_time) );
$before = $stats['calls']; $client->transfer_many( 'PUT', $files, 4, $dir );
$stats = json_decode( file_get_contents( 'http://127.0.0.1:' . $port . '/__stats' ), true );
t( 'Durable receipts avoid replaying successful uploads', $stats['calls'] === $before );
$downloads = [];
foreach ( [ 'ok', 'retry', 'fail', 'truncated' ] as $name ) $downloads[$name] = [ 'key' => $name, 'path' => $dir.$name ];
$r = $client->transfer_many( 'GET', $downloads, 4 );
t( 'Successful downloads publish complete files', $r['ok'] === true && file_get_contents($dir.'ok') === 'object:/media/ok' );
t( 'Transient failure retries successfully', $r['retry'] === true );
t( 'Permission failure and truncation retain no partial destination', is_wp_error($r['fail']) && is_wp_error($r['truncated']) && !is_file($dir.'fail') && !is_file($dir.'truncated') );
$stats = json_decode( file_get_contents( 'http://127.0.0.1:' . $port . '/__stats' ), true );
t( 'Retries only failed requests, never permission failures', $stats['calls']['GET /media/retry'] === 2 && $stats['calls']['GET /media/ok'] === 1 && $stats['calls']['GET /media/fail'] === 1 );
ISXM_Client::set_deadline(microtime(true)-1); $r=$client->transfer_many('GET',$downloads,4); ISXM_Client::set_deadline(0);
t( 'Deadline yields without starting more network work', $r['ok']->get_error_code() === 'isxs_transfer_yield' );
// A failed derivative must not become a successful record merely because it existed in a prior record.
$paths=['primary.jpg'=>$dir.'f0','fail.jpg'=>$dir.'f1'];
isxm_test_add_post(9,[]); update_post_meta(9,'_wp_attached_file','primary.jpg');
$r=(new ISXM_Offload())->offload_attachment(9,['sizes'=>['small'=>['file'=>'fail.jpg']]],true,'migrate',$paths,$dir);
t( 'Derivative PUT failure fails attachment before metadata commit', is_wp_error($r) && ISXM_Offload::get_record(9) === null );
echo "\n{$GLOBALS['isxm_pass']} passed; {$GLOBALS['isxm_fail']} failed\n";
exit($GLOBALS['isxm_fail'] ? 1 : 0);

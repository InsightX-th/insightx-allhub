<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Client — Minimal S3-compatible REST client (AWS Signature V4).
 *
 * Plain PHP + wp_remote_request, no SDK. Works with Amazon S3, Minio,
 * Cloudflare R2, DigitalOcean Spaces and any SigV4-compatible endpoint.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Client {

    private $endpoint;   // host without scheme, e.g. minio.example.com or s3.ap-southeast-1.amazonaws.com
    private $scheme;     // http|https used to talk to the API
    private $region;
    private $bucket;
    private $access_key;
    private $secret_key;
    private $path_style;

    /**
     * Shared cURL handle for streamed uploads.
     *
     * Uploads used to curl_init()/curl_close() per file, so every object
     * paid a fresh TCP and TLS handshake. One attachment is the original
     * plus its generated sizes — commonly eight objects — so a bulk run
     * spent most of its time handshaking rather than transferring, and the
     * cost scaled with how far away the endpoint is.
     *
     * curl_easy_reset() clears the options but explicitly keeps the handle's
     * live connections, DNS cache and TLS session cache, so reusing one
     * handle lets every file after the first ride the connection the
     * previous one opened. Static (not per-instance) because callers create
     * a client per attachment; the handle has to outlive them to be worth
     * anything.
     *
     * @var resource|\CurlHandle|null
     */
    private static $curl_handle = null;

    /**
     * Wall-clock time after which retry backoff is abandoned, or 0.0 for no
     * limit. See set_deadline().
     *
     * @var float
     */
    private static $deadline = 0.0;

    public function __construct( array $args = [] ) {
        $s = wp_parse_args( $args, ISXM_Settings::all() );

        $this->region     = $s['region'] !== '' ? $s['region'] : 'us-east-1';
        $this->bucket     = $s['bucket'];
        $this->access_key = $s['access_key'];
        $this->secret_key = $s['secret_key'];
        $this->path_style = ! empty( $s['path_style'] );

        if ( $s['endpoint'] !== '' ) {
            $parsed         = wp_parse_url( $s['endpoint'] );
            // Never anything but http(s), whatever older saved settings hold.
            $this->scheme   = ( ! empty( $parsed['scheme'] ) && strtolower( $parsed['scheme'] ) === 'http' ) ? 'http' : 'https';
            $this->endpoint = ! empty( $parsed['host'] ) ? $parsed['host'] : preg_replace( '#^https?://#', '', untrailingslashit( $s['endpoint'] ) );
            if ( ! empty( $parsed['port'] ) ) {
                $this->endpoint .= ':' . $parsed['port'];
            }
        } else {
            $this->scheme   = 'https';
            $this->endpoint = 's3.' . $this->region . '.amazonaws.com';
            $this->path_style = false;
        }
    }

    /**
     * Bound how long retry backoff may keep the caller waiting.
     *
     * A retryable failure sleeps 1s then 3s between attempts. Inside a
     * time-budgeted batch that is 4 seconds of a 15-second budget spent
     * asleep on ONE file, so an endpoint returning 503s could burn a whole
     * batch on three or four items while still paying the full cost of
     * dispatching the next runner. Past the deadline the remaining attempts
     * are abandoned and the error is returned: the batch ends, the cursor is
     * saved, and the next batch retries the same item with a full budget.
     *
     * Set by ISXM_Tools::execute_batch() around a batch and cleared after.
     *
     * @param float $deadline microtime(true) value, or 0.0 for no limit.
     */
    public static function set_deadline( $deadline ) {
        self::$deadline = (float) $deadline;
    }

    /**
     * Whether sleeping this long would run past the batch deadline.
     *
     * @param int $seconds Backoff about to be slept.
     * @return bool
     */
    private static function may_wait( $seconds ) {
        if ( self::$deadline <= 0 ) {
            return true;
        }
        return ( microtime( true ) + $seconds ) < self::$deadline;
    }

    /**
     * Upload a local file to the bucket.
     *
     * @param string $key          Object key.
     * @param string $file_path    Absolute path of the local file.
     * @param string $content_type MIME type.
     * @param bool   $public_acl   Send x-amz-acl: public-read.
     * @return true|WP_Error
     */
    /**
     * Bounded, streamed requests; only this attachment's owner commits metadata.
     *
     * @param string $tool Job whose pause/cancel signal stops new transfers;
     *                     '' when no job owns the call (an upload request).
     */
    public function transfer_many( $method, array $files, $concurrency = null, $receipt_dir = '', $tool = 'migrate' ) {
        $limit = max( 1, min( 16, (int) ( $concurrency ?? apply_filters( 'isxm_migrate_concurrency', 4 ) ) ) );
        $results = []; $receipts = [];
        if ( $method === 'PUT' ) {
            foreach ( $files as $id => $file ) {
                // A file may name its own receipt dir: one batch can then
                // carry several attachments, each with its own staging dir.
                $dir = $file['receipt_dir'] ?? $receipt_dir;
                if ( $dir === '' ) continue;
                $fingerprint = hash( 'sha256', $this->endpoint . '|' . $this->bucket . '|' . $file['key'] . '|' . hash_file( 'sha256', $file['path'] ) );
                $receipts[ $id ] = $dir . $fingerprint . '.uploaded';
                if ( is_file( $receipts[ $id ] ) ) { $results[ $id ] = true; unset( $files[ $id ] ); }
            }
        }
        $queue = array_keys( $files ); $attempts = [];
        if ( ! function_exists( 'curl_multi_init' ) || $limit === 1 ) {
            foreach ( $files as $id => $file ) {
                if ( ! self::may_wait( 0 ) || ( $tool !== '' && ISXM_Job::signal( $tool ) !== '' ) ) { $results[ $id ] = new WP_Error( 'isxs_transfer_yield', 'Transfer checkpoint retained.' ); continue; }
                $results[ $id ] = $method === 'GET' ? $this->get_object_to_file( $file['key'], $file['path'] ) : $this->put_object( $file['key'], $file['path'], $file['mime'], $file['acl'] );
            }
            self::save_receipts( $results, $receipts );
            return $results;
        }
        $multi = curl_multi_init(); $active = []; $renewed = 0;
        try {
            while ( $queue || $active ) {
                // The scheduler ticks every 0.2 s; one lease write per tick
                // was a DB UPDATE five times a second for the whole run.
                if ( microtime( true ) - $renewed >= 5 ) {
                    ISXM_Background::renew_runner_lock();
                    $renewed = microtime( true );
                }
                while ( $queue && count( $active ) < $limit ) {
                    $id = array_shift( $queue ); $f = $files[ $id ];
                    if ( ! self::may_wait( 0 ) || ( $tool !== '' && ISXM_Job::signal( $tool ) !== '' ) ) { $results[ $id ] = new WP_Error( 'isxs_transfer_yield', 'Transfer checkpoint retained.' ); continue; }
                    $attempts[ $id ] = ( $attempts[ $id ] ?? 0 ) + 1;
                    $headers = [];
                    if ( $method === 'PUT' ) {
                        $headers['content-type'] = $f['mime'];
                        if ( $f['acl'] ) $headers['x-amz-acl'] = 'public-read';
                    }
                    $spec = $this->request( $method, $f['key'], [ 'prepare_only' => true, 'body_file' => $method === 'PUT' ? $f['path'] : '', 'headers' => $headers ] );
                    if ( is_wp_error( $spec ) ) { $results[ $id ] = $spec; continue; }
                    $tmp = $method === 'GET' ? $f['path'] . '.part-' . bin2hex( random_bytes( 6 ) ) : '';
                    $fp = @fopen( $method === 'GET' ? $tmp : $f['path'], $method === 'GET' ? 'wb' : 'rb' );
                    if ( ! $fp ) { $results[ $id ] = new WP_Error( 'isxs_stream_open', 'Could not open migration stream.' ); continue; }
                    $ch = curl_init(); $lines = [];
                    foreach ( $spec['headers'] as $k => $v ) $lines[] = $k . ': ' . $v;
                    $lines[] = 'Expect:'; // same 100-continue stall put_object() avoids
                    $verify = apply_filters( 'https_ssl_verify', true, $spec['url'] );
                    $options = [ CURLOPT_URL => $spec['url'], CURLOPT_CUSTOMREQUEST => $method,
                        CURLOPT_HTTPHEADER => $lines, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 300,
                        CURLOPT_SSL_VERIFYPEER => (bool) $verify, CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false ];
                    $ca = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
                    if ( is_file( $ca ) ) $options[ CURLOPT_CAINFO ] = $ca;
                    if ( $method === 'GET' ) $options[ CURLOPT_FILE ] = $fp;
                    else {
                        $options[ CURLOPT_UPLOAD ] = true; $options[ CURLOPT_INFILE ] = $fp;
                        $options[ CURLOPT_INFILESIZE ] = filesize( $f['path'] );
                        // Do not buffer an unbounded error response from the provider.
                        $options[ CURLOPT_WRITEFUNCTION ] = function ( $handle, $data ) { return strlen( $data ); };
                    }
                    curl_setopt_array( $ch, $options ); curl_multi_add_handle( $multi, $ch );
                    $active[ (int) ( is_object( $ch ) ? spl_object_id( $ch ) : $ch ) ] = [ 'ch' => $ch, 'fp' => $fp, 'tmp' => $tmp, 'id' => $id ];
                }
                do { $status = curl_multi_exec( $multi, $running ); } while ( $status === CURLM_CALL_MULTI_PERFORM );
                if ( $status !== CURLM_OK ) throw new RuntimeException( 'Migration network scheduler failed.' );
                while ( $message = curl_multi_info_read( $multi ) ) {
                    $ch = $message['handle']; $handle_id = (int) ( is_object( $ch ) ? spl_object_id( $ch ) : $ch );
                    $a = $active[ $handle_id ]; $id = $a['id']; $code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
                    fclose( $a['fp'] );
                    $ok = $message['result'] === CURLE_OK && $code === 200;
                    if ( $method === 'GET' ) {
                        if ( $ok ) $ok = @rename( $a['tmp'], $files[ $id ]['path'] );
                        if ( is_file( $a['tmp'] ) ) @unlink( $a['tmp'] );
                    }
                    $retry = $message['result'] !== CURLE_OK || in_array( $code, [ 408, 429, 500, 502, 503, 504 ], true );
                    curl_multi_remove_handle( $multi, $ch ); self::close_curl( $ch ); unset( $active[ $handle_id ] );
                    if ( ! $ok && $retry && $attempts[ $id ] < 3 && self::may_wait( 0 ) ) $queue[] = $id;
                    else {
                        // Keep the status in the code: Remove/Download tell a
                        // missing object (isxs_http_404) from a real failure.
                        $results[ $id ] = $ok ? true : new WP_Error( $code ? 'isxs_http_' . $code : 'isxs_transfer_failed', 'Storage transfer failed (HTTP ' . $code . ').' );
                        self::save_receipts( [ $id => $results[ $id ] ], $receipts );
                    }
                }
                if ( $active && curl_multi_select( $multi, 0.2 ) === -1 ) usleep( 10000 );
            }
        } finally {
            foreach ( $active as $a ) {
                curl_multi_remove_handle( $multi, $a['ch'] ); self::close_curl( $a['ch'] ); fclose( $a['fp'] );
                if ( $a['tmp'] !== '' && is_file( $a['tmp'] ) ) @unlink( $a['tmp'] );
            }
            curl_multi_close( $multi );
        }
        return $results;
    }

    /** curl_close() is a no-op since PHP 8.0 and deprecated in 8.5; handles free on unset. */
    private static function close_curl( $ch ) {
        if ( PHP_VERSION_ID < 80000 ) {
            curl_close( $ch );
        }
    }

    private static function save_receipts( array $results, array $receipts ) {
        foreach ( $results as $id => $result ) {
            if ( $result === true && isset( $receipts[ $id ] ) && file_put_contents( $receipts[ $id ], 'uploaded', LOCK_EX ) === false ) {
                throw new RuntimeException( 'Could not persist upload receipt; checkpoint retained.' );
            }
        }
    }

    public function put_object( $key, $file_path, $content_type = 'application/octet-stream', $public_acl = false ) {
        if ( ! file_exists( $file_path ) ) {
            // basename only — these messages surface in the admin UI and the
            // `_isxs_offload_error` meta, never leak server paths.
            return new WP_Error( 'isxs_missing_file', sprintf( 'Local file not found: %s', wp_basename( $file_path ) ) );
        }

        $headers = [ 'content-type' => $content_type ];
        if ( $public_acl ) {
            $headers['x-amz-acl'] = 'public-read';
        }

        // 'body_file' (not 'body') so the file is streamed from disk instead
        // of read into memory — a large video/zip used to exhaust
        // memory_limit here, and that's a FATAL, which killed the whole
        // bulk batch rather than failing this one attachment.
        $response = $this->request_with_retry( 'PUT', $key, [
            'body_file' => $file_path,
            'headers'   => $headers,
            'timeout'   => 300,
        ] );
        return $this->expect_status( $response, [ 200 ] );
    }

    /**
     * Set a canned ACL on an existing object (PUT Object ACL subresource).
     *
     * @param string $key Object key.
     * @param string $acl Canned ACL value, e.g. 'private' or 'public-read'.
     * @return true|WP_Error
     */
    public function set_object_acl( $key, $acl = 'private' ) {
        $response = $this->request( 'PUT', $key, [
            'query'   => [ 'acl' => '' ],
            'headers' => [ 'x-amz-acl' => $acl ],
        ] );
        return $this->expect_status( $response, [ 200 ] );
    }

    /**
     * Delete an object. Missing objects are treated as success (204/404).
     *
     * @param string $key Object key.
     * @return true|WP_Error
     */
    public function delete_object( $key ) {
        $response = $this->request( 'DELETE', $key );
        return $this->expect_status( $response, [ 200, 204, 404 ] );
    }

    /**
     * Delete many objects with S3 DeleteObjects (up to 1,000 per request)
     * instead of one DELETE each. Stores that don't implement it fall back
     * to single deletes. Missing objects count as deleted, as in delete_object().
     *
     * @param string[] $keys
     * @return array<string,true|WP_Error> Outcome per key.
     */
    public function delete_objects( array $keys ) {
        $results = [];
        foreach ( array_chunk( array_values( array_unique( $keys ) ), 1000 ) as $chunk ) {
            if ( count( $chunk ) === 1 ) {
                $results[ $chunk[0] ] = $this->delete_object( $chunk[0] );
                continue;
            }
            $xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
            foreach ( $chunk as $key ) {
                $xml .= '<Object><Key>' . htmlspecialchars( $key, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</Key></Object>';
            }
            $xml .= '</Delete>';
            $response = $this->request_with_retry( 'POST', '', [
                'query'   => [ 'delete' => '' ],
                'body'    => $xml,
                // S3 requires an integrity header on DeleteObjects.
                'headers' => [ 'content-type' => 'application/xml', 'content-md5' => base64_encode( md5( $xml, true ) ) ],
            ] );
            if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                foreach ( $chunk as $key ) {
                    $results[ $key ] = $this->delete_object( $key );
                }
                continue;
            }
            foreach ( $chunk as $key ) {
                $results[ $key ] = true;
            }
            // Quiet mode lists only the keys that failed.
            if ( preg_match_all( '#<Error>(.*?)</Error>#s', wp_remote_retrieve_body( $response ), $errors ) ) {
                foreach ( $errors[1] as $error ) {
                    $key  = preg_match( '#<Key>(.*?)</Key>#s', $error, $m ) ? html_entity_decode( $m[1], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) : '';
                    $code = preg_match( '#<Code>(.*?)</Code>#s', $error, $m ) ? $m[1] : 'Error';
                    if ( $key !== '' && $code !== 'NoSuchKey' ) {
                        $results[ $key ] = new WP_Error( 'isxs_delete_failed', sprintf( 'DELETE %s failed: %s', $key, $code ) );
                    }
                }
            }
        }
        return $results;
    }

    /**
     * Download an object straight to a local file, never holding the body in
     * memory — a multi-GB video or zip read into a string exhausts
     * memory_limit, and that is a FATAL, which kills the entire batch rather
     * than failing one attachment.
     *
     * Retries the failures worth retrying, the same way put_object() does:
     * Download, Remove and Migrate all read through here, so without it a
     * single dropped connection failed the item outright (and in Migrate's
     * case, the whole attachment).
     *
     * Writes to a temporary file next to the destination and renames on
     * success, so a failed or truncated transfer can never leave a partial
     * file where callers expect a complete one.
     *
     * @param string $key         Object key.
     * @param string $destination Absolute local path to write.
     * @return true|WP_Error
     */
    public function get_object_to_file( $key, $destination ) {
        $delays   = [ 1, 3 ];
        $attempts = 3;
        $result   = null;

        for ( $i = 0; $i < $attempts; $i++ ) {
            $result = $this->get_object_to_file_once( $key, $destination );
            if ( ! is_wp_error( $result ) || ! self::is_retryable_download( $result ) ) {
                return $result;
            }
            if ( $i < $attempts - 1 ) {
                $delay = isset( $delays[ $i ] ) ? $delays[ $i ] : end( $delays );
                if ( ! self::may_wait( $delay ) ) {
                    break;
                }
                sleep( $delay );
            }
        }

        return $result;
    }

    /**
     * One streamed download attempt. See get_object_to_file().
     *
     * @return true|WP_Error
     */
    private function get_object_to_file_once( $key, $destination ) {
        $temp = $destination . '.isxs-part';

        $response = $this->request( 'GET', $key, [
            'timeout'   => 300,
            'stream_to' => $temp,
        ] );

        if ( is_wp_error( $response ) ) {
            self::discard( $temp );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 200 ) {
            self::discard( $temp );
            return new WP_Error( 'isxs_http_' . $code, sprintf( 'GET %s returned HTTP %d', $key, $code ) );
        }

        // Same truncation guard get_object() applies: a connection dropped
        // mid-body still arrives with a 200 status line already sent, and a
        // short file must not pass as the complete one — Remove deletes the
        // remote copy once the local "backup" looks successful.
        $content_length = wp_remote_retrieve_header( $response, 'content-length' );
        $written        = @filesize( $temp );
        if ( $written === false ) {
            self::discard( $temp );
            return new WP_Error( 'isxs_write_failed', sprintf( __( 'Could not write file %s', 'insightx-offload' ), wp_basename( $destination ) ) );
        }
        if ( $content_length !== '' && (int) $content_length !== (int) $written ) {
            self::discard( $temp );
            return new WP_Error(
                'isxs_truncated_download',
                sprintf( __( 'Download of %s is incomplete (got %d of %d bytes — the connection may have dropped mid-download)', 'insightx-offload' ), $key, (int) $written, (int) $content_length )
            );
        }

        if ( ! @rename( $temp, $destination ) ) {
            self::discard( $temp );
            return new WP_Error( 'isxs_write_failed', sprintf( __( 'Could not write file %s', 'insightx-offload' ), wp_basename( $destination ) ) );
        }

        return true;
    }

    /**
     * Remove a partial download so a later attempt starts clean.
     */
    private static function discard( $path ) {
        if ( file_exists( $path ) ) {
            @unlink( $path );
        }
    }

    /**
     * Whether a failed download is worth another attempt. A 404 or a
     * permission failure answers the same way every time — repeating those
     * just burns the batch's time budget (and remove_remote_attachment()
     * specifically relies on 404 coming back promptly).
     *
     * @param WP_Error $error
     * @return bool
     */
    private static function is_retryable_download( $error ) {
        $code = $error->get_error_code();

        // A truncated body IS a dropped connection, just one that arrived
        // after the 200 status line — exactly the case retrying fixes.
        if ( $code === 'isxs_truncated_download' ) {
            return true;
        }
        if ( preg_match( '#^isxs_http_(\d+)$#', $code, $m ) ) {
            return in_array( (int) $m[1], [ 429, 500, 502, 503, 504 ], true );
        }
        // Anything else is a transport-level WP_Error (timeout, DNS, reset).
        return true;
    }

    /**
     * Test bucket connectivity + credentials (HEAD bucket).
     *
     * @return true|WP_Error
     */
    public function test_connection() {
        if ( $this->bucket === '' || $this->access_key === '' || $this->secret_key === '' ) {
            return new WP_Error( 'isxs_not_configured', __( 'Not fully configured — Bucket, Access Key and Secret Key are required', 'insightx-offload' ) );
        }

        $response = $this->request( 'HEAD', '' );
        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 200 ) return true;
        if ( $code === 404 ) return new WP_Error( 'isxs_no_bucket', sprintf( __( 'Bucket "%s" not found on this endpoint (HTTP 404)', 'insightx-offload' ), $this->bucket ) );
        if ( $code === 403 ) return new WP_Error( 'isxs_forbidden', __( 'Invalid Access Key/Secret Key, or no permission to access the bucket (HTTP 403)', 'insightx-offload' ) );
        if ( $code === 301 ) return new WP_Error( 'isxs_wrong_region', __( 'Region does not match the bucket (HTTP 301) — check the Region value', 'insightx-offload' ) );
        return new WP_Error( 'isxs_http_' . $code, sprintf( __( 'Connection failed (HTTP %d)', 'insightx-offload' ), $code ) );
    }

    /**
     * One page of ListObjectsV2 (bucket-root, up to 1000 keys). Parsed
     * with SimpleXML (built into PHP) so keys containing entities, CDATA
     * or attribute oddities decode correctly — see parse_xml().
     *
     * @param string $continuation_token Pass the previous page's next_token to continue.
     * @return array{count:int,next_token:string}|WP_Error
     */
    public function list_objects_page( $continuation_token = '', $prefix = '' ) {
        $query = [ 'list-type' => '2', 'max-keys' => '1000' ];
        if ( $prefix !== '' ) {
            $query['prefix'] = $prefix;
        }
        if ( $continuation_token !== '' ) {
            $query['continuation-token'] = $continuation_token;
        }

        $response = $this->request( 'GET', '', [ 'query' => $query, 'timeout' => 30 ] );
        $ok       = $this->expect_status( $response, [ 200 ] );
        if ( is_wp_error( $ok ) ) return $ok;

        $body = wp_remote_retrieve_body( $response );
        $xml  = self::parse_xml( $body );
        if ( $xml === false || $xml->getName() !== 'ListBucketResult' ) {
            return self::malformed_response_error();
        }
        $count      = count( $xml->Contents );
        $next_token = self::extract_next_token( $body );
        if ( is_wp_error( $next_token ) ) {
            return $next_token;
        }

        return [ 'count' => $count, 'next_token' => $next_token ];
    }

    /**
     * One page of ListObjectsV2 that returns the actual object keys, not
     * just a count — used by Migrate to enumerate what's really in the
     * source bucket (ground truth) instead of guessing keys that might not
     * exist, and by the Sync tool to verify what's really in the
     * destination bucket. Parsed with SimpleXML, same as list_objects_page().
     *
     * @param string $continuation_token Pass the previous page's next_token to continue.
     * @param int    $max_keys           Page size (S3 caps at 1000).
     * @param string $prefix             Only list keys starting with this prefix
     *                                   ('' = whole bucket). One prefixed page is
     *                                   how an attachment's own objects are
     *                                   checked without listing the whole bucket.
     * @return array{keys:string[],next_token:string,modified:array<string,int>}|WP_Error
     */
    public function list_objects_keys_page( $continuation_token = '', $max_keys = 1000, $prefix = '' ) {
        $query = [ 'list-type' => '2', 'max-keys' => (string) $max_keys ];
        if ( $prefix !== '' ) {
            $query['prefix'] = $prefix;
        }
        if ( $continuation_token !== '' ) {
            $query['continuation-token'] = $continuation_token;
        }

        $response = $this->request( 'GET', '', [ 'query' => $query, 'timeout' => 30 ] );
        $ok       = $this->expect_status( $response, [ 200 ] );
        if ( is_wp_error( $ok ) ) return $ok;

        $body = wp_remote_retrieve_body( $response );
        $xml  = self::parse_xml( $body );
        if ( $xml === false || $xml->getName() !== 'ListBucketResult' ) {
            return self::malformed_response_error();
        }
        // SimpleXML decodes entities itself, so a key like "a&amp;b.jpg"
        // comes back as the real "a&b.jpg" without a manual unescape step.
        $keys     = [];
        $modified = []; // key => unix time, when the listing says
        foreach ( $xml->Contents as $object ) {
            $key = (string) $object->Key;
            if ( $key !== '' ) {
                $keys[] = $key;
                $time   = strtotime( (string) $object->LastModified );
                if ( $time !== false ) {
                    $modified[ $key ] = $time;
                }
            }
        }

        $next_token = self::extract_next_token( $body );
        if ( is_wp_error( $next_token ) ) {
            return $next_token;
        }

        return [ 'keys' => $keys, 'next_token' => $next_token, 'modified' => $modified ];
    }

    /**
     * The immediate sub-folders of $prefix (ListObjectsV2 with
     * delimiter=/, read from CommonPrefixes) — what the Migrate folder
     * picker browses. Capped at $max_pages pages so a bucket with a flat
     * million-folder level can't hang the request.
     *
     * @param string $prefix    '' for the bucket root, else "folder/".
     * @param int    $max_pages Listing pages to follow (1000 entries each).
     * @return array{folders:string[],truncated:bool}|WP_Error Full prefixes, e.g. "wp-content/uploads/".
     */
    public function list_folders( $prefix = '', $max_pages = 5 ) {
        $folders = []; $token = '';
        for ( $page = 0; $page < $max_pages; $page++ ) {
            $query = [ 'list-type' => '2', 'delimiter' => '/', 'max-keys' => '1000' ];
            if ( $prefix !== '' ) $query['prefix'] = $prefix;
            if ( $token !== '' ) $query['continuation-token'] = $token;

            $response = $this->request( 'GET', '', [ 'query' => $query, 'timeout' => 30 ] );
            $ok       = $this->expect_status( $response, [ 200 ] );
            if ( is_wp_error( $ok ) ) return $ok;

            $body = wp_remote_retrieve_body( $response );
            $xml  = self::parse_xml( $body );
            if ( $xml === false || $xml->getName() !== 'ListBucketResult' ) {
                return self::malformed_response_error();
            }
            foreach ( $xml->CommonPrefixes as $common ) {
                $folder = (string) $common->Prefix;
                if ( $folder !== '' ) $folders[] = $folder;
            }
            $token = self::extract_next_token( $body );
            if ( is_wp_error( $token ) ) return $token;
            if ( $token === '' ) break;
        }
        return [ 'folders' => $folders, 'truncated' => $token !== '' ];
    }

    /**
     * Pull the pagination token out of a ListObjectsV2 response body.
     *
     * '' means "genuinely the last page". If the response claims it's
     * truncated but carries no usable NextContinuationToken (broken/partial
     * provider response), that's an ERROR — treating it as '' would make
     * callers believe the listing ended and silently drop every remaining
     * page, the same "quietly looks finished" failure class fixed elsewhere.
     *
     * @param string $body Raw XML response body.
     * @return string|WP_Error Unescaped token, '' when not truncated.
     */
    private static function extract_next_token( $body ) {
        $xml = self::parse_xml( $body );
        if ( $xml === false ) {
            return '';
        }
        if ( strtolower( trim( (string) $xml->IsTruncated ) ) !== 'true' ) {
            return '';
        }
        $token = trim( (string) $xml->NextContinuationToken );
        if ( $token === '' ) {
            return new WP_Error(
                'isxs_pagination_broken',
                __( 'The bucket reported another page of files but sent no continuation token — stopping here so the listing is not mistaken for complete', 'insightx-offload' )
            );
        }
        return $token;
    }

    /**
     * Parse an S3 XML response body with SimpleXML — the XML parser built
     * into PHP, so no dependency to install — with external-entity loading
     * disabled so a hostile body can't trigger XXE.
     *
     * Returns false on any parse failure (callers treat that as a
     * malformed response, the same way the old regex checks did) and when
     * SimpleXML itself is unavailable (rare host configs), so the plugin
     * degrades instead of fatalling.
     *
     * @param string $body Raw XML response body.
     * @return \SimpleXMLElement|false
     */
    private static function parse_xml( $body ) {
        if ( ! function_exists( 'simplexml_load_string' ) ) {
            return false;
        }

        $previous = libxml_use_internal_errors( true );
        // PHP < 8.0 loads external entities by default — disable them.
        // PHP 8.0+ keeps them disabled and the function is a no-op there
        // (that's why the call is guarded rather than relied upon).
        if ( function_exists( 'libxml_disable_entity_loader' ) ) {
            @libxml_disable_entity_loader( true );
        }
        $xml = simplexml_load_string( $body );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        return $xml;
    }

    /**
     * Total object count in the bucket (paginated List Objects V2), bounded
     * by a time budget rather than a fixed page cap so it degrades
     * gracefully on huge buckets instead of timing out the request — same
     * time-budget philosophy the bulk-tool batch runners already use.
     *
     * Resumable: pass the `next_token` from a previous incomplete response
     * back in as `$continuation_token` to keep counting from where it left
     * off. `total` is only the count accumulated *this call* — callers must
     * accumulate across calls themselves until `complete` is true, same
     * contract as `list_objects_keys_page()`.
     *
     * @param string $continuation_token Resume cursor from a previous incomplete call ('' to start).
     * @param int    $time_limit Seconds to keep paginating before giving up.
     * @return array{total:int,next_token:string,complete:bool}|WP_Error
     */
    public function count_objects( $continuation_token = '', $time_limit = 20, $prefix = '' ) {
        $started = microtime( true );
        $total   = 0;
        $token   = $continuation_token;
        do {
            $page = $this->list_objects_page( $token, $prefix );
            if ( is_wp_error( $page ) ) return $page;
            $total += $page['count'];
            $token  = $page['next_token'];
        } while ( $token !== '' && ( microtime( true ) - $started ) < $time_limit );

        return [ 'total' => $total, 'next_token' => $token, 'complete' => ( $token === '' ) ];
    }

    /* ---------------------------------------------------------------------
     * Internals — AWS Signature Version 4
     * ------------------------------------------------------------------ */

    /**
     * A time-limited GET URL for a private object (SigV4 query-string auth).
     *
     * Lets a private object be handed to one visitor — e.g. a paid
     * WooCommerce download — without making it public. The link stops
     * working after $expires seconds.
     *
     * @param string   $key     Object key.
     * @param int      $expires Lifetime in seconds (S3 caps it at 7 days).
     * @param int|null $now     Signing time (tests); current time when null.
     * @return string
     */
    public function presigned_url( $key, $expires = 300, $now = null ) {
        $now        = $now === null ? time() : (int) $now;
        $amz_date   = gmdate( 'Ymd\THis\Z', $now );
        $date_stamp = gmdate( 'Ymd', $now );
        $scope      = $date_stamp . '/' . $this->region . '/s3/aws4_request';

        $query = [
            'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential'    => $this->access_key . '/' . $scope,
            'X-Amz-Date'          => $amz_date,
            'X-Amz-Expires'       => (string) min( max( (int) $expires, 1 ), 604800 ),
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort( $query );
        $query_parts = [];
        foreach ( $query as $q_key => $q_value ) {
            $query_parts[] = rawurlencode( $q_key ) . '=' . rawurlencode( $q_value );
        }
        $canonical_query = implode( '&', $query_parts );
        $uri             = $this->canonical_uri( $key );

        $canonical_request = implode( "\n", [
            'GET',
            $uri,
            $canonical_query,
            'host:' . $this->host() . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ] );
        $string_to_sign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $scope,
            hash( 'sha256', $canonical_request ),
        ] );
        $signature = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $date_stamp ) );

        return $this->scheme . '://' . $this->host() . $uri . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
    }

    /**
     * SigV4 signing key for one day, shared by signed requests and
     * presigned URLs.
     *
     * @param string $date_stamp Ymd.
     * @return string Raw binary key.
     */
    private function signing_key( $date_stamp ) {
        $k_date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $this->secret_key, true );
        $k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
        $k_service = hash_hmac( 'sha256', 's3', $k_region, true );
        return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
    }

    /**
     * Host header value for the request.
     */
    private function host() {
        return $this->path_style ? $this->endpoint : $this->bucket . '.' . $this->endpoint;
    }

    /**
     * Canonical URI for the object key (each segment URI-encoded per SigV4).
     *
     * @param string $key Object key ('' means the bucket itself).
     */
    private function canonical_uri( $key ) {
        $path = $this->path_style ? '/' . $this->bucket : '';
        if ( $key !== '' ) {
            $segments = array_map( 'rawurlencode', explode( '/', $key ) );
            $path    .= '/' . implode( '/', $segments );
        }
        return $path === '' ? '/' : $path;
    }

    /**
     * Perform a signed request against the bucket.
     *
     * @param string $method HTTP method.
     * @param string $key    Object key ('' for bucket-level).
     * @param array  $args   { body?: string, body_file?: string, stream_to?: string,
     *                        headers?: array, timeout?: int, query?: array }
     *                       body_file streams a request body OUT of a file;
     *                       stream_to streams the response body IN to one.
     * @return array|WP_Error wp_remote_request()-shaped result.
     */
    private function request( $method, $key, array $args = [] ) {
        ISXM_Background::renew_runner_lock();
        $body      = isset( $args['body'] ) ? $args['body'] : '';
        $body_file = isset( $args['body_file'] ) ? $args['body_file'] : '';
        $stream_to = isset( $args['stream_to'] ) ? $args['stream_to'] : '';
        $headers   = isset( $args['headers'] ) ? $args['headers'] : [];
        $timeout   = isset( $args['timeout'] ) ? $args['timeout'] : 30;
        $query     = isset( $args['query'] ) ? $args['query'] : [];

        if ( $body_file !== '' && ! self::can_stream() ) {
            // No cURL — fall back to reading the file, but only when it
            // actually fits in memory. Returning a WP_Error keeps the batch
            // alive; letting PHP hit the memory limit would not.
            $fits = self::fits_in_memory( $body_file );
            if ( is_wp_error( $fits ) ) {
                return $fits;
            }
            $body = file_get_contents( $body_file );
            if ( $body === false ) {
                return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot read local file: %s', wp_basename( $body_file ) ) );
            }
            $body_file = '';
        }

        $amz_date   = gmdate( 'Ymd\THis\Z' );
        $date_stamp = gmdate( 'Ymd' );
        $payload_hash = $this->payload_hash( $body_file, $body );
        if ( $payload_hash === false ) {
            return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot read local file: %s', wp_basename( $body_file ) ) );
        }
        $uri = $this->canonical_uri( $key );

        // SigV4 canonical query string: sorted by key, each key/value
        // percent-encoded per RFC 3986 (rawurlencode matches, same as
        // canonical_uri() already relies on for path segments).
        ksort( $query );
        $query_parts = [];
        foreach ( $query as $q_key => $q_value ) {
            $query_parts[] = rawurlencode( $q_key ) . '=' . rawurlencode( $q_value );
        }
        $canonical_query = implode( '&', $query_parts );

        $sign_headers = array_merge( $headers, [
            'host'                 => $this->host(),
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date'           => $amz_date,
        ] );
        $sign_headers = array_change_key_case( $sign_headers, CASE_LOWER );
        ksort( $sign_headers );

        $canonical_headers = '';
        $signed_names      = [];
        foreach ( $sign_headers as $name => $value ) {
            $canonical_headers .= $name . ':' . trim( $value ) . "\n";
            $signed_names[]     = $name;
        }
        $signed_headers = implode( ';', $signed_names );

        $canonical_request = implode( "\n", [
            $method,
            $uri,
            $canonical_query,
            $canonical_headers,
            $signed_headers,
            $payload_hash,
        ] );

        $scope          = $date_stamp . '/' . $this->region . '/s3/aws4_request';
        $string_to_sign = implode( "\n", [
            'AWS4-HMAC-SHA256',
            $amz_date,
            $scope,
            hash( 'sha256', $canonical_request ),
        ] );

        $signature = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $date_stamp ) );

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->access_key,
            $scope,
            $signed_headers,
            $signature
        );

        $request_headers                  = $sign_headers;
        $request_headers['authorization'] = $authorization;
        unset( $request_headers['host'] ); // WP HTTP API sets Host from the URL

        $url = $this->scheme . '://' . $this->host() . $uri . ( $canonical_query !== '' ? '?' . $canonical_query : '' );

        if ( ! empty( $args['prepare_only'] ) ) return [ 'url' => $url, 'headers' => $request_headers ];

        if ( $body_file !== '' ) {
            // The WP HTTP API can only take a string body, so a streamed
            // upload goes straight to cURL — with the very same signed
            // headers computed above, nothing about SigV4 changes.
            return $this->stream_request( $method, $url, $request_headers, $body_file, $timeout );
        }

        $request_args = [
            'method'  => $method,
            'headers' => $request_headers,
            'body'    => $body !== '' ? $body : null,
            'timeout' => $timeout,
        ];
        // Explicit, matching the cURL path's own https_ssl_verify handling
        // below — the streams transport verifies certificates too, but
        // saying so keeps the two transports consistent if a site ever
        // relaxes verification via the same filter.
        $request_args['sslverify'] = apply_filters( 'https_ssl_verify', true, $url );

        if ( $stream_to !== '' ) {
            // The WP HTTP API writes the response body straight to this file
            // instead of buffering it — supported by both the cURL and the
            // streams transport, so no hand-rolled cURL is needed here the
            // way the upload side required.
            $request_args['stream']   = true;
            $request_args['filename'] = $stream_to;
        }

        return wp_remote_request( $url, $request_args );
    }

    /**
     * PUT a file straight off disk via cURL, returning a
     * wp_remote_request()-shaped array so expect_status() and the
     * wp_remote_retrieve_* helpers work on it unchanged.
     *
     * @param string $method    HTTP method (PUT).
     * @param string $url       Full request URL.
     * @param array  $headers   Signed headers, name => value.
     * @param string $file_path Absolute path of the file to send.
     * @param int    $timeout   Seconds.
     * @return array|WP_Error
     */
    private function stream_request( $method, $url, array $headers, $file_path, $timeout ) {
        $size = @filesize( $file_path );
        if ( $size === false ) {
            return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot read local file: %s', wp_basename( $file_path ) ) );
        }

        $handle = @fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot open local file: %s', wp_basename( $file_path ) ) );
        }

        $header_lines = [];
        foreach ( $headers as $name => $value ) {
            $header_lines[] = $name . ': ' . $value;
        }
        // Suppress cURL's automatic 100-continue: some S3-compatible
        // endpoints never answer it and every upload then eats cURL's 1s
        // wait. Unsigned extra headers don't affect the signature.
        $header_lines[] = 'Expect:';

        // Same SSL policy the WP HTTP API would have applied to this
        // request, so a site that already relaxes verification (e.g. a
        // self-signed Minio behind https) keeps working after the switch.
        $ssl_verify = apply_filters( 'https_ssl_verify', true, $url );

        $ch = self::curl_handle();
        if ( ! $ch ) {
            fclose( $handle );
            return new WP_Error( 'isxs_curl_init_failed', __( 'Could not start the cURL connection', 'insightx-offload' ) );
        }
        $curl_options = [
            CURLOPT_URL            => $url,
            CURLOPT_UPLOAD         => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_INFILE         => $handle,
            CURLOPT_INFILESIZE     => $size,
            CURLOPT_HTTPHEADER     => $header_lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => (bool) $ssl_verify,
            CURLOPT_SSL_VERIFYHOST => $ssl_verify ? 2 : 0,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ];
        // WordPress ships its own CA bundle — point at it when it exists
        // (the standard WP_Http_Curl behaviour); otherwise let cURL fall
        // back to the system bundle instead of referencing a file this
        // install doesn't have.
        $cainfo = ABSPATH . WPINC . '/certificates/ca-bundle.crt';
        if ( file_exists( $cainfo ) ) {
            $curl_options[ CURLOPT_CAINFO ] = $cainfo;
        }
        curl_setopt_array( $ch, $curl_options );

        $response_body = curl_exec( $ch );
        $errno         = curl_errno( $ch );
        $error         = curl_error( $ch );
        $code          = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        // Deliberately NOT closed: the handle is reused by the next upload so
        // the connection it just opened stays alive. curl_handle() resets it
        // before it is configured again.
        fclose( $handle );

        if ( $errno ) {
            return new WP_Error( 'isxs_curl_' . $errno, sprintf( __( 'Upload failed (cURL %d): %s', 'insightx-offload' ), $errno, $error ) );
        }

        return [
            'headers'  => [],
            'body'     => is_string( $response_body ) ? $response_body : '',
            'response' => [ 'code' => $code, 'message' => '' ],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    /**
     * Same as request(), but retries the handful of failures that are worth
     * retrying: transport errors (dropped connection, timeout) and the
     * server-side "try again" codes. Credential/permission/not-found
     * responses are returned immediately — repeating those just wastes the
     * batch's time budget and hammers the endpoint.
     *
     * @return array|WP_Error
     */
    private function request_with_retry( $method, $key, array $args = [], $attempts = 3 ) {
        $delays   = [ 1, 3 ]; // seconds between attempts
        $response = null;

        for ( $i = 0; $i < $attempts; $i++ ) {
            $response = $this->request( $method, $key, $args );

            if ( ! self::is_retryable( $response ) ) {
                return $response;
            }
            if ( $i < $attempts - 1 ) {
                $delay = isset( $delays[ $i ] ) ? $delays[ $i ] : end( $delays );
                if ( ! self::may_wait( $delay ) ) {
                    break;
                }
                sleep( $delay );
            }
        }

        return $response;
    }

    /**
     * Whether a response is worth another attempt.
     *
     * @param array|WP_Error $response
     * @return bool
     */
    private static function is_retryable( $response ) {
        if ( is_wp_error( $response ) ) {
            // Local read/permission problems never fix themselves.
            $code = $response->get_error_code();
            return $code !== 'isxs_read_failed' && $code !== 'isxs_missing_file' && $code !== 'isxs_file_too_large';
        }
        $status = (int) wp_remote_retrieve_response_code( $response );
        return in_array( $status, [ 429, 500, 502, 503, 504 ], true );
    }

    /**
     * The `x-amz-content-sha256` value to sign this request with.
     *
     * SigV4 lets a request over TLS declare UNSIGNED-PAYLOAD instead of the
     * body's real digest — the transport already protects the body, so the
     * signature only has to cover the headers. Taking that route on uploads
     * skips a full extra read of every file: hashing streams the file off
     * disk before cURL then reads it again to send it, which on a large
     * video is a second pass over hundreds of megabytes purely to compute a
     * number the endpoint does not need. Every S3-compatible endpoint this
     * plugin targets (S3, Minio, R2, Spaces) accepts it, and the AWS SDK
     * signs S3 uploads this way by default.
     *
     * Only for streamed uploads over https: a plaintext endpoint has no
     * transport integrity to lean on, and hashing an in-memory body (the
     * small XML of a delete/list request) costs nothing worth avoiding.
     * Filter `isxs_sign_upload_payload` to true to force real hashing on an
     * endpoint that rejects UNSIGNED-PAYLOAD.
     *
     * @param string $body_file Absolute path of a streamed request body, or ''.
     * @param string $body      In-memory request body.
     * @return string|false Hex digest or UNSIGNED-PAYLOAD; false if the file is unreadable.
     */
    private function payload_hash( $body_file, $body ) {
        if ( $body_file === '' ) {
            return hash( 'sha256', $body );
        }

        if ( $this->scheme === 'https' && ! apply_filters( 'isxs_sign_upload_payload', false, $this->endpoint ) ) {
            return 'UNSIGNED-PAYLOAD';
        }

        // hash_file() streams the file in chunks, so signing a 2 GB upload
        // costs the same memory as signing an empty one.
        return hash_file( 'sha256', $body_file );
    }

    /**
     * A cURL handle ready to configure, reusing the previous one so its
     * live connection is kept. See $curl_handle.
     *
     * @return resource|\CurlHandle|false
     */
    private static function curl_handle() {
        if ( self::$curl_handle === null ) {
            $handle = curl_init();
            if ( ! $handle ) {
                return false;
            }
            self::$curl_handle = $handle;
        } else {
            // Clears every option set by the previous upload — including the
            // file handle it read from, which is closed by now — while
            // leaving the connection and TLS session caches intact.
            curl_reset( self::$curl_handle );
        }

        return self::$curl_handle;
    }

    /**
     * Whether this PHP can stream a request body from disk.
     */
    private static function can_stream() {
        return function_exists( 'curl_init' ) && function_exists( 'curl_setopt_array' );
    }

    /**
     * Guard for the no-cURL fallback path: refuse a file that can't fit in
     * the remaining memory instead of letting PHP fatal on it.
     *
     * @return true|WP_Error
     */
    private static function fits_in_memory( $file_path ) {
        $size = @filesize( $file_path );
        if ( $size === false ) {
            return new WP_Error( 'isxs_read_failed', sprintf( 'Cannot read local file: %s', wp_basename( $file_path ) ) );
        }

        $limit = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );
        if ( $limit <= 0 ) {
            return true; // Unlimited.
        }

        // The body is held twice at peak (file contents + request payload),
        // so budget 2.5× the file size plus what's already in use.
        $needed = (int) ( $size * 2.5 ) + memory_get_usage( true );
        if ( $needed > $limit ) {
            return new WP_Error(
                'isxs_file_too_large',
                sprintf(
                    __( 'The file is larger than memory can hold (%s, memory_limit %s) and this server has no cURL for streaming uploads', 'insightx-offload' ),
                    size_format( $size ),
                    ini_get( 'memory_limit' )
                )
            );
        }

        return true;
    }

    /**
     * Reduce a response to true|WP_Error against a list of acceptable codes.
     *
     * @param array|WP_Error $response wp_remote_request result.
     * @param int[]          $ok_codes Acceptable HTTP status codes.
     * @return true|WP_Error
     */
    private function expect_status( $response, array $ok_codes ) {
        if ( is_wp_error( $response ) ) return $response;
        $code = wp_remote_retrieve_response_code( $response );
        if ( in_array( $code, $ok_codes, true ) ) return true;

        $body    = wp_remote_retrieve_body( $response );
        $code_tag = '';
        $message  = '';
        if ( $body ) {
            $xml = self::parse_xml( $body );
            if ( $xml !== false ) {
                if ( isset( $xml->Code ) && trim( (string) $xml->Code ) !== '' ) {
                    $code_tag = ' [' . trim( (string) $xml->Code ) . ']';
                }
                if ( isset( $xml->Message ) && trim( (string) $xml->Message ) !== '' ) {
                    $message = ' — ' . trim( (string) $xml->Message );
                }
            }
        }
        return new WP_Error( 'isxs_http_' . $code, sprintf( 'HTTP %d%s%s', $code, $code_tag, $message ) );
    }

    /**
     * A 200 status doesn't guarantee a real ListObjectsV2 response — a
     * connection dropped mid-transfer, or a captive-portal/proxy page, can
     * still arrive as HTTP 200 with a body that isn't S3 XML at all.
     * Without checking for the real root element, an incomplete/garbage
     * body has no `<IsTruncated>true</IsTruncated>` to match, which reads
     * as "no more pages" and silently ends the caller's loop as if the
     * listing were genuinely exhausted.
     *
     * @return WP_Error
     */
    private static function malformed_response_error() {
        return new WP_Error(
            'isxs_malformed_response',
            __( 'The response from the source bucket is not valid XML (the connection may have dropped while fetching)', 'insightx-offload' )
        );
    }
}

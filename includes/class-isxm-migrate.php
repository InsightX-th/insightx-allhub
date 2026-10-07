<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Migrate — Pulls existing media from another S3-compatible source
 * bucket (e.g. Amazon S3) down to the local server, then hands off to
 * ISXM_Offload to push it up to the currently configured destination.
 *
 * The source bucket is never modified — this only ever GETs objects.
 *
 * @since 0.1.0
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Migrate {

    /**
     * Build a client bound to the source bucket settings.
     *
     * @param array $overrides Optional live form values (endpoint/region/bucket/
     *                         access_key/secret_key/path_style) that take
     *                         priority over the saved settings — used so
     *                         "test connection" can check unsaved edits.
     */
    private static function source_client( array $overrides = [] ) {
        $s = ISXM_Settings::all();
        $defaults = [
            'endpoint'   => $s['source_endpoint'],
            'region'     => $s['source_region'],
            'bucket'     => $s['source_bucket'],
            'access_key' => $s['source_access_key'],
            'secret_key' => $s['source_secret_key'],
            'path_style' => $s['source_path_style'],
        ];
        return new ISXM_Client( array_merge( $defaults, $overrides ) );
    }

    /**
     * Real object count currently in the source bucket (List Objects V2) —
     * a reference number only, independent of the WP-attachment-based
     * pending count that actually drives the migrate batch loop. Resumable
     * via $continuation_token/next_token so the caller can keep paginating
     * across multiple requests until `complete` is true.
     *
     * @param array  $overrides Optional live form values, see source_client().
     * @param string $continuation_token Resume cursor from a previous incomplete call ('' to start).
     * @return array{total:int,next_token:string,complete:bool}|WP_Error
     */
    public static function count_source_objects( array $overrides = [], $continuation_token = '' ) {
        return self::source_client( $overrides )->count_objects( $continuation_token );
    }

    /**
     * Object key prefix on the source bucket for one attachment
     * (mirrors ISXM_Offload's base_key logic, but with the source's own
     * prefix/year-month settings and no object-version folder).
     *
     * @param int $attachment_id Attachment post ID.
     * @return string
     */
    public static function source_key_base( $attachment_id ) {
        $s = ISXM_Settings::all();

        $base_key = $s['source_prefix'] !== '' ? trailingslashit( ltrim( $s['source_prefix'], '/' ) ) : '';

        if ( $s['source_use_year_month'] ) {
            // Normalised, for the same reason ISXM_Offload does it: a stale
            // absolute path in the meta would otherwise become part of the
            // source object key.
            $relative = (string) ISXM_Offload::relative_local_path( $attachment_id );
            $subdir   = $relative === '' ? '' : dirname( $relative );
            if ( $subdir !== '.' && $subdir !== '' ) {
                $base_key .= trailingslashit( $subdir );
            }
        }

        return $base_key;
    }

    /**
     * Additional base directory URLs the DB may reference for this
     * attachment (i.e. where the source publicly served it from), for
     * the permanent URL rewrite.
     *
     * @param int $attachment_id Attachment post ID.
     * @return string[]
     */
    public static function extra_old_dirs( $attachment_id ) {
        $source_base = ISXM_Settings::source_public_base_url();
        if ( $source_base === '' ) {
            return [];
        }
        return [ trailingslashit( $source_base ) . self::source_key_base( $attachment_id ) ];
    }

    /**
     * WordPress' size-suffix pattern (e.g. "-300x200") stripped from a
     * filename to recover the original/canonical filename it was generated
     * from — same regex ISXM_Offload::rewrite_content_urls() already uses.
     */
    private static function canonical_filename( $filename ) {
        return preg_replace( '#-\d+x\d+(\.[a-zA-Z0-9]+)$#', '$1', $filename );
    }

    /**
     * Scan ONE page of the source bucket's real object listing (ground
     * truth via ListObjectsV2) and resolve every key back to a WP
     * attachment via its `_wp_attached_file` meta — grouping the real keys
     * that belong to each attachment (original + every size WordPress
     * generated) so migrate_attachment() fetches exactly what's actually
     * there instead of guessing a key that might not exist (guessing broke
     * down whenever a file's real key had extra path segments this site's
     * own settings don't reproduce, e.g. an object-version folder from a
     * previous offload). Keys outside the configured source prefix, or that
     * don't match any attachment on this site, are silently skipped — they
     * aren't ours to migrate. Every other resolved attachment is included
     * unconditionally — this is a plain "pull what's really on Source, push
     * it to Destination" mirror, not gated on `_isxs_offload` postmeta
     * (which doesn't reliably reflect what's physically in either bucket).
     *
     * Each call scans exactly one listing page (up to 200 keys); the
     * cursor-based callers page through with the returned next_token.
     *
     * @param string $continuation_token Resume token from a previous call ('' to start from the beginning).
     * @return array{items:array<int,array<string,string>>, next_token:string, done:bool, scanned_keys:int}|WP_Error
     *               items: attachment_id => [ real_s3_key => filename ].
     *               scanned_keys: total keys in this page (including ones
     *               skipped for being outside the prefix / unmatched) — lets
     *               the caller report progress in OBJECT units, matching the
     *               source-object-count denominator shown in the UI.
     */
    public static function scan_source_batch( $continuation_token, $indexed = false ) {
        global $wpdb;

        $s      = ISXM_Settings::all();
        $prefix = $s['source_prefix'];

        $page = self::source_client()->list_objects_keys_page( $continuation_token, 200 );
        if ( is_wp_error( $page ) ) {
            return $page;
        }

        $scanned_keys = count( $page['keys'] );

        // Resolve the whole page's keys with ONE query instead of one per key:
        // at 200 keys per page that was 200 round trips against a table with
        // millions of rows, and it was the dominant cost of a large migrate.
        // The keys are turned into `_wp_attached_file` candidates first, then
        // looked up together; a second pass over the SAME ordered list builds
        // $items, so the resulting order is byte-for-byte what the per-key
        // loop produced — run_migrate_batch()'s mid-page resume offset counts
        // on that order being stable.
        $resolved = []; // ordered [ candidate, key, filename ]
        foreach ( $page['keys'] as $key ) {
            if ( $prefix !== '' && strpos( $key, $prefix ) !== 0 ) {
                continue; // outside our configured prefix — not ours
            }

            $relative = substr( $key, strlen( $prefix ) );
            $segments = explode( '/', $relative );
            $filename = array_pop( $segments );
            if ( $filename === '' ) {
                continue;
            }

            $year_month = '';
            if ( $s['source_use_year_month'] && count( $segments ) >= 2 ) {
                $year_month = $segments[0] . '/' . $segments[1] . '/';
            }

            // Sizes and the original of a big (-scaled) or EXIF-rotated
            // (-rotated) image keep the bare name, while the attachment is
            // recorded as name-scaled.ext — try those forms too, or every
            // such size is silently left behind on the source.
            $resolved[] = [
                'candidates' => ISXM_Offload::lookup_paths( $year_month . $filename ),
                'key'        => $key,
                'filename'   => $filename,
            ];
        }

        $items = []; // attachment_id => [ key => filename ]
        if ( empty( $resolved ) ) {
            return [
                'items'        => $items,
                'next_token'   => $page['next_token'],
                'done'         => $page['next_token'] === '',
                'scanned_keys' => $scanned_keys,
            ];
        }

        $candidates   = array_values( array_unique( array_merge( ...wp_list_pluck( $resolved, 'candidates' ) ) ) );
        if ( $indexed ) {
            $by_candidate = ISXM_Migration_Store::resolve( $candidates );
        } else {
        $placeholders = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );
        $rows         = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built from a counted array
            $candidates
        ) );
        if ( $wpdb->last_error ) {
            return new WP_Error( 'isxs_source_resolve', 'Could not resolve source files in the database; checkpoint retained.' );
        }

        $by_candidate = [];
        foreach ( $rows as $row ) {
            // First row wins, matching the LIMIT 1 the per-key query used.
            if ( ! isset( $by_candidate[ $row->meta_value ] ) ) {
                $by_candidate[ $row->meta_value ] = (int) $row->post_id;
            }
        }

        }

        $unmatched = [];
        foreach ( $resolved as $entry ) {
            $id = 0;
            foreach ( $entry['candidates'] as $candidate ) {
                if ( ! empty( $by_candidate[ $candidate ] ) ) {
                    $id = $by_candidate[ $candidate ];
                    break;
                }
            }
            if ( ! $id ) {
                $unmatched[] = $entry['key'];
                continue;
            }
            $items[ $id ][ $entry['key'] ] = $entry['filename'];
        }
        $items = self::pick_versions( $items, $page['modified'] ?? [], $s['source_bucket'] );

        // run_migrate_batch() resumes an interrupted page by POSITION within
        // this array, so the order has to be reproducible when the same page
        // is scanned again. Insertion order alone isn't: it follows whichever
        // of an attachment's keys the listing happened to return first, so a
        // single new size-variant object appearing on the source between the
        // two scans could reorder items and make the offset skip live work.
        // Sorting by attachment ID makes the position depend only on WHICH
        // attachments the page resolves to.
        ksort( $items, SORT_NUMERIC );

        return [
            'items'        => $items,
            'next_token'   => $page['next_token'],
            'done'         => $page['next_token'] === '',
            'scanned_keys' => $scanned_keys,
            'unmatched'    => $unmatched, // inside the prefix, but no attachment uses them
        ];
    }

    /**
     * Migrate one attachment: fetch any locally-missing files using their
     * REAL source keys (as resolved by scan_source_batch(), not guessed),
     * then offload them to the configured destination.
     *
     * @param int   $attachment_id Attachment post ID.
     * @param array $key_map       [ real_s3_key => filename ] for this attachment, from scan_source_batch().
     * @param bool  $defer_persist Skip the per-attachment DB URL rewrite —
     *                             bulk callers batch it via
     *                             ISXM_Offload::collect_url_pairs() +
     *                             ISXM_DB_Rewriter::replace_urls_bulk().
     * @return true|WP_Error
     */
    /**
     * Where each of an attachment's files will come from: the existing local
     * copy, or a download into this run's private staging dir.
     *
     * @return array{dir:string,local:string,primary:string,paths:array<string,string>,downloads:array}|WP_Error
     */
    public static function plan_staged( $id, array $keys, $run ) {
        $local = ISXM_Offload::local_path( $id );
        if ( $local === '' ) return new WP_Error( 'isxs_no_path', 'Unknown attachment path.' );
        $uploads = wp_get_upload_dir();
        $dir = trailingslashit( $uploads['basedir'] ) . 'isxs-migration/' . hash( 'sha256', $run ) . '/' . (int) $id . '/';
        if ( ! wp_mkdir_p( $dir ) ) return new WP_Error( 'isxs_stage_dir', 'Could not create migration staging directory.' );
        $primary = wp_basename( $local );
        if ( ! in_array( $primary, $keys, true ) && ! is_file( $local ) ) {
            $first = key( $keys ); $base = dirname( $first );
            $keys[ ( $base === '.' ? '' : $base . '/' ) . $primary ] = $primary;
        }
        $paths = []; $downloads = []; $seen = [];
        foreach ( $keys as $key => $name ) {
            if ( $name === '' || ISXM_Offload::safe_filename( $name ) !== $name ) return new WP_Error( 'isxs_source_name', 'Unsafe source filename.' );
            if ( isset( $seen[ $name ] ) && $seen[ $name ] !== $key ) return new WP_Error( 'isxs_source_collision', 'Multiple source keys resolve to the same filename.' );
            $seen[ $name ] = $key;
            $original = trailingslashit( dirname( $local ) ) . $name;
            $paths[ $name ] = is_file( $original ) ? $original : $dir . $name;
            if ( ! is_file( $paths[ $name ] ) ) $downloads[ $name ] = [ 'key' => $key, 'path' => $paths[ $name ] ];
        }
        if ( is_file( $local ) ) $paths[ $primary ] = $local;
        return [ 'dir' => $dir, 'local' => $local, 'primary' => $primary, 'paths' => $paths, 'downloads' => $downloads ];
    }

    /**
     * With object versioning, a source bucket can hold one attachment's file
     * under several version folders (re-offloaded, edited, regenerated — the
     * old copies stay behind): .../2024/05/16834567/photo.jpg and
     * .../2024/05/17000123/photo.jpg. Keep the copy the site really uses:
     *  1. the folder WP Offload Media records for the attachment
     *     (as3cf_items.path) — also drops stale copies that sit on a
     *     different listing page, which would otherwise overwrite;
     *  2. else, per file name, the newest object by LastModified.
     * With neither, duplicates are left as they are and the item reports
     * isxs_source_collision rather than guessing.
     *
     * @param array  $items    attachment_id => [ key => filename ].
     * @param array  $modified key => unix time, from the listing page.
     * @param string $bucket   Source bucket name.
     * @return array
     */
    public static function pick_versions( array $items, array $modified, $bucket ) {
        if ( ! $items ) return $items;
        $dirs = self::as3cf_dirs( array_keys( $items ), $bucket );
        foreach ( $items as $id => $keys ) {
            if ( isset( $dirs[ $id ] ) ) {
                // Only stale copies on this page: skip them. Older version
                // folders list first, and once their files are on the
                // destination the live ones would be taken as already there.
                // An item whose live folder is missing everywhere stays
                // "not in the cloud" in the Media Library, never silently wrong.
                $items[ $id ] = array_filter( $keys, function ( $key ) use ( $dirs, $id ) { return dirname( $key ) === $dirs[ $id ]; }, ARRAY_FILTER_USE_KEY );
                if ( ! $items[ $id ] ) unset( $items[ $id ] );
                continue;
            }
            $by_name = [];
            foreach ( $keys as $key => $name ) $by_name[ $name ][] = $key;
            foreach ( $by_name as $name => $candidates ) {
                if ( count( $candidates ) < 2 ) continue;
                $dated = array_filter( $candidates, function ( $key ) use ( $modified ) { return isset( $modified[ $key ] ); } );
                if ( count( $dated ) !== count( $candidates ) ) continue; // can't tell which is newer
                usort( $candidates, function ( $a, $b ) use ( $modified ) { return $modified[ $b ] <=> $modified[ $a ]; } );
                foreach ( array_slice( $candidates, 1 ) as $stale ) unset( $items[ $id ][ $stale ] );
            }
        }
        return $items;
    }

    /**
     * Folder of each attachment's original in WP Offload Media's own table,
     * when that plugin's data is present.
     *
     * @param int[]  $ids
     * @param string $bucket
     * @return array<int,string> attachment_id => key folder
     */
    private static function as3cf_dirs( array $ids, $bucket ) {
        global $wpdb;
        $table = $wpdb->prefix . 'as3cf_items';
        // One cheap check per 200-key listing page; not cached across calls.
        if ( ! $ids || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) return [];
        $marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT source_id, path FROM {$table} WHERE source_type = 'media-library' AND bucket = %s AND source_id IN ({$marks})", // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built from a counted array
            array_merge( [ $bucket ], array_map( 'intval', $ids ) )
        ) );
        $dirs = [];
        foreach ( (array) $rows as $row ) {
            if ( (string) $row->path !== '' ) $dirs[ (int) $row->source_id ] = dirname( (string) $row->path );
        }
        return $dirs;
    }

    /**
     * Fetch and upload the files of several attachments at once, ahead of
     * their one-by-one commit. Attachments are otherwise moved strictly in
     * sequence, so a run spent most of its time waiting on round trips with
     * only one item's handful of files in flight.
     *
     * Nothing is committed here. migrate_staged() then finds the downloads
     * already staged and offload_attachment() finds an upload receipt for
     * every object, so each item's own pass only writes its record — and a
     * crash anywhere in between just repeats skipped work. Failures are
     * left for that pass to retry and report.
     *
     * @param array  $entries [ [ 'id' => int, 'keys' => [ key => filename ] ], … ]
     * @param string $run     Job run id (owns the staging dirs).
     */
    public static function prefetch( array $entries, $run ) {
        $per = (int) apply_filters( 'isxm_migrate_concurrency', 4 );
        if ( $per <= 1 || count( $entries ) < 2 ) return; // parallel transfers switched off
        $s = ISXM_Settings::all();
        $plans = []; $gets = [];
        foreach ( $entries as $entry ) {
            $id   = (int) $entry['id'];
            $info = ISXM_Offload::get_record( $id );
            // Items already on this destination take transfer()'s verified path.
            if ( ISXM_Offload::is_protected_download( $id ) || ( is_array( $info ) && ( $info['bucket'] ?? '' ) === $s['bucket'] && ( $info['endpoint'] ?? '' ) === $s['endpoint'] ) ) continue;
            $plan = self::plan_staged( $id, $entry['keys'], $run );
            if ( is_wp_error( $plan ) ) continue;
            $plans[ $id ] = [ $plan, $info ];
            foreach ( $plan['downloads'] as $name => $download ) $gets[ $id . '|' . $name ] = $download;
        }
        $limit = min( 16, $per * count( $entries ) );
        if ( $gets ) self::source_client()->transfer_many( 'GET', $gets, $limit );
        $puts = [];
        foreach ( $plans as $id => list( $plan, $info ) ) {
            $relative = ISXM_Offload::relative_local_path( $id );
            if ( $relative === null ) continue;
            // The same keys offload_attachment() will compute for this item.
            $destination = ISXM_Offload::destination_key( $id, $relative, $info, $plan['dir'] );
            foreach ( $plan['paths'] as $name => $path ) {
                if ( ! is_file( $path ) ) continue;
                $type = wp_check_filetype( $name );
                $puts[ $id . '|' . $name ] = [ 'key' => $destination['base_key'] . $name, 'path' => $path,
                    'mime' => $type['type'] ?: 'application/octet-stream', 'acl' => ! empty( $s['send_public_acl'] ), 'receipt_dir' => $plan['dir'] ];
            }
        }
        if ( $puts ) ( new ISXM_Client() )->transfer_many( 'PUT', $puts, $limit );
    }

    /**
     * Sub-folders of $prefix on the source bucket, for the folder picker.
     *
     * @return array{folders:string[],truncated:bool}|WP_Error
     */
    public static function list_source_folders( $prefix = '' ) {
        return self::source_client()->list_folders( $prefix );
    }

    /**
     * How many of the first $sample keys under $folder belong to a Media
     * Library attachment — the folder picker uses it to tell which ticked
     * folder is the uploads root (Source Prefix) and which are copied
     * as-is. Matches both "2024/05/a.jpg" and "a.jpg" so the answer doesn't
     * depend on the Year & Month toggle being right yet.
     *
     * @return int|WP_Error
     */
    public static function count_attachment_matches( $folder, $sample = 200 ) {
        global $wpdb;
        $page = self::source_client()->list_objects_keys_page( '', $sample, $folder );
        if ( is_wp_error( $page ) ) return $page;

        $by_key = [];
        foreach ( $page['keys'] as $key ) {
            $relative = substr( $key, strlen( $folder ) );
            if ( $relative === '' || substr( $relative, -1 ) === '/' ) continue;
            $by_key[ $key ] = array_unique( array_merge(
                ISXM_Offload::lookup_paths( $relative ),
                ISXM_Offload::lookup_paths( wp_basename( $relative ) )
            ) );
        }
        if ( ! $by_key ) return 0;

        $candidates = array_values( array_unique( array_merge( ...array_values( $by_key ) ) ) );
        $marks      = implode( ',', array_fill( 0, count( $candidates ), '%s' ) );
        $found      = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$marks})", // phpcs:ignore WordPress.DB.PreparedSQL -- placeholders built from a counted array
            $candidates
        ) );
        $found   = array_flip( (array) $found );
        $matches = 0;
        foreach ( $by_key as $paths ) {
            foreach ( $paths as $path ) {
                if ( isset( $found[ $path ] ) ) { $matches++; break; }
            }
        }
        return $matches;
    }

    /**
     * One listing page of an extra source folder (see
     * ISXM_Settings::extra_prefixes()).
     *
     * @return array{keys:string[],next_token:string}|WP_Error
     */
    public static function list_extra_keys( $prefix, $continuation_token = '' ) {
        return self::source_client()->list_objects_keys_page( $continuation_token, 200, $prefix );
    }

    /**
     * Copy one object no attachment owns to the destination under the SAME
     * key. Streamed through a temp file; the source is only read.
     *
     * @return true|WP_Error
     */
    public static function copy_extra_object( $key ) {
        // tempnam(get_temp_dir()), not wp_tempnam(): that lives in wp-admin
        // includes, which a WP-Cron or WP-CLI runner doesn't load.
        $tmp = tempnam( get_temp_dir(), 'isxs-extra-' );
        if ( $tmp === false ) {
            return new WP_Error( 'isxs_temp', 'Could not create a temporary file.' );
        }
        $got = self::source_client()->get_object_to_file( $key, $tmp );
        if ( is_wp_error( $got ) ) {
            @unlink( $tmp );
            return $got;
        }
        $type = wp_check_filetype( wp_basename( $key ) );
        $put  = ( new ISXM_Client() )->put_object( $key, $tmp, $type['type'] ?: 'application/octet-stream', (bool) ISXM_Settings::get( 'send_public_acl' ) );
        @unlink( $tmp );
        return is_wp_error( $put ) ? $put : true;
    }

    /** Private staging keeps downloads separate from pre-existing local media. */
    public static function migrate_staged( $id, array $keys, $run ) {
        $plan = self::plan_staged( $id, $keys, $run );
        if ( is_wp_error( $plan ) ) return $plan;
        $dir = $plan['dir']; $paths = $plan['paths'];
        foreach ( self::source_client()->transfer_many( 'GET', $plan['downloads'] ) as $result ) if ( is_wp_error( $result ) ) return $result;
        $result = ( new ISXM_Offload() )->offload_attachment( $id, wp_get_attachment_metadata( $id ), true, 'migrate', $paths, $dir );
        ISXM_Offload::clear_pending_deletions();
        if ( is_wp_error( $result ) ) return $result;
        $record = ISXM_Offload::get_record( $id );
        foreach ( $paths as $name => $path ) {
            if ( strpos( $path, $dir ) === 0 && in_array( $name, $record['files'] ?? [], true ) ) @unlink( $path );
        }
        foreach ( glob( $dir . '*.uploaded' ) ?: [] as $receipt ) @unlink( $receipt );
        @rmdir( $dir );
        return true;
    }

    public static function migrate_attachment( $attachment_id, array $key_map, $defer_persist = false ) {
        if ( empty( $key_map ) ) {
            return new WP_Error( 'isxs_no_source_keys', __( 'File for this item not found in the source bucket', 'insightx-offload' ) );
        }

        // Normalised against the current uploads dir: the staging files are
        // written here, and a stale absolute path from another host would
        // put them outside the uploads tree entirely.
        $file = ISXM_Offload::local_path( $attachment_id );
        if ( $file === '' ) {
            return new WP_Error( 'isxs_no_path', __( 'Unknown file location for this attachment', 'insightx-offload' ) );
        }

        $local_dir = trailingslashit( dirname( $file ) );
        wp_mkdir_p( $local_dir );

        $client = self::source_client();

        foreach ( $key_map as $key => $filename ) {
            $path = $local_dir . $filename;
            if ( file_exists( $path ) ) {
                continue;
            }

            // Streamed to disk — a large source object held whole in memory
            // is a fatal waiting to happen, and a fatal kills the whole batch.
            $fetched = $client->get_object_to_file( $key, $path );
            if ( is_wp_error( $fetched ) ) {
                return new WP_Error(
                    'isxs_source_fetch_failed',
                    sprintf( __( 'Could not fetch %s from the source (key: %s): %s', 'insightx-offload' ), $filename, $key, $fetched->get_error_message() )
                );
            }
        }

        // Persist is always deferred on the inner offload — this method owns
        // the rewrite so the source's extra old dirs are included.
        $metadata = wp_get_attachment_metadata( $attachment_id );
        $result   = ( new ISXM_Offload() )->offload_attachment( $attachment_id, $metadata, true, 'migrate' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        // The DB already has URLs pointing at wherever the source publicly
        // served media from — which is not necessarily this server's local
        // wp-content/uploads. Tell persist_permanent_urls() about it so it
        // can find and replace those too, on top of any local-uploads URLs.
        if ( ! $defer_persist ) {
            ISXM_Offload::persist_permanent_urls( $attachment_id, self::extra_old_dirs( $attachment_id ) );
            // The inner offload always defers, so its "Remove Local Media"
            // deletions are queued too — release them now the rewrite is done.
            ISXM_Offload::flush_local_deletions();
        }

        return true;
    }
}

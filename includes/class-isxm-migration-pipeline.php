<?php
/** Single-owner migration: indexed listing, bounded transfers, one final rewrite. */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Migration_Pipeline {
    public static function step( ISXM_Job $job, $budget = 15 ) {
        ISXM_Migration_Store::install();
        $deadline = microtime( true ) + $budget;
        if ( empty( $job->migration ) ) {
            $job->migration = [ 'version' => 2, 'stage' => 'index', 'after' => 0, 'rewrite' => [ 'table' => 0, 'after' => 0 ] ];
            self::save( $job, false );
        }
        if ( $job->migration['stage'] === 'complete' ) return self::result( $job );
        if ( self::stop( $job, $deadline ) ) return self::result( $job );
        $m =& $job->migration;
        $changed = self::settings_changed( $job );
        if ( $changed ) return $changed;
        if ( $m['stage'] === 'index' ) {
            $before = $m['after'];
            $window = ISXM_Migration_Store::index_window( $m['after'] );
            ISXM_Migration_Store::index_paths( $window['rows'] );
            $m['after'] = $window['after'];
            if ( $window['done'] ) { $m['stage'] = 'transfer'; $m['after'] = 0; }
            self::save( $job, $m['after'] !== $before || $m['stage'] !== 'index' );
            return self::result( $job );
        }
        if ( $m['stage'] === 'transfer' ) {
            if ( ! isset( $m['items'] ) ) {
                $parts = explode( '|', $job->cursor, 3 );
                $token = $parts[1] ?? '';
                $page = ISXM_Migrate::scan_source_batch( $token, true );
                if ( is_wp_error( $page ) ) return $page;
                if ( ! $page['done'] && $page['next_token'] === $token ) return new WP_Error( 'isxs_source_cursor', 'Source listing did not advance.' );
                $m['items'] = []; $matched = 0;
                foreach ( $page['items'] as $id => $keys ) { $m['items'][] = [ 'id' => $id, 'keys' => $keys ]; $matched += count( $keys ); }
                $m['index'] = (int) ( $parts[2] ?? 0 );
                $m['next'] = $page['next_token']; $m['done'] = $page['done'];
                $m['unmatched'] = $page['scanned_keys'] - $matched;
                self::save( $job );
            }
            $group = max( 1, (int) apply_filters( 'isxm_migrate_parallel_items', 4 ) ); $prefetched = $m['index'];
            while ( $m['index'] < count( $m['items'] ) && ! self::stop( $job, $deadline ) ) {
                // Move the next few attachments' bytes together; each is still
                // committed and checkpointed on its own below.
                if ( $m['index'] >= $prefetched ) {
                    ISXM_Migrate::prefetch( array_slice( $m['items'], $m['index'], $group ), $job->run_id );
                    $prefetched = $m['index'] + $group;
                }
                $entry = $m['items'][ $m['index'] ]; $id = $entry['id'];
                $job->activity['attachment'] = $id;
                self::save( $job, false );
                $result = self::transfer( $job, $id, $entry['keys'] );
                if ( is_wp_error( $result ) ) {
                    if ( $result->get_error_code() === 'isxs_transfer_yield' ) return self::result( $job );
                    $job->errors[] = '#' . $id . ': ' . $result->get_error_message();
                    $job->errors = array_slice( $job->errors, -ISXM_Job::MAX_ERRORS ); $job->error_count++;
                }
                $job->processed += count( $entry['keys'] ); $m['index']++;
                self::save( $job );
            }
            if ( $m['index'] >= count( $m['items'] ) ) {
                $job->processed += $m['unmatched']; $job->cursor = 'run|' . $m['next'];
                if ( $m['done'] ) $m['stage'] = 'rewrite';
                unset( $m['items'], $m['index'], $m['next'], $m['done'], $m['unmatched'] );
                self::save( $job );
            }
            return self::result( $job );
        }
        if ( $m['stage'] === 'rewrite' ) {
            $result = ISXM_DB_Rewriter::replace_urls_window( [], $m['rewrite'], $deadline, function ( $content ) use ( $job ) {
                return ISXM_Migration_Store::url_map( $job->run_id, $content );
            } );
            if ( is_wp_error( $result ) ) return $result;
            $moved = $m['rewrite'] !== $result['cursor']; $m['rewrite'] = $result['cursor'];
            if ( $result['done'] ) { $m['stage'] = 'guid'; $m['after'] = 0; }
            self::save( $job, $moved );
            return self::result( $job );
        }
        if ( $m['stage'] === 'guid' || $m['stage'] === 'cleanup' ) {
            $kind = $m['stage'];
            if ( ! isset( $m['finalize'][ $kind ] ) ) {
                // Counted once per stage (and at the first step after an
                // upgrade mid-stage) so the card can show done/total and an ETA.
                $done = ISXM_Migration_Store::count( $job->run_id, $kind, $m['after'] );
                $m['finalize'][ $kind ] = [ 'total' => ISXM_Migration_Store::count( $job->run_id, $kind ), 'done' => $done, 'from' => $done, 'since' => time(), 'eta' => 0 ];
            }
            $rows = ISXM_Migration_Store::rows( $job->run_id, $kind, $m['after'] );
            $handled = 0;
            foreach ( $rows as $row ) {
                // The pause/cancel check is a DB read: every 10 rows, not every row.
                if ( $handled % 10 === 0 && self::stop( $job, $deadline ) ) break;
                $data = json_decode( $row->payload, true );
                if ( $kind === 'guid' ) {
                    global $wpdb;
                    $guid = $wpdb->get_var( $wpdb->prepare( "SELECT guid FROM {$wpdb->posts} WHERE ID=%d", $data['id'] ) );
                    if ( $wpdb->last_error ) return new WP_Error( 'isxs_guid_read', 'Could not read attachment GUID.' );
                    $pairs = [];
                    foreach ( ISXM_Migration_Store::url_map( $job->run_id, (string) $guid ) as $old => $new ) $pairs[] = [ 'old' => $old, 'new' => $new ];
                    $result = ISXM_DB_Rewriter::update_attachment_guid( $data['id'], $pairs );
                    // One attachment's guid is not worth stopping the run for:
                    // files and content are already migrated by this stage.
                    if ( is_wp_error( $result ) ) {
                        $job->errors[] = '#' . $data['id'] . ': ' . $result->get_error_message();
                        $job->errors = array_slice( $job->errors, -ISXM_Job::MAX_ERRORS ); $job->error_count++;
                    }
                } elseif ( ISXM_Settings::get( 'remove_local' ) && ( $data['covered'] || ISXM_Settings::get( 'deliver_enabled' ) ) ) {
                    ISXM_Background::renew_runner_lock();
                    // Preserve files replaced by an editor while this long job ran.
                    if ( is_file( $data['path'] ) && hash_file( 'sha256', $data['path'] ) === $data['hash'] ) ISXM_Offload::delete_local_files( [ $data['path'] ] );
                }
                // One checkpoint per chunk instead of per row (hundreds of
                // thousands of option writes). Replaying a chunk is safe: a
                // written guid is no longer an old URL, a deleted file is gone.
                $m['after'] = (int) $row->id; $handled++;
            }
            $f =& $m['finalize'][ $kind ];
            $f['done'] = min( $f['total'], $f['done'] + $handled );
            $elapsed = time() - $f['since'];
            $f['eta'] = ( $elapsed > 0 && $f['done'] > $f['from'] ) ? (int) round( ( $f['total'] - $f['done'] ) * $elapsed / ( $f['done'] - $f['from'] ) ) : 0;
            unset( $f );
            if ( $handled === count( $rows ) && count( $rows ) < 100 ) { $m['stage'] = $kind === 'guid' ? 'cleanup' : 'complete'; $m['after'] = 0; }
            self::save( $job, $handled > 0 );
        }
        return self::result( $job );
    }

    private static function transfer( ISXM_Job $job, $id, array $keys ) {
        if ( ISXM_Offload::is_protected_download( $id ) ) return new WP_Error( 'isxs_protected_download', 'Protected download retained.' );
        $s = ISXM_Settings::all(); $info = ISXM_Offload::get_record( $id );
        $same = is_array( $info ) && ( $info['bucket'] ?? '' ) === $s['bucket'] && ( $info['endpoint'] ?? '' ) === $s['endpoint'];
        $verified = [];
        foreach ( array_unique( array_values( $keys ) ) as $name ) {
            if ( $same && in_array( $name, $info['files'] ?? [], true ) ) {
                $probe = $info; $probe['files'] = [ $name ];
                if ( ISXM_Sync::primary_exists_on_destination( $probe ) ) $verified[] = $name;
            }
        }
        if ( count( $verified ) !== count( array_unique( array_values( $keys ) ) ) ) {
            $result = ISXM_Migrate::migrate_staged( $id, $keys, $job->run_id );
            if ( is_wp_error( $result ) ) return $result;
            $info = ISXM_Offload::get_record( $id );
        }
        $pairs = ISXM_Offload::collect_url_pairs( $id, ISXM_Migrate::extra_old_dirs( $id ) );
        // Real source keys may include an object-version directory.
        if ( $s['persist_urls'] && ISXM_Settings::source_public_base_url() !== '' ) {
            foreach ( $keys as $key => $name ) if ( in_array( $name, $info['files'] ?? [], true ) ) {
                $pairs[] = [ 'old' => trailingslashit( ISXM_Settings::source_public_base_url() ) . $key, 'new' => ISXM_Offload::build_remote_url( $info, $name ) ];
            }
        }
        $primary = wp_basename( ISXM_Offload::local_path( $id ) ); $guid = []; $urls = []; $replays = [];
        foreach ( $pairs as $pair ) {
            if ( strpos( $pair['old'], 'http' ) !== 0 || strpos( $pair['old'], '\\/' ) !== false ) continue;
            $urls[ $pair['old'] ] = $pair;
            $replays[ $pair['new'] ] = [ 'old' => $pair['new'], 'new' => $pair['new'] ];
            if ( wp_basename( $pair['old'] ) === $primary ) $guid[] = $pair;
        }
        ISXM_Migration_Store::put_many( $job->run_id, 'url', $urls );
        ISXM_Migration_Store::put_many( $job->run_id, 'replay', $replays );
        // GUID is resolved with the run-wide map at this attachment's final stage.
        if ( $guid ) ISXM_Migration_Store::put( $job->run_id, 'guid', $id, [ 'id' => $id ] );
        if ( $s['remove_local'] && ( $s['persist_urls'] || $s['deliver_enabled'] ) ) {
            $dir = trailingslashit( dirname( ISXM_Offload::local_path( $id ) ) );
            foreach ( $keys as $name ) {
                $path = $dir . ISXM_Offload::safe_filename( $name );
                if ( ! in_array( $name, $info['files'] ?? [], true ) || ! is_file( $path ) ) continue;
                ISXM_Migration_Store::put( $job->run_id, 'cleanup', $path, [ 'path' => $path, 'hash' => hash_file( 'sha256', $path ), 'covered' => (bool) $pairs ] );
            }
        }
        ISXM_Offload::clear_pending_deletions();
        return true;
    }

    /** Settings that decide the keys and URLs this run writes. */
    private static function settings_snapshot() {
        $s = ISXM_Settings::all();
        $keys = [ 'provider', 'endpoint', 'bucket', 'region', 'path_style', 'use_prefix', 'prefix', 'use_year_month', 'use_object_version',
            'use_type_folder', 'persist_urls', 'cdn_domain', 'force_https',
            'source_endpoint', 'source_bucket', 'source_prefix', 'source_use_year_month', 'source_public_base_url' ];
        if ( ! empty( $s['use_type_folder'] ) ) {
            $keys = array_merge( $keys, [ 'product_folder', 'download_folder', 'post_folder', 'promotion_folder', 'category_folder', 'brand_folder' ] );
        }
        $out = [];
        foreach ( $keys as $key ) $out[ $key ] = $s[ $key ] ?? null;
        return $out;
    }

    /**
     * A run must finish with the destination and URL layout it started with,
     * or the URLs already written and the ones still to come disagree. Only
     * those settings count — toggling Asset URLs or rotating an access key
     * used to stop the run too — and a stop names what changed.
     *
     * @return WP_Error|null
     */
    private static function settings_changed( ISXM_Job $job ) {
        $m   =& $job->migration;
        $now = self::settings_snapshot();
        if ( isset( $m['settings'] ) ) {
            $changed = [];
            foreach ( $now + $m['settings'] as $key => $unused ) {
                $was = $m['settings'][ $key ] ?? null; $is = $now[ $key ] ?? null;
                if ( $was !== $is ) $changed[] = sprintf( '%s "%s" -> "%s"', $key, is_scalar( $was ) ? (string) $was : '', is_scalar( $is ) ? (string) $is : '' );
            }
            if ( $changed ) {
                return new WP_Error( 'isxs_migration_settings', 'Settings that decide this run\'s file keys or URLs changed since it started: ' . implode( '; ', $changed ) . '. Restore them to resume, or cancel and start a new run.' );
            }
            return null;
        }
        if ( isset( $m['configuration'] ) ) {
            // Started by v0.2.18 or older, which hashed every setting — secrets
            // and unrelated toggles included. Accept the run when that hash
            // still matches, or when the last migrated item's URLs come out
            // exactly as this run recorded them.
            $all = ISXM_Settings::all();
            unset( $all['remove_local'], $all['deliver_enabled'], $all['enabled'] );
            if ( hash( 'sha256', wp_json_encode( $all ) ) !== $m['configuration'] && ! self::urls_still_match( $job ) ) {
                return new WP_Error( 'isxs_migration_settings', 'The destination or URL settings changed since this run started (an older version started it, so the exact setting cannot be named). Restore them to resume, or cancel and start a new run.' );
            }
            unset( $m['configuration'] );
        }
        $m['settings'] = $now;
        return null;
    }

    private static function urls_still_match( ISXM_Job $job ) {
        $id   = (int) ( $job->activity['attachment'] ?? 0 );
        $info = $id ? ISXM_Offload::get_record( $id ) : null;
        $s    = ISXM_Settings::all();
        if ( ! is_array( $info ) || ( $info['bucket'] ?? '' ) !== $s['bucket'] || ( $info['endpoint'] ?? '' ) !== $s['endpoint'] ) return false;
        foreach ( ISXM_Offload::collect_url_pairs( $id ) as $pair ) {
            $stored = ISXM_Migration_Store::url_map( $job->run_id, $pair['old'] );
            if ( isset( $stored[ $pair['old'] ] ) && $stored[ $pair['old'] ] !== $pair['new'] ) return false;
        }
        return true;
    }

    private static function stop( $job, $deadline ) {
        return microtime( true ) >= $deadline || ISXM_Background::memory_exceeded() || ISXM_Job::signal( $job->tool ) !== '';
    }
    private static function save( $job, $progress = true ) {
        $job->activity['stage'] = $job->migration['stage'];
        $job->activity['finalize'] = $job->migration['finalize'][ $job->migration['stage'] ] ?? null;
        $job->activity['heartbeat'] = microtime( true );
        if ( $progress ) { $job->activity['last_progress'] = microtime( true ); $job->activity['restarts'] = 0; }
        $job->phase = in_array( $job->migration['stage'], [ 'rewrite', 'guid', 'cleanup' ], true ) ? 'rewriting' : $job->migration['stage'];
        ISXM_Migrate_Queue::checkpoint( $job );
    }
    private static function result( $job ) {
        return [ 'processed' => 0, 'cursor' => $job->cursor, 'done' => $job->migration['stage'] === 'complete', 'errors' => [], 'phase' => $job->phase, 'checkpointed' => true ];
    }
}

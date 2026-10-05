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
        $settings = ISXM_Settings::all();
        unset( $settings['remove_local'], $settings['deliver_enabled'], $settings['enabled'] );
        $fingerprint = hash( 'sha256', wp_json_encode( $settings ) );
        if ( isset( $m['configuration'] ) && $m['configuration'] !== $fingerprint ) return new WP_Error( 'isxs_migration_settings', 'Migration connection or URL settings changed. Restore the original settings before resuming.' );
        $m['configuration'] = $fingerprint;
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
            $rows = ISXM_Migration_Store::rows( $job->run_id, $kind, $m['after'] );
            foreach ( $rows as $row ) {
                if ( self::stop( $job, $deadline ) ) return self::result( $job );
                $data = json_decode( $row->payload, true );
                if ( $kind === 'guid' ) {
                    global $wpdb;
                    $guid = $wpdb->get_var( $wpdb->prepare( "SELECT guid FROM {$wpdb->posts} WHERE ID=%d", $data['id'] ) );
                    if ( $wpdb->last_error ) return new WP_Error( 'isxs_guid_read', 'Could not read attachment GUID.' );
                    $pairs = [];
                    foreach ( ISXM_Migration_Store::url_map( $job->run_id, (string) $guid ) as $old => $new ) $pairs[] = [ 'old' => $old, 'new' => $new ];
                    $result = ISXM_DB_Rewriter::update_attachment_guid( $data['id'], $pairs );
                    if ( is_wp_error( $result ) ) return $result;
                } elseif ( ISXM_Settings::get( 'remove_local' ) && ( $data['covered'] || ISXM_Settings::get( 'deliver_enabled' ) ) ) {
                    ISXM_Background::renew_runner_lock();
                    // Preserve files replaced by an editor while this long job ran.
                    if ( is_file( $data['path'] ) && hash_file( 'sha256', $data['path'] ) === $data['hash'] ) ISXM_Offload::delete_local_files( [ $data['path'] ] );
                }
                $m['after'] = (int) $row->id; self::save( $job );
            }
            if ( count( $rows ) < 100 ) { $m['stage'] = $kind === 'guid' ? 'cleanup' : 'complete'; $m['after'] = 0; self::save( $job ); }
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

    private static function stop( $job, $deadline ) {
        return microtime( true ) >= $deadline || ISXM_Background::memory_exceeded() || ISXM_Job::signal( $job->tool ) !== '';
    }
    private static function save( $job, $progress = true ) {
        $job->activity['stage'] = $job->migration['stage'];
        $job->activity['heartbeat'] = microtime( true );
        if ( $progress ) { $job->activity['last_progress'] = microtime( true ); $job->activity['restarts'] = 0; }
        $job->phase = in_array( $job->migration['stage'], [ 'rewrite', 'guid', 'cleanup' ], true ) ? 'rewriting' : $job->migration['stage'];
        ISXM_Migrate_Queue::checkpoint( $job );
    }
    private static function result( $job ) {
        return [ 'processed' => 0, 'cursor' => $job->cursor, 'done' => $job->migration['stage'] === 'complete', 'errors' => [], 'phase' => $job->phase, 'checkpointed' => true ];
    }
}

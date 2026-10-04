<?php
/** Resumable source migration. The job option is the atomic checkpoint. */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Migrate_Queue {
    /** Run one bounded slice. Counts are committed here, never again by the runner. */
    public static function step( ISXM_Job $job, $budget = 15 ) {
        if ( ( $job->migration['stage'] ?? '' ) === 'complete' ) return self::result( $job, true );
        if ( function_exists( 'set_time_limit' ) ) @set_time_limit( ISXM_Tools::MAX_REQUEST_SECONDS );
        $started = microtime( true );
        $deadline = $started + $budget;
        if ( empty( $job->migration ) ) {
            // Accept the v0.2.12 cursor, including its mid-page offset.
            $parts = explode( '|', (string) $job->cursor, 3 );
            $token = $parts[1] ?? '';
            $offset = max( 0, (int) ( $parts[2] ?? 0 ) );
            self::activity( $job, 'listing' );
            self::checkpoint( $job );
            $page = ISXM_Migrate::scan_source_batch( $token );
            if ( is_wp_error( $page ) ) return $page;
            if ( ! $page['done'] && $page['next_token'] === $token ) {
                return new WP_Error( 'isxs_source_cursor', 'Source listing did not advance; checkpoint retained.' );
            }
            $items = [];
            $matched = 0;
            foreach ( $page['items'] as $id => $keys ) {
                $items[] = [ 'id' => $id, 'keys' => $keys ];
                $matched += count( $keys );
            }
            $job->migration = [
                'stage' => 'transfer', 'items' => $items, 'index' => $offset, 'batch_count' => 0,
                'next' => $page['next_token'], 'done' => $page['done'],
                'unmatched' => max( 0, $page['scanned_keys'] - $matched ),
                'pairs' => [], 'deletions' => [], 'rewrite' => [ 'table' => 0, 'after' => 0 ],
            ];
            $job->batch_progress = 0;
            self::checkpoint( $job );
        }

        if ( $job->migration['stage'] === 'transfer' ) {
            $handled = (int) ( $job->migration['batch_count'] ?? 0 );
            $settings = ISXM_Settings::all();
            ISXM_Tools::prime_caches( array_column( $job->migration['items'], 'id' ) );
            while ( $job->migration['index'] < count( $job->migration['items'] ) ) {
                if ( microtime( true ) >= $deadline || ISXM_Background::memory_exceeded()
                    || ISXM_Job::signal( $job->tool ) !== '' ) break;
                $entry = $job->migration['items'][ $job->migration['index'] ];
                $id = $entry['id'];
                self::activity( $job, 'transfer', $id );
                self::checkpoint( $job );
                $info = ISXM_Offload::get_record( $id );
                $exists = is_array( $info ) && ( $info['bucket'] ?? '' ) === $settings['bucket']
                    && ( $info['endpoint'] ?? '' ) === $settings['endpoint']
                    && ISXM_Sync::primary_exists_on_destination( $info );
                $result = $exists ? true : ISXM_Migrate::migrate_attachment( $id, $entry['keys'], true );
                // A lost worker must not save progress or release another worker's lease.
                ISXM_Background::renew_runner_lock();
                ISXM_Offload::clear_pending_deletions();
                if ( is_wp_error( $result ) ) {
                    $job->errors[] = sprintf( '#%d %s: %s', $id, wp_basename( (string) get_attached_file( $id ) ), $result->get_error_message() );
                    $job->errors = array_slice( $job->errors, -ISXM_Job::MAX_ERRORS );
                    $job->error_count++;
                } else {
                    $pairs = ISXM_Offload::collect_url_pairs( $id, ISXM_Migrate::extra_old_dirs( $id ) );
                    // Keep GUID work in the same durable queue as the content rewrite.
                    $job->migration['pairs'][ $id ] = $pairs;
                    $info = ISXM_Offload::get_record( $id );
                    if ( $settings['remove_local'] && ( $settings['persist_urls'] || $settings['deliver_enabled'] ) ) {
                        $dir = trailingslashit( dirname( ISXM_Offload::local_path( $id ) ) );
                        foreach ( $info['files'] ?? [] as $filename ) {
                            $filename = ISXM_Offload::safe_filename( $filename );
                            if ( $filename === '' || ! file_exists( $dir . $filename ) ) continue;
                            // Recover deletions after a previous upload crashed,
                            // but do not delete a last local derivative just
                            // because the ORIGINAL exists on the destination.
                            $file_info = $info;
                            $file_info['files'] = [ $filename ];
                            if ( $exists && ! ISXM_Sync::primary_exists_on_destination( $file_info ) ) continue;
                            // Remember whether THIS item's URLs are covered
                            // by the queued rewrite. Settings may change
                            // while the job is paused between phases.
                            $job->migration['deletions'][ $dir . $filename ] = ! empty( $pairs );
                        }
                    }
                }
                $job->processed += count( $entry['keys'] );
                $job->migration['index']++;
                $job->activity['last_progress'] = microtime( true );
                $job->activity['restarts'] = 0;
                $job->migration['batch_count'] = ++$handled;
                if ( $handled >= ISXM_Tools::MAX_BATCH ) {
                    $job->migration['stage'] = 'rewrite';
                    $job->phase = 'rewriting';
                    self::activity( $job, 'rewrite' );
                }
                self::checkpoint( $job );
                if ( $handled >= ISXM_Tools::MAX_BATCH ) break;
            }
            // Close this transfer batch even on the already-on-destination path.
            if ( $handled > 0 || $job->migration['index'] >= count( $job->migration['items'] ) ) {
                $job->migration['stage'] = 'rewrite';
                $job->phase = 'rewriting';
                self::activity( $job, 'rewrite' );
                self::checkpoint( $job );
            }
            return self::result( $job );
        }

        if ( $job->migration['stage'] === 'rewrite' ) {
            $pairs = [];
            foreach ( $job->migration['pairs'] as $id => $item_pairs ) {
                $pairs = array_merge( $pairs, $item_pairs );
            }
            if ( $pairs ) {
                $result = ISXM_DB_Rewriter::replace_urls_window( $pairs, $job->migration['rewrite'], $deadline );
                if ( is_wp_error( $result ) ) return $result;
                $job->migration['rewrite'] = $result['cursor'];
                $job->activity['last_progress'] = microtime( true );
                self::checkpoint( $job );
                if ( ! $result['done'] ) return self::result( $job );
                foreach ( $job->migration['pairs'] as $id => $item_pairs ) {
                    ISXM_Background::renew_runner_lock();
                    $written = ISXM_DB_Rewriter::update_attachment_guid( $id, $item_pairs );
                    if ( is_wp_error( $written ) ) return $written;
                }
            }
            $job->migration['stage'] = 'finalize';
            self::activity( $job, 'finalize' );
            self::checkpoint( $job );
            return self::result( $job );
        }

        // Replaying finalize is safe: file deletion is idempotent, counts and
        // cursor advance in the SAME write that clears the page checkpoint.
        ISXM_Background::renew_runner_lock();
        if ( ISXM_Settings::get( 'remove_local' ) ) {
            $paths = [];
            $delivery = ISXM_Settings::get( 'deliver_enabled' );
            foreach ( $job->migration['deletions'] as $path => $rewritten ) {
                if ( $rewritten || $delivery ) $paths[] = $path;
            }
            ISXM_Offload::delete_local_files( $paths );
        }
        $done = false;
        if ( $job->migration['index'] >= count( $job->migration['items'] ) ) {
            $job->processed += $job->migration['unmatched'];
            $job->cursor = 'run|' . $job->migration['next'];
            $done = $job->migration['done'];
            // Persist exhaustion too: a crash before the outer runner calls
            // finish() must never interpret run| as the first page again.
            $job->migration = $done ? [ 'stage' => 'complete' ] : [];
        } else {
            $job->migration['stage'] = 'transfer';
            $job->migration['pairs'] = [];
            $job->migration['deletions'] = [];
            $job->migration['batch_count'] = 0;
            $job->migration['rewrite'] = [ 'table' => 0, 'after' => 0 ];
        }
        $job->phase = '';
        $job->activity['last_progress'] = microtime( true );
        self::activity( $job, $done ? 'complete' : 'transfer' );
        self::checkpoint( $job );
        return self::result( $job, $done );
    }

    private static function activity( ISXM_Job $job, $stage, $id = 0 ) {
        $job->activity['stage'] = $stage;
        if ( $id ) $job->activity['attachment'] = $id;
    }

    private static function checkpoint( ISXM_Job $job ) {
        ISXM_Background::renew_runner_lock();
        $current = ISXM_Job::get( $job->tool );
        if ( ! $current || $current->run_id !== $job->run_id || $current->state !== ISXM_Job::STATE_RUNNING ) {
            throw new ISXM_Lease_Lost( 'Migration was cancelled or replaced.' );
        }
        $job->batch_progress = 0;
        if ( ! $job->save() ) throw new RuntimeException( 'Migration checkpoint could not be saved.' );
        $stored = ISXM_Job::get( $job->tool );
        if ( ! $stored || $stored->to_array() !== $job->to_array() ) {
            throw new RuntimeException( 'Migration checkpoint write failed; local files were retained.' );
        }
    }

    private static function result( ISXM_Job $job, $done = false ) {
        return [ 'processed' => 0, 'cursor' => $job->cursor, 'done' => $done,
            'errors' => [], 'phase' => $job->phase, 'checkpointed' => true ];
    }
}

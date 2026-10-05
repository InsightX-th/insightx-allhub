<?php
/** Indexed attachment paths and durable, run-scoped migration work. */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Migration_Store {
    const VERSION_OPTION = 'isxs_migration_schema';

    public static function table( $kind ) {
        global $wpdb;
        return $wpdb->prefix . ( $kind === 'paths' ? 'isxs_migration_paths' : 'isxs_migration_work' );
    }

    public static function install() {
        if ( (int) get_option( self::VERSION_OPTION ) === 1 ) return;
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $paths = self::table( 'paths' ); $work = self::table( 'work' );
        $charset = $wpdb->get_charset_collate();
        dbDelta( "CREATE TABLE {$paths} (
            attachment_id bigint(20) unsigned NOT NULL,
            path_hash char(64) NOT NULL,
            path longtext NOT NULL,
            PRIMARY KEY  (attachment_id),
            KEY path_lookup (path_hash,attachment_id)
        ) {$charset};" );
        dbDelta( "CREATE TABLE {$work} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(64) NOT NULL,
            kind varchar(12) NOT NULL,
            item_hash char(64) NOT NULL,
            payload longtext NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY identity (run_id,kind,item_hash),
            KEY pending (run_id,kind,id)
        ) {$charset};" );
        foreach ( [ $paths, $work ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                throw new RuntimeException( 'Could not create migration tables. Check database CREATE privileges.' );
            }
        }
        update_option( self::VERSION_OPTION, 1, false );
    }

    private static function check( $result ) {
        global $wpdb;
        if ( $result === false || $wpdb->last_error ) throw new RuntimeException( 'Migration ledger database operation failed; checkpoint retained.' );
        return $result;
    }

    public static function index_path( $id, $value ) {
        global $wpdb;
        $path = ISXM_Offload::normalize_relative_path( $value );
        if ( $path === null ) { self::delete_attachment( $id ); return; }
        $table = self::table( 'paths' );
        self::check( $wpdb->query( $wpdb->prepare(
            "REPLACE INTO {$table} (attachment_id,path_hash,path) VALUES (%d,%s,%s)", $id, hash( 'sha256', $path ), $path
        ) ) );
    }

    public static function delete_attachment( $id ) {
        if ( (int) get_option( self::VERSION_OPTION ) !== 1 ) return;
        global $wpdb;
        self::check( $wpdb->delete( self::table( 'paths' ), [ 'attachment_id' => $id ] ) );
    }

    public static function meta_changed( $meta_id, $id, $key, $value ) {
        if ( $key !== '_wp_attached_file' || (int) get_option( self::VERSION_OPTION ) !== 1 ) return;
        // Read the surviving value too when one of several meta rows was deleted.
        try { self::index_path( $id, get_post_meta( $id, $key, true ) ); }
        catch ( Throwable $e ) {
            delete_option( 'isxs_migration_index_ready' );
            isxm_log_error( $e->getMessage() );
        }
    }

    /** Bulk form of index_path(); later rows for the same attachment win. */
    public static function index_paths( array $rows ) {
        global $wpdb;
        $paths = [];
        foreach ( $rows as $row ) $paths[ (int) $row->post_id ] = ISXM_Offload::normalize_relative_path( $row->meta_value );
        $values = [];
        foreach ( $paths as $id => $path ) {
            if ( $path === null ) { self::delete_attachment( $id ); continue; }
            $values[] = $wpdb->prepare( '(%d,%s,%s)', $id, hash( 'sha256', $path ), $path );
        }
        $table = self::table( 'paths' );
        foreach ( array_chunk( $values, 500 ) as $chunk ) {
            self::check( $wpdb->query( "REPLACE INTO {$table} (attachment_id,path_hash,path) VALUES " . implode( ',', $chunk ) ) );
        }
    }

    public static function index_window( $after, $limit = 2000 ) {
        global $wpdb;
        // Served by core's meta_key index, whose InnoDB entries end with the
        // meta_id primary key, so the window stays ordered without a table scan.
        $rows = self::check( $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_id,post_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_id > %d ORDER BY meta_id LIMIT %d", $after, $limit
        ) ) );
        foreach ( $rows as $row ) $after = (int) $row->meta_id;
        return [ 'rows' => $rows, 'after' => $after, 'done' => count( $rows ) < $limit ];
    }

    public static function resolve( array $paths ) {
        if ( ! $paths ) return [];
        global $wpdb;
        $table = self::table( 'paths' );
        $hashes = array_map( function ( $p ) { return hash( 'sha256', $p ); }, $paths );
        $marks = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
        $rows = self::check( $wpdb->get_results( $wpdb->prepare(
            "SELECT attachment_id,path FROM {$table} WHERE path_hash IN ({$marks}) ORDER BY attachment_id", $hashes
        ) ) );
        $wanted = array_fill_keys( $paths, true ); $map = [];
        foreach ( $rows as $row ) {
            if ( isset( $wanted[ $row->path ] ) && ! isset( $map[ $row->path ] ) ) $map[ $row->path ] = (int) $row->attachment_id;
        }
        return $map;
    }

    /** Idempotent intent write, verified before the job checkpoint can advance. */
    public static function put( $run, $kind, $key, array $data ) {
        self::put_many( $run, $kind, [ $key => $data ] );
    }

    public static function put_many( $run, $kind, array $entries ) {
        global $wpdb;
        $table = self::table( 'work' );
        foreach ( array_chunk( $entries, 100, true ) as $chunk ) {
            $values = []; $expected = [];
            foreach ( $chunk as $key => $data ) {
                $hash = hash( 'sha256', (string) $key ); $payload = wp_json_encode( $data );
                if ( ! is_string( $payload ) ) throw new RuntimeException( 'Could not encode migration work.' );
                $expected[ $hash ] = $payload;
                $values[] = $wpdb->prepare( '(%s,%s,%s,%s)', $run, $kind, $hash, $payload );
            }
            self::check( $wpdb->query( "INSERT IGNORE INTO {$table} (run_id,kind,item_hash,payload) VALUES " . implode( ',', $values ) ) );
            $marks = implode( ',', array_fill( 0, count( $expected ), '%s' ) );
            $rows = self::check( $wpdb->get_results( $wpdb->prepare(
                "SELECT item_hash,payload FROM {$table} WHERE run_id=%s AND kind=%s AND item_hash IN ({$marks})",
                array_merge( [ $run, $kind ], array_keys( $expected ) )
            ) ) );
            foreach ( $rows as $row ) {
                if ( $row->payload !== $expected[ $row->item_hash ] ) throw new RuntimeException( 'Conflicting migration mapping; checkpoint retained.' );
                unset( $expected[ $row->item_hash ] );
            }
            if ( $expected ) throw new RuntimeException( 'Migration intent write failed; checkpoint retained.' );
        }
    }

    public static function rows( $run, $kind, $after = 0, $limit = 100 ) {
        global $wpdb;
        $table = self::table( 'work' );
        return self::check( $wpdb->get_results( $wpdb->prepare(
            "SELECT id,payload FROM {$table} WHERE run_id=%s AND kind=%s AND id>%d ORDER BY id LIMIT %d", $run, $kind, $after, $limit
        ) ) );
    }

    /** Per-row URL lookup. The full run's map never enters PHP memory. */
    public static function url_map( $run, $content ) {
        global $wpdb;
        $decoded = str_replace( '\\/', '/', $content );
        $decoded = preg_replace_callback( '/\\\\u[0-9a-fA-F]{4}(?:\\\\u[0-9a-fA-F]{4})?/', function ( $m ) {
            $v = json_decode( '"' . $m[0] . '"' ); return is_string( $v ) ? $v : $m[0];
        }, $decoded );
        preg_match_all( '~https?://(?:(?!https?://)[^"\'<>\\\\\r\n])+~u', $decoded, $matches );
        $candidates = [];
        foreach ( $matches[0] as $url ) {
            // Keep spaces and parentheses inside filenames. Try delimiter
            // boundaries as well as the full token; exact hash/path matching
            // disambiguates prose, query strings, CSS and srcset separators.
            $candidates[ $url ] = true;
            preg_match_all( '/[\s?#),;\]}]/u', $url, $ends, PREG_OFFSET_CAPTURE );
            foreach ( $ends[0] as $end ) $candidates[ substr( $url, 0, $end[1] ) ] = true;
        }
        $map = []; $table = self::table( 'work' );
        foreach ( array_chunk( array_keys( $candidates ), 100 ) as $chunk ) {
            $hashes = array_map( function ( $url ) { return hash( 'sha256', $url ); }, $chunk );
            $marks = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
            $rows = self::check( $wpdb->get_results( $wpdb->prepare(
                "SELECT payload FROM {$table} WHERE run_id=%s AND kind IN ('url','replay') AND item_hash IN ({$marks})", array_merge( [ $run ], $hashes )
            ) ) );
            foreach ( $rows as $row ) {
                $pair = json_decode( $row->payload, true );
                if ( ! isset( $candidates[ $pair['old'] ] ) ) continue;
                foreach ( ISXM_Offload::with_escaped_pairs( [ $pair ] ) as $p ) $map[ $p['old'] ] = $p['new'];
                $encoded = substr( wp_json_encode( $pair['old'] ), 1, -1 );
                $map[ $encoded ] = substr( wp_json_encode( $pair['new'] ), 1, -1 );
            }
        }
        uksort( $map, function ( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
        return $map;
    }
}

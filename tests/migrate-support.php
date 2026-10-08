<?php
/** Real bounded SQL windows in SQLite, WordPress/storage boundaries faked. */
if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );
class ISXM_Migration_Test_DB extends ISXM_Test_WPDB {
    public $db;
    public $queries = 0;
    public $fail_read = false;
    public $fail_write = false;
    public $fail_guid = false;
    public $max_rows = 0;
    public function __construct() {
        $this->db = new PDO( 'sqlite::memory:' );
        $this->db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
        $this->db->exec( 'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_content TEXT, guid TEXT)' );
        $this->db->exec( 'CREATE TABLE wp_postmeta (meta_id INTEGER PRIMARY KEY, post_id INTEGER, meta_key TEXT, meta_value TEXT)' );
        $this->db->exec( 'CREATE TABLE wp_termmeta (meta_id INTEGER PRIMARY KEY, term_id INTEGER, meta_key TEXT, meta_value TEXT)' );
        $this->db->exec( 'CREATE TABLE wp_usermeta (umeta_id INTEGER PRIMARY KEY, user_id INTEGER, meta_key TEXT, meta_value TEXT)' );
        $this->db->exec( 'CREATE INDEX attached_files ON wp_postmeta(meta_key,meta_value)' );
        $this->db->exec( 'CREATE TABLE wp_isxs_migration_paths (attachment_id INTEGER PRIMARY KEY, path_hash TEXT, path TEXT)' );
        $this->db->exec( 'CREATE INDEX path_lookup ON wp_isxs_migration_paths(path_hash,attachment_id)' );
        $this->db->exec( 'CREATE TABLE wp_isxs_migration_work (id INTEGER PRIMARY KEY AUTOINCREMENT,run_id TEXT,kind TEXT,item_hash TEXT,payload TEXT,UNIQUE(run_id,kind,item_hash))' );
        $this->db->exec( 'CREATE INDEX pending ON wp_isxs_migration_work(run_id,kind,id)' );
        update_option( 'isxs_migration_schema', 1 );
        $this->db->exec( 'CREATE TABLE wp_options (option_id INTEGER PRIMARY KEY, option_name TEXT UNIQUE, option_value TEXT, autoload TEXT)' );
    }
    public function prepare( $sql, ...$args ) {
        if ( isset( $args[0] ) && is_array( $args[0] ) ) $args = $args[0];
        return preg_replace_callback( '/%[sdf]/', function ( $m ) use ( &$args ) {
            $v = array_shift( $args );
            return $m[0] === '%s' ? $this->db->quote( (string) $v ) : (string) (int) $v;
        }, $sql );
    }
    public function get_results( $sql = '', $output = 'OBJECT' ) {
        $this->queries++;
        $this->last_error = '';
        if ( $this->fail_read ) { $this->last_error = 'injected read failure'; return null; }
        // MySQL's LIKE treats esc_like()'s backslash as the escape character;
        // SQLite only does with an explicit ESCAPE clause.
        $sql  = preg_replace( "/LIKE ('(?:[^']|'')*')(?! ESCAPE)/", "LIKE $1 ESCAPE '\\\\'", $sql );
        $rows = $this->db->query( $sql )->fetchAll( $output === ARRAY_A ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ );
        $this->max_rows = max( $this->max_rows, count( $rows ) );
        return $rows;
    }
    public function get_var( $sql ) {
        $this->last_error = '';
        if ( preg_match( "/^SHOW TABLES LIKE '(.+)'$/", $sql, $m ) ) {
            $name = stripslashes( $m[1] );
            return $this->db->query( 'SELECT name FROM sqlite_master WHERE type = ' . $this->db->quote( 'table' ) . ' AND name = ' . $this->db->quote( $name ) )->fetchColumn() ?: null;
        }
        if ( strpos( $sql, 'SHOW ' ) === 0 ) return null;
        $value = $this->db->query( $sql )->fetchColumn();
        return $value === false ? null : $value;
    }
    public function get_col( $sql = "" ) {
        $this->queries++;
        $this->last_error = '';
        $sql = preg_replace( "/LIKE ('(?:[^']|'')*')(?! ESCAPE)/", "LIKE $1 ESCAPE '\\\\'", $sql ); // as in get_results()
        return $this->db->query( $sql )->fetchAll( PDO::FETCH_COLUMN );
    }
    public function get_row( $sql, $output = 'OBJECT' ) {
        $rows = $this->get_results( $sql, $output );
        return $rows ? $rows[0] : null;
    }
    /** Regex: matching statements fail like a MySQL error would. */
    public $fail_query = '';
    public function query( $sql ) {
        $this->last_error = '';
        if ( $this->fail_query !== '' && preg_match( $this->fail_query, $sql ) ) { $this->last_error = 'injected query failure'; return false; }
        return $this->db->exec( str_replace( 'INSERT IGNORE', 'INSERT OR IGNORE', $sql ) );
    }
    public function update( $table, $data, $where ) {
        $this->last_error = '';
        if ( $this->fail_write || ( $this->fail_guid && isset( $data['guid'] ) ) ) { $this->last_error = 'injected write failure'; return false; }
        $set = []; $conditions = [];
        foreach ( $data as $k => $v ) $set[] = $k . '=' . $this->db->quote( (string) $v );
        foreach ( $where as $k => $v ) $conditions[] = $k . '=' . $this->db->quote( (string) $v );
        return $this->db->exec( 'UPDATE ' . $table . ' SET ' . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $conditions ) );
    }
    public function insert( $table, $data ) {
        $this->last_error = ''; $this->queries++;
        $values = array_map( function ( $v ) { return $v === null ? 'NULL' : $this->db->quote( (string) $v ); }, $data );
        return $this->db->exec( 'INSERT INTO ' . $table . ' (' . implode( ',', array_keys( $data ) ) . ') VALUES (' . implode( ',', $values ) . ')' );
    }
    public function delete( $table, $where ) {
        $conditions = [];
        foreach ( $where as $k => $v ) $conditions[] = $k . '=' . $this->db->quote( (string) $v );
        return $this->db->exec( 'DELETE FROM ' . $table . ' WHERE ' . implode( ' AND ', $conditions ) );
    }
}
function clean_post_cache( $id ) {
    $GLOBALS['isxm_t']['purged_posts'][] = $id;
    // Core cache invalidation may query the post and clear the last SQL error.
    $GLOBALS['wpdb']->last_error = '';
}
function wp_remote_post( $url, $args ) { return wp_remote_request( $url, $args ); }
function admin_url( $path ) { return 'https://example.com/wp-admin/' . $path; }
// Recording cron stubs: tests can assert what got (un)scheduled.
function wp_next_scheduled( $hook ) { return $GLOBALS['isxm_t']['cron'][ $hook ] ?? false; }
function wp_schedule_event( $ts = 0, $recurrence = '', $hook = '' ) { if ( $hook !== '' ) $GLOBALS['isxm_t']['cron'][ $hook ] = $ts; return true; }
function wp_clear_scheduled_hook( $hook = '' ) { unset( $GLOBALS['isxm_t']['cron'][ $hook ] ); }

function migration_fixture( $count = 3, $persist = true ) {
    isxm_test_reset();
    ISXM_Offload::clear_pending_deletions();
    $GLOBALS['wpdb'] = new ISXM_Migration_Test_DB();
    isxm_test_configure( [], [ 'source_provider' => 'custom', 'source_prefix' => '', 'source_use_year_month' => false,
        'use_prefix' => false, 'use_year_month' => false, 'persist_urls' => $persist, 'remove_local' => true, 'deliver_enabled' => true ] );
    for ( $id = 1; $id <= $count; $id++ ) {
        isxm_test_add_post( $id, [] );
        update_post_meta( $id, '_wp_attached_file', 'm' . $id . '.jpg' );
        update_post_meta( $id, ISXM_Offload::META_KEY, [ 'bucket' => 'media', 'endpoint' => 'https://s3.example.test',
            'base_key' => '', 'files' => [ 'm' . $id . '.jpg' ] ] );
        $GLOBALS['wpdb']->db->exec( "INSERT INTO wp_postmeta(post_id,meta_key,meta_value) VALUES ($id,'_wp_attached_file','m$id.jpg')" );
    }
    $GLOBALS['isxm_t']['http_provider'] = function ( $url ) use ( $count ) {
        parse_str( parse_url( $url, PHP_URL_QUERY ) ?? '', $q );
        $keys = isset( $q['prefix'] ) && $q['prefix'] !== '' ? [ $q['prefix'] ] : array_map( function ( $id ) { return 'm' . $id . '.jpg'; }, range( 1, $count ) );
        return migration_listing( $keys );
    };
    return ISXM_Job::start( 'migrate', $count, 'run|' );
}
function migration_listing( array $keys, $next = '', array $modified = [] ) {
    $xml = '<ListBucketResult><IsTruncated>' . ( $next === '' ? 'false' : 'true' ) . '</IsTruncated>';
    foreach ( $keys as $key ) $xml .= '<Contents><Key>' . htmlspecialchars( $key, ENT_XML1 ) . '</Key>' . ( isset( $modified[ $key ] ) ? '<LastModified>' . gmdate( 'Y-m-d\\TH:i:s.000\\Z', $modified[ $key ] ) . '</LastModified>' : '' ) . '</Contents>';
    if ( $next !== '' ) $xml .= '<NextContinuationToken>' . htmlspecialchars( $next, ENT_XML1 ) . '</NextContinuationToken>';
    return [ 'response' => [ 'code' => 200 ], 'body' => $xml . '</ListBucketResult>' ];
}
function migration_step( $budget = 15 ) { return ISXM_Migrate_Queue::legacy_step( ISXM_Job::get( 'migrate' ), $budget ); }
function migration_drain( $max = 100 ) {
    for ( $i = 0; $i < $max; $i++ ) {
        $result = migration_step();
        if ( is_wp_error( $result ) || $result['done'] ) return $result;
    }
    throw new RuntimeException( 'Migration did not settle.' );
}

/** The offload ledger (ISXM_Items) in the SQLite test DB, marked installed. */
function isxm_items_table( ISXM_Migration_Test_DB $db ) {
    $db->db->exec( 'CREATE TABLE IF NOT EXISTS wp_isxs_items (id INTEGER PRIMARY KEY AUTOINCREMENT, source_type TEXT, source_id INTEGER, provider TEXT, bucket TEXT, endpoint TEXT, region TEXT, path_style INTEGER, base_key TEXT, object_version TEXT, origin TEXT, files TEXT, missing TEXT, is_partial INTEGER, updated_at TEXT, UNIQUE(source_type,source_id))' );
    update_option( ISXM_Items::DB_VERSION_OPTION, ISXM_Items::DB_VERSION );
}

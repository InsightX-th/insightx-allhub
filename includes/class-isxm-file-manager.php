<?php
/**
 * Every file operation of the File Manager page, sandboxed to one root
 * (the WordPress directory). No HTML and no request handling here — the
 * admin class turns requests into calls, so this is what the tests cover.
 *
 * Paths in and out are relative to the root, "/"-separated, no leading
 * slash ("" is the root itself).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_File_Manager {

    /** Visible, but nothing in them may be created, changed or removed: they are the site's code. */
    const READ_ONLY_DIRS = [ 'wp-admin/', 'wp-includes/', 'wp-content/plugins/', 'wp-content/themes/', 'wp-content/mu-plugins/' ];

    const READ_ONLY_FILES = [ 'wp-config.php' ];

    /**
     * Extensions a web server may execute. Any dot-separated part of a name
     * counts ("x.php.pdf" runs as PHP on some Apache setups).
     */
    const EXECUTABLE = [ 'php', 'php3', 'php4', 'php5', 'php6', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'shtml', 'cgi', 'pl', 'py', 'sh', 'asp', 'aspx', 'jsp', 'htaccess', 'ini' ];

    /** Pages a browser runs script from; only for users trusted with unfiltered HTML. */
    const MARKUP = [ 'html', 'htm', 'xhtml', 'shtm', 'svg', 'svgz', 'xml', 'xsl' ];

    private $root;
    private $jail;
    private $read_only;
    private $allow_markup;

    /**
     * @param string    $root      The WordPress folder; every path in and out is relative to it.
     * @param bool|null $read_only Defaults to DISALLOW_FILE_MODS.
     * @param string    $jail      Folder (relative to $root) this user may not leave. "" = everything.
     * @param bool      $allow_markup May .html / .svg … be created (the unfiltered_html capability).
     */
    public function __construct( $root = ABSPATH, $read_only = null, $jail = '', $allow_markup = true ) {
        $this->allow_markup = (bool) $allow_markup;
        $this->root      = rtrim( (string) realpath( $root ), '/' ) . '/';
        $this->read_only = $read_only ?? ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );
        $this->jail      = $this->root;
        $jail            = $this->normalize( $jail );
        if ( $jail !== '' ) {
            $real = is_wp_error( $jail ) ? false : realpath( $this->root . $jail );
            // A jail that is gone or outside the root locks everything out
            // rather than falling back to the whole site.
            $this->jail = ( $real && is_dir( $real ) && strpos( $real . '/', $this->root ) === 0 ) ? $real . '/' : "\0";
        }
    }

    public function read_only() {
        return $this->read_only;
    }

    /**
     * Administrators: delete or empty folders even when dot-files are inside
     * (they go with the folder). Site code stays protected either way.
     */
    private $force_hidden = false;
    public function force_hidden( $on = true ) {
        $this->force_hidden = (bool) $on;
        return $this;
    }

    /** The folder this user starts in and cannot leave, root-relative. */
    public function jail() {
        return $this->jail === "\0" ? '' : $this->relative( rtrim( $this->jail, '/' ) );
    }

    /**
     * Cleans a relative path: no null bytes, no "." or ".." parts, no
     * leading slash.
     *
     * @return string|WP_Error
     */
    public function normalize( $relative ) {
        $relative = str_replace( '\\', '/', (string) $relative );
        if ( strpos( $relative, "\0" ) !== false ) return $this->error( 'bad_path' );
        $parts = array_filter( explode( '/', $relative ), 'strlen' );
        foreach ( $parts as $part ) {
            if ( $part === '.' || $part === '..' ) return $this->error( 'bad_path' );
        }
        return implode( '/', $parts );
    }

    /**
     * Absolute path of an existing file or folder, symlinks followed, that
     * must still be inside the root.
     *
     * @return string|WP_Error
     */
    public function resolve( $relative ) {
        $relative = $this->normalize( $relative );
        if ( is_wp_error( $relative ) ) return $relative;
        $real = realpath( $this->root . $relative );
        if ( $real === false ) return $this->error( 'not_found' );
        if ( $real . '/' !== $this->jail && strpos( $real, $this->jail ) !== 0 ) return $this->error( 'bad_path' );
        return $real;
    }

    /**
     * Absolute path of an item itself (a symlink is the link, not its
     * target), with its parent folder resolved inside the root. Every change
     * goes through this, so deleting a link never deletes what it points at.
     *
     * @return string|WP_Error
     */
    private function item( $relative, $must_exist = true ) {
        $relative = $this->normalize( $relative );
        if ( is_wp_error( $relative ) ) return $relative;
        if ( $relative === '' ) return $this->error( 'bad_path' );
        $parent = $this->resolve( dirname( $relative ) === '.' ? '' : dirname( $relative ) );
        if ( is_wp_error( $parent ) ) return $parent;
        $path = rtrim( $parent, '/' ) . '/' . basename( $relative );
        if ( $must_exist && ! file_exists( $path ) && ! is_link( $path ) ) return $this->error( 'not_found' );
        return $path;
    }

    /** Root-relative form of an absolute path inside the root. */
    private function relative( $absolute ) {
        return ltrim( substr( $absolute, strlen( rtrim( $this->root, '/' ) ) ), '/' );
    }

    /**
     * A file or folder name that may be created: one path part, no hidden
     * names, nothing executable.
     *
     * @return true|WP_Error
     */
    public function valid_name( $name ) {
        $name = (string) $name;
        if ( $name === '' || strlen( $name ) > 255 || preg_match( '#[/\\\\\x00-\x1f\x7f]#', $name ) ) return $this->error( 'bad_name' );
        if ( $name[0] === '.' ) return $this->error( 'hidden_name' );
        $parts = array_slice( explode( '.', strtolower( $name ) ), 1 );
        if ( array_intersect( $parts, self::EXECUTABLE ) ) return $this->error( 'executable' );
        if ( ! $this->allow_markup && array_intersect( $parts, self::MARKUP ) ) return $this->error( 'markup' );
        return true;
    }

    /**
     * May something at this absolute path be created, changed or removed?
     *
     * @return true|WP_Error
     */
    public function writable( $absolute ) {
        if ( $this->read_only ) return $this->error( 'read_only' );
        $relative = $this->relative( $absolute );
        if ( $relative === '' || in_array( $relative, self::READ_ONLY_FILES, true ) ) return $this->error( 'protected' );
        foreach ( self::READ_ONLY_DIRS as $dir ) {
            if ( strpos( $relative . '/', $dir ) === 0 || strpos( $dir, $relative . '/' ) === 0 ) return $this->error( 'protected' );
        }
        return true;
    }

    /**
     * May this existing item be renamed, moved or deleted? Writable, and not
     * a script (index.php, wp-login.php … wherever they are).
     *
     * @return true|WP_Error
     */
    public function changeable( $absolute ) {
        $ok = $this->writable( $absolute );
        if ( is_wp_error( $ok ) ) return $ok;
        $ok = $this->readable( $absolute );
        if ( is_wp_error( $ok ) ) return $ok;
        if ( rtrim( $absolute, '/' ) . '/' === $this->jail ) return $this->error( 'protected' );
        // Scripts and dot-files (.htaccess, .user.ini …) are the site's own:
        // never renamed, moved or deleted from here.
        $name = $this->valid_name( basename( $absolute ) );
        if ( is_wp_error( $name ) && in_array( $name->get_error_code(), [ 'isxfm_executable', 'isxfm_hidden_name' ], true ) ) return $this->error( 'protected' );
        return true;
    }

    /** Never shown, read or downloaded: credentials, backups, logs. */
    const SECRET_EXT = [ 'sql', 'sqlite', 'sqlite3', 'db', 'bak', 'log', 'key', 'pem', 'p12', 'pfx' ];

    /** Folder names (any part of them) that hold backups: UpdraftPlus, BackWPup, WPvivid, Duplicator, All-in-One WP Migration … */
    const SECRET_DIRS = [ 'backup', 'updraft', 'backwpup', 'wpvivid', 'duplicator', 'ai1wm' ];

    /**
     * May this path be shown, read or downloaded? Not wp-config*, nothing
     * hidden (.env, .git/, .htaccess), no database dumps or keys, nothing
     * inside a backup folder (wp-content/updraft/, ai1wm-backups/,
     * insightx-backup/ … any folder named *backup*).
     *
     * @return true|WP_Error
     */
    public function readable( $absolute ) {
        // Callers pass resolved paths; anything else is resolved through its
        // parent first so the root prefix always matches.
        if ( strpos( $absolute, rtrim( $this->root, '/' ) ) !== 0 ) {
            $parent = realpath( dirname( $absolute ) );
            if ( $parent === false ) return $this->error( 'not_found' );
            $absolute = $parent . '/' . basename( $absolute );
        }
        $relative = strtolower( $this->relative( $absolute ) );
        if ( $relative === '' ) return true;
        $parts = explode( '/', $relative );
        $name  = end( $parts );
        $backup_dir = function ( $part ) {
            foreach ( self::SECRET_DIRS as $word ) {
                if ( strpos( $part, $word ) !== false ) return true;
            }
            return false;
        };
        foreach ( $parts as $i => $part ) {
            if ( $part !== '' && $part[0] === '.' ) return $this->error( 'secret' );
            // Folder names only: a file called "backup-guide.pdf" is fine.
            if ( $i < count( $parts ) - 1 && $backup_dir( $part ) ) return $this->error( 'secret' );
        }
        if ( is_dir( $absolute ) && $backup_dir( $name ) ) return $this->error( 'secret' );
        if ( strpos( $name, 'wp-config' ) === 0 ) return $this->error( 'secret' );
        foreach ( array_slice( explode( '.', $name ), 1 ) as $ext ) {
            if ( in_array( $ext, self::SECRET_EXT, true ) ) return $this->error( 'secret' );
        }
        return true;
    }

    /**
     * Folders first, then files, natural order; hidden names left out.
     *
     * @return array|WP_Error {path, writable, dirs: [{name, path, mtime}], files: [{name, path, size, mtime}]}
     */
    public function listing( $relative ) {
        $dir = $this->resolve( $relative );
        if ( is_wp_error( $dir ) ) return $dir;
        if ( ! is_dir( $dir ) ) return $this->error( 'not_dir' );
        $ok = $this->readable( $dir );
        if ( is_wp_error( $ok ) ) return $ok;
        $base  = $this->relative( $dir );
        $dirs  = [];
        $files = [];
        foreach ( (array) scandir( $dir ) as $name ) {
            if ( $name === '' || $name[0] === '.' ) continue;
            $path  = $dir . '/' . $name;
            if ( $this->readable( $path ) !== true ) continue;
            $entry = $this->entry( $path, $name );
            if ( isset( $entry['size'] ) ) $files[] = $entry; else $dirs[] = $entry;
        }
        $by_name = function ( $a, $b ) { return strnatcasecmp( $a['name'], $b['name'] ); };
        usort( $dirs, $by_name );
        usort( $files, $by_name );
        return [ 'path' => $base, 'writable' => $this->writable( $dir . '/x' ) === true, 'dirs' => $dirs, 'files' => $files ];
    }

    /**
     * Total size of each folder (everything below it; symlinks are not
     * followed). Stops starting new folders after $seconds, but always
     * finishes at least one, so repeated calls always make progress.
     *
     * @param string[] $relatives
     * @return array{sizes: array<string,int>, pending: string[]}
     */
    public function dir_sizes( array $relatives, $seconds = 5.0 ) {
        $stop  = microtime( true ) + $seconds;
        $sizes = [];
        $pending = [];
        foreach ( array_values( array_unique( array_map( 'strval', $relatives ) ) ) as $i => $relative ) {
            if ( $i > 0 && microtime( true ) > $stop ) { $pending[] = $relative; continue; }
            $dir = $this->resolve( $relative );
            if ( is_wp_error( $dir ) || ! is_dir( $dir ) || $this->readable( $dir ) !== true ) continue;
            $total = 0;
            $iter  = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::LEAVES_ONLY,
                RecursiveIteratorIterator::CATCH_GET_CHILD
            );
            foreach ( $iter as $item ) {
                if ( ! $item->isLink() && $item->isFile() ) $total += (int) $item->getSize();
            }
            $sizes[ $this->relative( $dir ) ] = $total;
        }
        return [ 'sizes' => $sizes, 'pending' => $pending ];
    }

    /** One row of a listing or of search results; folders have no size. */
    private function entry( $path, $name ) {
        $entry = [ 'name' => $name, 'path' => $this->relative( $path ), 'mtime' => (int) @filemtime( $path ), 'writable' => $this->changeable( $path ) === true ];
        if ( ! is_dir( $path ) ) $entry['size'] = (int) @filesize( $path );
        return $entry;
    }

    /**
     * Names containing $query (any case, any script) in a folder and every
     * folder below it. Hidden names are skipped and symlinked folders are
     * not entered. Stops at $limit hits or after $seconds.
     *
     * @return array|WP_Error {path, query, items, truncated}
     */
    public function search( $relative, $query, $limit = 500, $seconds = 5.0 ) {
        $dir = $this->resolve( $relative );
        if ( is_wp_error( $dir ) ) return $dir;
        if ( ! is_dir( $dir ) ) return $this->error( 'not_dir' );
        $query = trim( (string) $query );
        $items = [];
        $truncated = false;
        if ( $query !== '' ) {
            $stop = microtime( true ) + $seconds;
            $iter = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
                function ( $item ) { return strpos( $item->getFilename(), '.' ) !== 0 && ( ! $item->isDir() || $this->readable( $item->getPathname() ) === true ); }
            ), RecursiveIteratorIterator::SELF_FIRST, RecursiveIteratorIterator::CATCH_GET_CHILD );
            foreach ( $iter as $item ) {
                if ( count( $items ) >= $limit || microtime( true ) > $stop ) { $truncated = true; break; }
                if ( mb_stripos( $item->getFilename(), $query, 0, 'UTF-8' ) === false ) continue;
                if ( $this->readable( $item->getPathname() ) !== true ) continue;
                $items[] = $this->entry( $item->getPathname(), $item->getFilename() );
            }
        }
        return [ 'path' => $this->relative( $dir ), 'query' => $query, 'items' => $items, 'truncated' => $truncated ];
    }

    /** An empty new file. @return array|WP_Error {path, notes} */
    public function make_file( $parent, $name ) {
        $target = $this->target( $parent, $name );
        if ( is_wp_error( $target ) ) return $target;
        if ( file_exists( $target ) || is_link( $target ) ) return $this->error( 'exists' );
        if ( @file_put_contents( $target, '' ) === false ) return $this->error( 'failed' );
        @chmod( $target, 0644 );
        $relative = $this->relative( $target );
        return [ 'path' => $relative, 'notes' => $this->written( [ $relative ] ) ];
    }

    /** @return string|WP_Error New folder's path. */
    public function make_dir( $parent, $name ) {
        $target = $this->target( $parent, $name );
        if ( is_wp_error( $target ) ) return $target;
        if ( file_exists( $target ) ) return $this->error( 'exists' );
        if ( ! @mkdir( $target, 0755 ) ) return $this->error( 'failed' );
        return $this->relative( $target );
    }

    /**
     * Stores an uploaded file. $mover is move_uploaded_file() outside tests.
     *
     * @return array|WP_Error {path, notes}
     */
    public function upload( $parent, $name, $tmp, $replace = false, $mover = null ) {
        $target = $this->target( $parent, $name );
        if ( is_wp_error( $target ) ) return $target;
        if ( is_dir( $target ) ) return $this->error( 'exists' );
        if ( file_exists( $target ) && ! $replace ) return $this->error( 'exists' );
        $mover = $mover ?? function ( $from, $to ) { return is_uploaded_file( $from ) && move_uploaded_file( $from, $to ); };
        if ( ! $mover( $tmp, $target ) ) return $this->error( 'failed' );
        @chmod( $target, 0644 );
        clearstatcache( true, $target );
        $relative = $this->relative( $target );
        return [ 'path' => $relative, 'notes' => $this->written( [ $relative ] ) ];
    }

    /** @return array|WP_Error {path, notes} */
    public function rename( $relative, $name ) {
        $from = $this->item( $relative );
        if ( is_wp_error( $from ) ) return $from;
        $ok = $this->changeable( $from );
        if ( is_wp_error( $ok ) ) return $ok;
        return $this->relocate( $from, $this->target( $this->relative( dirname( $from ) ), $name ) );
    }

    /** @return array|WP_Error {path, notes} */
    public function move( $relative, $parent ) {
        $from = $this->item( $relative );
        if ( is_wp_error( $from ) ) return $from;
        $ok = $this->changeable( $from );
        if ( is_wp_error( $ok ) ) return $ok;
        $to = $this->target( $parent, basename( $from ) );
        if ( ! is_wp_error( $to ) && strpos( $to . '/', $from . '/' ) === 0 ) return $this->error( 'into_itself' );
        return $this->relocate( $from, $to );
    }

    /** @return array|WP_Error {notes} */
    public function delete( $relative ) {
        $path = $this->item( $relative );
        if ( is_wp_error( $path ) ) return $path;
        $ok = $this->changeable( $path );
        if ( is_wp_error( $ok ) ) return $ok;
        $guard = $this->force_hidden ? '' : $this->holds_hidden( $path );
        if ( $guard !== '' ) return $this->guarded( $guard );
        $files = $this->files_in( $path );
        if ( ! $this->remove( $path ) ) return $this->error( 'failed' );
        return [ 'notes' => $this->removed( $files ) ];
    }

    /**
     * Copies a file or a whole folder into folder $parent, under $name
     * (default: its own name). A folder holding anything that could not be
     * created by hand (scripts, hidden names, links) is refused whole, so a
     * copy never makes a new .php file.
     *
     * @return array|WP_Error {path, notes}
     */
    public function copy( $relative, $parent, $name = null ) {
        $from = $this->item( $relative );
        if ( is_wp_error( $from ) ) return $from;
        $to = $this->target( $parent, $name ?? basename( $from ) );
        if ( is_wp_error( $to ) ) return $to;
        if ( file_exists( $to ) || is_link( $to ) ) return $this->error( 'exists' );
        if ( strpos( $to . '/', rtrim( $from, '/' ) . '/' ) === 0 ) return $this->error( 'into_itself' );
        $ok = $this->copyable( $from );
        if ( is_wp_error( $ok ) ) return $ok;
        if ( ! $this->copy_tree( $from, $to ) ) {
            $this->remove( $to );
            return $this->error( 'failed' );
        }
        return [ 'path' => $this->relative( $to ), 'notes' => $this->written( $this->files_in( $to ) ) ];
    }

    /** A copy next to the original: "spec copy.pdf", "spec copy 2.pdf" … */
    public function duplicate( $relative ) {
        $from = $this->item( $relative );
        if ( is_wp_error( $from ) ) return $from;
        $name = basename( $from );
        $dot  = is_dir( $from ) ? false : strrpos( $name, '.' );
        $stem = $dot ? substr( $name, 0, $dot ) : $name;
        $ext  = $dot ? substr( $name, $dot ) : '';
        $dir  = dirname( $from );
        for ( $i = 1; $i < 100; $i++ ) {
            $try = $stem . ' copy' . ( $i > 1 ? ' ' . $i : '' ) . $ext;
            if ( ! file_exists( $dir . '/' . $try ) ) return $this->copy( $relative, $this->relative( $dir ), $try );
        }
        return $this->error( 'exists' );
    }

    /**
     * Deletes everything inside a folder, keeping the folder. Every item is
     * checked before anything is deleted: emptying wp-content/ must not
     * remove uploads/ and then stop at plugins/.
     *
     * @return true|WP_Error
     */
    public function empty_dir( $relative ) {
        $dir = $this->resolve( $relative );
        if ( is_wp_error( $dir ) ) return $dir;
        if ( ! is_dir( $dir ) ) return $this->error( 'not_dir' );
        $ok = $this->writable( $dir . '/x' );
        if ( is_wp_error( $ok ) ) return $ok;
        $items = array_values( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) );
        foreach ( $items as $name ) {
            if ( self::is_junk( $name ) ) continue; // .DS_Store & co. simply go
            if ( $name[0] === '.' ) {
                if ( $this->force_hidden ) continue;
                return $this->guarded( $this->relative( $dir . '/' . $name ) );
            }
            $ok = $this->changeable( $dir . '/' . $name );
            if ( is_wp_error( $ok ) ) return $ok;
            $guard = $this->force_hidden ? '' : $this->holds_hidden( $dir . '/' . $name );
            if ( $guard !== '' ) return $this->guarded( $guard );
        }
        $files = $this->files_in( $dir );
        foreach ( $items as $name ) {
            if ( ! $this->remove( $dir . '/' . $name ) ) return $this->error( 'failed' );
        }
        return [ 'notes' => $this->removed( $files ) ];
    }

    /** @return true|WP_Error Every name under $path could have been made here by hand. */
    private function copyable( $path ) {
        $ok = $this->valid_name( basename( $path ) );
        if ( is_wp_error( $ok ) ) return $ok;
        if ( is_link( $path ) ) return $this->error( 'bad_path' );
        if ( ! is_dir( $path ) ) return true;
        foreach ( (array) scandir( $path ) as $name ) {
            if ( $name === '.' || $name === '..' ) continue;
            $ok = $this->copyable( $path . '/' . $name );
            if ( is_wp_error( $ok ) ) return $ok;
        }
        return true;
    }

    private function copy_tree( $from, $to ) {
        if ( ! is_dir( $from ) ) return @copy( $from, $to ) && ( @chmod( $to, 0644 ) || true );
        if ( ! @mkdir( $to, 0755 ) ) return false;
        foreach ( (array) scandir( $from ) as $name ) {
            if ( $name === '.' || $name === '..' ) continue;
            if ( ! $this->copy_tree( $from . '/' . $name, $to . '/' . $name ) ) return false;
        }
        return true;
    }

    /**
     * Absolute path for a new $name inside folder $parent, both checked.
     *
     * @return string|WP_Error
     */
    private function target( $parent, $name ) {
        $ok = $this->valid_name( $name );
        if ( is_wp_error( $ok ) ) return $ok;
        $dir = $this->resolve( $parent );
        if ( is_wp_error( $dir ) ) return $dir;
        if ( ! is_dir( $dir ) ) return $this->error( 'not_dir' );
        $target = rtrim( $dir, '/' ) . '/' . $name;
        $ok     = $this->writable( $target );
        return is_wp_error( $ok ) ? $ok : $target;
    }

    private function relocate( $from, $to ) {
        if ( is_wp_error( $to ) ) return $to;
        if ( file_exists( $to ) || is_link( $to ) ) return $this->error( 'exists' );
        $old = $this->files_in( $from );
        if ( ! @rename( $from, $to ) ) return $this->error( 'failed' );
        // New names first (they go up), then the old ones are let go.
        $notes = $this->written( $this->files_in( $to ) );
        return [ 'path' => $this->relative( $to ), 'notes' => array_values( array_unique( array_merge( $notes, $this->removed( $old ) ) ) ) ];
    }

    /** Dot-files desktops leave behind: deleted along with their folder. */
    private static function is_junk( $name ) {
        return strtolower( $name ) === '.ds_store' || strpos( $name, '._' ) === 0;
    }

    /**
     * The first dot-file below a folder that matters (.htaccess guarding
     * uploads, .user.ini, .env …), root-relative, or "" when there is none.
     * Desktop junk (.DS_Store, ._*) does not count.
     */
    private function holds_hidden( $path ) {
        if ( is_link( $path ) || ! is_dir( $path ) ) return '';
        $iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
        foreach ( $iter as $item ) {
            $name = $item->getFilename();
            if ( strpos( $name, '.' ) === 0 && ! self::is_junk( $name ) ) return $this->relative( $item->getPathname() );
        }
        return '';
    }

    /** "Cannot delete: the system file x/.htaccess is inside" */
    private function guarded( $relative ) {
        /* translators: %s: path of a system file, e.g. wp-content/uploads/.htaccess */
        return new WP_Error( 'isxfm_guarded', sprintf( __( 'Cannot delete: the system file %s is inside this folder.', 'insightx-allhub' ), $relative ) );
    }

    /** Root-relative paths of a file, or of every file under a folder. */
    private function files_in( $path ) {
        if ( is_link( $path ) || ! is_dir( $path ) ) return [ $this->relative( $path ) ];
        $files = [];
        $iter  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ) );
        foreach ( $iter as $item ) {
            if ( $item->isFile() ) $files[] = $this->relative( $item->getPathname() );
        }
        sort( $files, SORT_STRING );
        return $files;
    }

    /**
     * Tells other plugins (InsightX AllHub) which files were just written;
     * whatever they return is shown to the user.
     *
     * @return string[]
     */
    private function written( array $files ) {
        $notes = [];
        foreach ( $files as $file ) {
            $notes = (array) apply_filters( 'isxfm_file_written', $notes, $file );
        }
        return array_values( array_unique( array_map( 'strval', $notes ) ) );
    }

    /**
     * Tells other plugins (InsightX AllHub) which files are gone from
     * these paths, so their bucket copies can go too.
     *
     * @return string[]
     */
    private function removed( array $files ) {
        $notes = [];
        foreach ( $files as $file ) {
            $notes = (array) apply_filters( 'isxfm_file_removed', $notes, $file );
        }
        return array_values( array_unique( array_map( 'strval', $notes ) ) );
    }

    private function remove( $path ) {
        if ( is_link( $path ) || ! is_dir( $path ) ) return @unlink( $path );
        foreach ( (array) scandir( $path ) as $name ) {
            if ( $name === '.' || $name === '..' ) continue;
            if ( ! $this->remove( $path . '/' . $name ) ) return false;
        }
        return @rmdir( $path );
    }

    private function error( $code ) {
        $messages = [
            'bad_path'    => __( 'That path is not allowed.', 'insightx-allhub' ),
            'not_found'   => __( 'File or folder not found.', 'insightx-allhub' ),
            'not_dir'     => __( 'Not a folder.', 'insightx-allhub' ),
            'bad_name'    => __( 'That name is not allowed.', 'insightx-allhub' ),
            'hidden_name' => __( 'Names starting with a dot are not allowed.', 'insightx-allhub' ),
            'executable'  => __( 'Executable files (such as .php) are not allowed.', 'insightx-allhub' ),
            'markup'      => __( 'HTML and SVG files can only be added by administrators.', 'insightx-allhub' ),
            'read_only'   => __( 'File changes are turned off on this site (DISALLOW_FILE_MODS).', 'insightx-allhub' ),
            'protected'   => __( 'This location holds the site\'s code and can only be viewed.', 'insightx-allhub' ),
            'secret'      => __( 'This file or folder is private to the server (configuration, backups, logs) and is not available here.', 'insightx-allhub' ),
            'exists'      => __( 'A file or folder with that name already exists.', 'insightx-allhub' ),
            'into_itself' => __( 'A folder cannot be moved into itself.', 'insightx-allhub' ),
            'failed'      => __( 'The server could not complete this. Check folder permissions.', 'insightx-allhub' ),
        ];
        return new WP_Error( 'isxfm_' . $code, $messages[ $code ] );
    }
}

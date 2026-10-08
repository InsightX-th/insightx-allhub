<?php
/**
 * Shrinks PDFs with Ghostscript. PHP cannot rewrite a PDF's images itself,
 * so this needs the `gs` binary on the server and exec() allowed.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Pdf {

    /** dpi => Ghostscript preset. */
    const PRESETS = [ 72 => '/screen', 150 => '/ebook', 300 => '/printer' ];

    /** A result must be at least this much smaller to replace the original. */
    const MIN_SAVING = 0.10;

    private static $gs = null;

    /** Path of the Ghostscript binary, or "" when it is missing or exec() is off. */
    public static function ghostscript() {
        if ( self::$gs !== null ) return self::$gs;
        self::$gs = '';
        if ( ! function_exists( 'exec' ) || in_array( 'exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
            return self::$gs;
        }
        foreach ( [ '/usr/bin/gs', '/usr/local/bin/gs', '/opt/homebrew/bin/gs' ] as $path ) {
            if ( @is_executable( $path ) ) return self::$gs = $path;
        }
        $out = [];
        @exec( 'command -v gs 2>/dev/null', $out );
        $found = trim( (string) ( $out[0] ?? '' ) );
        return self::$gs = ( $found !== '' && @is_executable( $found ) ) ? $found : '';
    }

    /** "10.02.1", or "" without Ghostscript. */
    public static function version() {
        $gs = self::ghostscript();
        if ( $gs === '' ) return '';
        $out = [];
        @exec( escapeshellarg( $gs ) . ' --version 2>/dev/null', $out );
        return trim( (string) ( $out[0] ?? '' ) );
    }

    /** Seconds one PDF may take before Ghostscript is stopped. */
    const TIMEOUT = 60;

    /** Path of coreutils `timeout`, or "" (then Ghostscript runs unbounded). */
    public static function timeout_bin() {
        foreach ( [ '/usr/bin/timeout', '/bin/timeout', '/usr/local/bin/timeout', '/opt/homebrew/bin/gtimeout' ] as $path ) {
            if ( @is_executable( $path ) ) return $path;
        }
        return '';
    }

    /** The Ghostscript command line that writes $in shrunk to $out. */
    public static function command( $gs, $in, $out, $dpi, $timeout = null ) {
        $preset  = self::PRESETS[ (int) $dpi ] ?? self::PRESETS[150];
        $timeout = $timeout ?? self::timeout_bin();
        // A PDF that makes Ghostscript loop must not hold a PHP worker forever.
        $prefix  = $timeout !== '' ? escapeshellarg( $timeout ) . ' -k 5 ' . self::TIMEOUT . ' ' : '';
        return $prefix . escapeshellarg( $gs ) . ' -q -dSAFER -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -dCompatibilityLevel=1.5'
            . ' -dPDFSETTINGS=' . $preset . ' -sOutputFile=' . escapeshellarg( $out ) . ' ' . escapeshellarg( $in ) . ' 2>&1';
    }

    /**
     * Shrinks one PDF in place. The original is only replaced by a valid
     * PDF at least MIN_SAVING smaller; otherwise it is left as it was.
     *
     * @param callable|null $runner fn(string $command): int exit code. exec() outside tests.
     * @return array|WP_Error {replaced: bool, before: int, after: int}
     */
    public static function compress( $path, $dpi = 150, $runner = null ) {
        $gs = $runner ? 'gs' : self::ghostscript();
        if ( $gs === '' ) return new WP_Error( 'isxs_no_ghostscript', __( 'Ghostscript (gs) is not installed on this server — ask your host to install it', 'insightx-offload' ) );
        $before = (int) @filesize( $path );
        if ( $before <= 0 ) return new WP_Error( 'isxs_pdf_missing', __( 'File not found', 'insightx-offload' ) );
        // Hidden name next to the original (same disk, so rename() works);
        // the folder scans skip dot-files, so a half-written one never goes up.
        $tmp    = dirname( $path ) . '/.isxs-' . md5( $path ) . '.pdf';
        $runner = $runner ?? function ( $cmd ) { $o = []; $code = 1; @exec( $cmd, $o, $code ); return (int) $code; };
        $code   = $runner( self::command( $gs, $path, $tmp, $dpi ) );
        clearstatcache( true, $tmp );
        $after = is_file( $tmp ) ? (int) filesize( $tmp ) : 0;
        $head  = $after > 0 ? (string) @file_get_contents( $tmp, false, null, 0, 5 ) : '';
        if ( $code !== 0 || $head !== '%PDF-' ) {
            @unlink( $tmp );
            return new WP_Error( 'isxs_pdf_failed', __( 'Ghostscript could not process this PDF', 'insightx-offload' ) );
        }
        if ( $after > $before * ( 1 - self::MIN_SAVING ) ) {
            @unlink( $tmp );
            return [ 'replaced' => false, 'before' => $before, 'after' => $before ];
        }
        if ( ! @rename( $tmp, $path ) ) {
            @unlink( $tmp );
            return new WP_Error( 'isxs_pdf_failed', __( 'Could not replace the file — check folder permissions', 'insightx-offload' ) );
        }
        @chmod( $path, 0644 );
        clearstatcache( true, $path );
        return [ 'replaced' => true, 'before' => $before, 'after' => $after ];
    }
}

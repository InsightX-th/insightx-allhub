<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Letter_Avatars — Every avatar becomes a coloured circle with the
 * person's first letter. Nothing is stored and Gravatar is never asked:
 * the picture is a small SVG this site serves itself from
 * /?isxs_avatar=<letter>&h=<hue> (pair with turning off avatar storage in
 * the social-login plugin). Stored profile pictures are left untouched and
 * come back if this is turned off.
 *
 * A real http(s) URL rather than a data: URI on purpose — get_avatar()
 * passes the URL through esc_url(), which drops the data: scheme and left
 * a broken image in 0.2.37.
 *
 * @since 0.2.37
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Letter_Avatars {

    /** Query var of the SVG endpoint. */
    const QUERY_VAR = 'isxs_avatar';

    public function __construct() {
        // Last word on the URL: runs after social-login / avatar plugins
        // that set their own (stored) picture in pre_get_avatar_data.
        add_filter( 'get_avatar_data', [ $this, 'filter_avatar_data' ], PHP_INT_MAX, 2 );
        // Served whether or not the toggle is on, so a URL already sitting
        // in a page cache keeps rendering after it is switched off.
        if ( isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- public, read-only image
            add_action( 'init', [ __CLASS__, 'serve' ], 0 );
        }
    }

    /**
     * @param array $args        get_avatar_data() arguments (size, url, …).
     * @param mixed $id_or_email User ID, WP_User, WP_Post, WP_Comment or email.
     * @return array
     */
    public function filter_avatar_data( $args, $id_or_email ) {
        if ( ! ISXM_Settings::get( 'letter_avatars' ) ) {
            return $args;
        }
        list( $name, $seed ) = self::identify( $id_or_email );
        $args['url']          = self::url( self::initial( $name ), self::hue( $seed ) );
        $args['found_avatar'] = true;
        return $args;
    }

    /**
     * The avatar URL: only the letter and a colour, no email or user id.
     */
    public static function url( $initial, $hue ) {
        return home_url( '/' ) . '?' . self::QUERY_VAR . '=' . rawurlencode( $initial ) . '&h=' . (int) $hue;
    }

    /** Same person, same colour. */
    public static function hue( $seed ) {
        return abs( crc32( (string) $seed ) ) % 360;
    }

    /**
     * Endpoint: send the SVG for ?isxs_avatar=<letter>&h=<hue> and stop.
     */
    public static function serve() {
        // phpcs:disable WordPress.Security.NonceVerification -- public, read-only image
        $initial = self::clean_initial( isset( $_GET[ self::QUERY_VAR ] ) ? wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) : '' );
        $hue     = self::clean_hue( isset( $_GET['h'] ) ? wp_unslash( (string) $_GET['h'] ) : '' );
        // phpcs:enable
        if ( ! headers_sent() ) {
            header( 'Content-Type: image/svg+xml; charset=utf-8' );
            header( 'Cache-Control: public, max-age=31536000, immutable' );
            header( 'X-Content-Type-Options: nosniff' );
        }
        echo self::svg( $initial, $hue ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from a validated letter, escaped in svg()
        exit;
    }

    /**
     * Only one letter or digit is ever drawn; anything else becomes "?".
     */
    public static function clean_initial( $value ) {
        $value = (string) $value;
        return ( strlen( $value ) <= 16 && preg_match( '/^[\p{L}\p{N}]\p{M}*$/u', $value ) ) ? $value : '?';
    }

    public static function clean_hue( $value ) {
        return ( is_numeric( $value ) && (int) $value >= 0 && (int) $value < 360 ) ? (int) $value : 0;
    }

    /**
     * The circle: a fixed template, the letter escaped.
     */
    public static function svg( $initial, $hue ) {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<circle cx="32" cy="32" r="32" fill="hsl(' . (int) $hue . ',55%,45%)"/>'
            . '<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#fff" font-size="28" font-weight="600"'
            . ' font-family="-apple-system,BlinkMacSystemFont,\'Segoe UI\',Tahoma,sans-serif">'
            . htmlspecialchars( (string) $initial, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</text></svg>';
    }

    /**
     * Display name and a stable colour seed for whatever get_avatar() got.
     *
     * @return array{0:string,1:string} [ name, seed ]
     */
    public static function identify( $id_or_email ) {
        $user = null; $email = ''; $name = '';
        if ( $id_or_email instanceof WP_Comment ) {
            $name  = (string) $id_or_email->comment_author;
            $email = (string) $id_or_email->comment_author_email;
            if ( ! empty( $id_or_email->user_id ) ) $user = get_userdata( (int) $id_or_email->user_id );
        } elseif ( $id_or_email instanceof WP_User ) {
            $user = $id_or_email;
        } elseif ( $id_or_email instanceof WP_Post ) {
            $user = get_userdata( (int) $id_or_email->post_author );
        } elseif ( is_numeric( $id_or_email ) ) {
            $user = get_userdata( (int) $id_or_email );
        } elseif ( is_string( $id_or_email ) ) {
            $email = $id_or_email;
            $user  = get_user_by( 'email', $id_or_email ) ?: null;
        }
        if ( $user ) {
            $name  = (string) ( $user->display_name !== '' ? $user->display_name : $user->user_login );
            $email = (string) $user->user_email;
        }
        if ( trim( $name ) === '' ) {
            $name = (string) strstr( $email . '@', '@', true );
        }
        return [ $name, strtolower( $email !== '' ? $email : $name ) ];
    }

    /**
     * First letter, upper-cased. Thai leading vowels (เ แ โ ใ ไ) are skipped
     * and vowel/tone marks dropped, so "เกศินี" shows ก and "นิดา" shows น —
     * a lone consonant reads cleanly in a small circle. "?" when nothing
     * usable is left.
     */
    public static function initial( $name ) {
        $name = trim( preg_replace( '/[^\p{L}\p{N}]+/u', ' ', (string) $name ) );
        $name = preg_replace( '/^[\x{0E40}-\x{0E44}]+/u', '', $name );
        if ( $name === '' || ! preg_match( '/^\X/u', $name, $m ) ) {
            return '?';
        }
        return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $m[0], 'UTF-8' ) : strtoupper( $m[0] );
    }
}

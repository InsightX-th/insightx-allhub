<?php
/**
 * Copyright (C) 2026 InsightX. GPLv3 or later. Original work by InsightX.
 *
 * ISXM_Letter_Avatars — Every avatar becomes a coloured circle with the
 * person's first letter, drawn as an inline SVG data URI. Nothing is stored
 * or fetched: no Gravatar request, no profile picture in the Media Library
 * (pair with turning off avatar storage in the social-login plugin). Stored
 * profile pictures are left untouched and come back if this is turned off.
 *
 * @since 0.2.37
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_Letter_Avatars {

    public function __construct() {
        // Last word on the URL: runs after social-login / avatar plugins
        // that set their own (stored) picture in pre_get_avatar_data.
        add_filter( 'get_avatar_data', [ $this, 'filter_avatar_data' ], PHP_INT_MAX, 2 );
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
        $args['url']          = self::svg_data_uri( $name, $seed );
        $args['found_avatar'] = true;
        return $args;
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
     * The circle: initial centred on a colour derived from the seed, so the
     * same person always gets the same colour.
     */
    public static function svg_data_uri( $name, $seed ) {
        $initial = self::initial( $name );
        $hue     = abs( crc32( $seed ) ) % 360;
        $svg     = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            . '<circle cx="32" cy="32" r="32" fill="hsl(' . $hue . ',55%,45%)"/>'
            . '<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#fff" font-size="28" font-weight="600"'
            . ' font-family="-apple-system,BlinkMacSystemFont,\'Segoe UI\',Tahoma,sans-serif">'
            . htmlspecialchars( $initial, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</text></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
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

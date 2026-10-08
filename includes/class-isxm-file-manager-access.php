<?php
/**
 * Who may do what on the File Manager page, and in which folder.
 *
 * Option `isxs_fm_access`:
 *   [ 'roles' => [ role => [ 'actions' => [...], 'root' => 'download/' ] ] ]
 *
 * Administrators (manage_options) may do everything everywhere. Everyone
 * else gets what their roles give. Without "view" nothing else counts.
 * (Per-person rules existed briefly in 0.2.45–0.2.51; any saved ones are
 * ignored.)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_File_Manager_Access {

    const OPTION  = 'isxs_fm_access';
    const ACTIONS = [ 'view', 'upload', 'mkdir', 'rename', 'move', 'download', 'delete' ];

    /** @return array{actions:string[],root:string} */
    public static function rules( $user = null ) {
        $user = $user ?: wp_get_current_user();
        if ( ! $user || ! $user->ID ) return [ 'actions' => [], 'root' => '' ];
        return self::resolve_rules( (array) $user->roles, user_can( $user, 'manage_options' ), self::config() );
    }

    public static function can( $action, $user = null ) {
        return in_array( $action, self::rules( $user )['actions'], true );
    }

    /**
     * The rules for one user, from their roles and the saved config.
     *
     * @return array{actions:string[],root:string}
     */
    public static function resolve_rules( array $roles, $is_admin, array $config ) {
        if ( $is_admin ) return [ 'actions' => self::ACTIONS, 'root' => '' ];
        $none = [ 'actions' => [], 'root' => '' ];
        $actions = [];
        $root    = null;
        foreach ( $roles as $role ) {
            $rule = $config['roles'][ $role ] ?? null;
            if ( ! $rule || ! in_array( 'view', $rule['actions'], true ) ) continue;
            $actions = array_merge( $actions, $rule['actions'] );
            if ( $root === null ) $root = $rule['root'];
        }
        if ( ! $actions ) return $none;
        // Back in ACTIONS order, each once.
        return [ 'actions' => array_values( array_intersect( self::ACTIONS, $actions ) ), 'root' => (string) $root ];
    }

    public static function config() {
        $config = get_option( self::OPTION, [] );
        return [ 'roles' => is_array( $config['roles'] ?? null ) ? $config['roles'] : [] ];
    }

    /**
     * Cleans what the settings tab sends. Unknown actions and roles are
     * dropped; a folder must exist inside the site or the row is dropped
     * (saving a typo must not hand out the whole site).
     *
     * @param array         $raw          {roles: {role: {actions, root}}}
     * @param string[]      $known_roles  Role slugs that exist (administrator excluded).
     * @param callable|null $folder_ok    fn(string $root): string|false  Normalized folder or false.
     */
    public static function sanitize( $raw, array $known_roles, $folder_ok = null ) {
        $folder_ok   = $folder_ok ?? function ( $root ) {
            $fs   = new ISXM_File_Manager();
            $norm = $fs->normalize( $root );
            if ( is_wp_error( $norm ) ) return false;
            if ( $norm === '' ) return '';
            $real = $fs->resolve( $norm );
            return ( ! is_wp_error( $real ) && is_dir( $real ) ) ? $norm . '/' : false;
        };
        $rule = function ( $r ) use ( $folder_ok ) {
            if ( ! is_array( $r ) ) return null;
            $actions = array_values( array_intersect( self::ACTIONS, array_map( 'strval', (array) ( $r['actions'] ?? [] ) ) ) );
            $root    = $folder_ok( (string) ( $r['root'] ?? '' ) );
            if ( $root === false ) return null;
            return [ 'actions' => $actions, 'root' => $root ];
        };
        $out = [ 'roles' => [] ];
        foreach ( (array) ( $raw['roles'] ?? [] ) as $role => $r ) {
            if ( ! in_array( (string) $role, $known_roles, true ) ) continue;
            $clean = $rule( $r );
            // A folder picked before any box is ticked is kept too.
            if ( $clean && ( $clean['actions'] || $clean['root'] !== '' ) ) $out['roles'][ (string) $role ] = $clean;
        }
        return $out;
    }
}

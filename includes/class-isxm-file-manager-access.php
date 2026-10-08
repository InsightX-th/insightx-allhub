<?php
/**
 * Who may do what on the File Manager page, and in which folder.
 *
 * Option `isxs_fm_access`:
 *   [ 'roles' => [ role => [ 'actions' => [...], 'root' => 'download/' ] ],
 *     'users' => [ user id => same shape ] ]
 *
 * Administrators (manage_options) may do everything everywhere. A user
 * entry replaces that user's role rules entirely. Without "view" nothing
 * else counts.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_File_Manager_Access {

    const OPTION  = 'isxs_fm_access';
    const ACTIONS = [ 'view', 'upload', 'mkdir', 'rename', 'move', 'download', 'delete' ];

    /** @return array{actions:string[],root:string} */
    public static function rules( $user = null ) {
        $user = $user ?: wp_get_current_user();
        if ( ! $user || ! $user->ID ) return [ 'actions' => [], 'root' => '' ];
        return self::resolve_rules( (array) $user->roles, (int) $user->ID, user_can( $user, 'manage_options' ), self::config() );
    }

    public static function can( $action, $user = null ) {
        return in_array( $action, self::rules( $user )['actions'], true );
    }

    /**
     * The rules for one user, from their roles, id and the saved config.
     *
     * @return array{actions:string[],root:string}
     */
    public static function resolve_rules( array $roles, $user_id, $is_admin, array $config ) {
        if ( $is_admin ) return [ 'actions' => self::ACTIONS, 'root' => '' ];
        $none = [ 'actions' => [], 'root' => '' ];
        if ( isset( $config['users'][ $user_id ] ) ) {
            $rule = $config['users'][ $user_id ];
            return in_array( 'view', $rule['actions'], true ) ? [ 'actions' => array_values( $rule['actions'] ), 'root' => $rule['root'] ] : $none;
        }
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
        return [
            'roles' => is_array( $config['roles'] ?? null ) ? $config['roles'] : [],
            'users' => is_array( $config['users'] ?? null ) ? $config['users'] : [],
        ];
    }

    /**
     * Cleans what the settings tab sends. Unknown actions, roles and users
     * are dropped; a folder must exist inside the site or the row is
     * dropped (saving a typo must not hand out the whole site).
     *
     * @param array         $raw          {roles: {role: {actions, root}}, users: {id: {...}}}
     * @param string[]      $known_roles  Role slugs that exist (administrator excluded).
     * @param callable|null $user_exists  fn(int $id): bool
     * @param callable|null $folder_ok    fn(string $root): string|false  Normalized folder or false.
     */
    public static function sanitize( $raw, array $known_roles, $user_exists = null, $folder_ok = null ) {
        $folder_ok   = $folder_ok ?? function ( $root ) {
            $fs   = new ISXM_File_Manager();
            $norm = $fs->normalize( $root );
            if ( is_wp_error( $norm ) ) return false;
            if ( $norm === '' ) return '';
            $real = $fs->resolve( $norm );
            return ( ! is_wp_error( $real ) && is_dir( $real ) ) ? $norm . '/' : false;
        };
        $user_exists = $user_exists ?? function ( $id ) {
            $user = get_userdata( $id );
            return $user && ! user_can( $user, 'manage_options' );
        };
        $rule = function ( $r ) use ( $folder_ok ) {
            if ( ! is_array( $r ) ) return null;
            $actions = array_values( array_intersect( self::ACTIONS, array_map( 'strval', (array) ( $r['actions'] ?? [] ) ) ) );
            $root    = $folder_ok( (string) ( $r['root'] ?? '' ) );
            if ( $root === false ) return null;
            return [ 'actions' => $actions, 'root' => $root ];
        };
        $out = [ 'roles' => [], 'users' => [] ];
        foreach ( (array) ( $raw['roles'] ?? [] ) as $role => $r ) {
            if ( ! in_array( (string) $role, $known_roles, true ) ) continue;
            $clean = $rule( $r );
            // A folder picked before any box is ticked is kept too.
            if ( $clean && ( $clean['actions'] || $clean['root'] !== '' ) ) $out['roles'][ (string) $role ] = $clean;
        }
        // A user row is kept even with no actions: it is how one person in
        // a role is shut out.
        foreach ( (array) ( $raw['users'] ?? [] ) as $id => $r ) {
            $id = (int) $id;
            if ( $id <= 0 || ! $user_exists( $id ) ) continue;
            $clean = $rule( $r );
            if ( $clean ) $out['users'][ $id ] = $clean;
        }
        return $out;
    }
}

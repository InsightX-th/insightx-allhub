<?php
require __DIR__ . '/file-manager-support.php';
require __DIR__ . '/../includes/class-isxm-file-manager-access.php';

$all = ISXM_File_Manager_Access::ACTIONS;
$config = [
    'roles' => [
        'editor'       => [ 'actions' => [ 'view', 'upload' ], 'root' => 'download/' ],
        'shop_manager' => [ 'actions' => [ 'view', 'delete', 'download' ], 'root' => 'files/' ],
        'author'       => [ 'actions' => [ 'upload' ], 'root' => '' ],
    ],
    // Left over from 0.2.45–0.2.51 (per-person rules): must be ignored.
    'users' => [ 7 => [ 'actions' => [ 'view', 'delete' ], 'root' => '' ] ],
];
$r = function ( $roles, $admin = false ) use ( $config ) { return ISXM_File_Manager_Access::resolve_rules( $roles, $admin, $config ); };

group( 'Who may do what' );
check( 'an administrator may do everything everywhere', $r( [ 'administrator' ], true ) === [ 'actions' => $all, 'root' => '' ] );
check( 'a role with no rules gets nothing', $r( [ 'subscriber' ] ) === [ 'actions' => [], 'root' => '' ] );
check( 'a role gets its own actions and folder', $r( [ 'editor' ] ) === [ 'actions' => [ 'view', 'upload' ], 'root' => 'download/' ] );
check( 'two roles add up; the first role\'s folder wins', $r( [ 'editor', 'shop_manager' ] ) === [ 'actions' => [ 'view', 'upload', 'download', 'delete' ], 'root' => 'download/' ] );
check( 'without "view" a role grants nothing', $r( [ 'author' ] ) === [ 'actions' => [], 'root' => '' ] );
check( 'old per-person rules are ignored', $r( [ 'subscriber' ] ) === [ 'actions' => [], 'root' => '' ] );

group( 'Saving cleans the input' );
$folders = [ '' => '', 'download' => 'download/', 'download/' => 'download/' ];
$folder_ok = function ( $root ) use ( $folders ) { return $folders[ $root ] ?? false; };
$clean = ISXM_File_Manager_Access::sanitize( [
    'roles' => [
        'editor'      => [ 'actions' => [ 'view', 'upload', 'hack' ], 'root' => 'download' ],
        'ghost_role'  => [ 'actions' => [ 'view' ], 'root' => '' ],
        'contributor' => [ 'actions' => [ 'view' ], 'root' => '../etc' ],
        'author'      => [ 'actions' => [], 'root' => '' ],
        'shop'        => [ 'actions' => [ 'delete', 'view' ], 'root' => '' ],
    ],
    'users' => [ '7' => [ 'actions' => [ 'view' ], 'root' => '' ] ],
], [ 'editor', 'contributor', 'author', 'shop' ], $folder_ok );
check( 'unknown actions are dropped, folder gets its slash', $clean['roles']['editor'] === [ 'actions' => [ 'view', 'upload' ], 'root' => 'download/' ] );
check( 'a role that does not exist is dropped', ! isset( $clean['roles']['ghost_role'] ) );
check( 'a folder that is not allowed drops the row', ! isset( $clean['roles']['contributor'] ) );
check( 'a role with no actions and no folder is not stored', ! isset( $clean['roles']['author'] ) );
$kept = ISXM_File_Manager_Access::sanitize( [ 'roles' => [ 'author' => [ 'actions' => [], 'root' => 'download' ] ] ], [ 'author' ], $folder_ok );
check( 'a folder picked before any box is ticked is kept (and grants nothing)', $kept['roles']['author'] === [ 'actions' => [], 'root' => 'download/' ] && ISXM_File_Manager_Access::resolve_rules( [ 'author' ], false, $kept )['actions'] === [] );
check( 'actions keep their fixed order', $clean['roles']['shop']['actions'] === [ 'view', 'delete' ] );
check( 'per-person rows are not stored', ! isset( $clean['users'] ) );
check( 'garbage input gives an empty config', ISXM_File_Manager_Access::sanitize( 'x', [ 'editor' ], $folder_ok ) === [ 'roles' => [] ] );

group( 'Folder check against the real disk' );
$root = fresh_root();
check( 'the default folder check accepts an existing folder', ISXM_File_Manager_Access::sanitize( [ 'roles' => [ 'editor' => [ 'actions' => [ 'view' ], 'root' => 'download/Specification' ] ] ], [ 'editor' ] )['roles']['editor']['root'] === 'download/Specification/' );
check( '…and rejects one that does not exist', ISXM_File_Manager_Access::sanitize( [ 'roles' => [ 'editor' => [ 'actions' => [ 'view' ], 'root' => 'nope' ] ] ], [ 'editor' ] )['roles'] === [] );
check( '…and rejects ../', ISXM_File_Manager_Access::sanitize( [ 'roles' => [ 'editor' => [ 'actions' => [ 'view' ], 'root' => '../' ] ] ], [ 'editor' ] )['roles'] === [] );

finish();

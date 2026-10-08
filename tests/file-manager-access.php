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
    'users' => [
        7 => [ 'actions' => [ 'view', 'rename' ], 'root' => 'download/Specification/' ],
        8 => [ 'actions' => [], 'root' => '' ],
    ],
];
$r = function ( $roles, $id = 1, $admin = false ) use ( $config ) { return ISXM_File_Manager_Access::resolve_rules( $roles, $id, $admin, $config ); };

group( 'Who may do what' );
check( 'an administrator may do everything everywhere', $r( [ 'administrator' ], 1, true ) === [ 'actions' => $all, 'root' => '' ] );
check( 'a role with no rules gets nothing', $r( [ 'subscriber' ] ) === [ 'actions' => [], 'root' => '' ] );
check( 'a role gets its own actions and folder', $r( [ 'editor' ] ) === [ 'actions' => [ 'view', 'upload' ], 'root' => 'download/' ] );
check( 'two roles add up; the first role\'s folder wins', $r( [ 'editor', 'shop_manager' ] ) === [ 'actions' => [ 'view', 'upload', 'download', 'delete' ], 'root' => 'download/' ] );
check( 'without "view" a role grants nothing', $r( [ 'author' ] ) === [ 'actions' => [], 'root' => '' ] );
check( 'a user entry replaces their role', $r( [ 'editor' ], 7 ) === [ 'actions' => [ 'view', 'rename' ], 'root' => 'download/Specification/' ] );
check( 'an empty user entry shuts one person out of their role', $r( [ 'editor' ], 8 ) === [ 'actions' => [], 'root' => '' ] );

group( 'Saving cleans the input' );
$folders = [ '' => '', 'download' => 'download/', 'download/' => 'download/' ];
$folder_ok = function ( $root ) use ( $folders ) { return $folders[ $root ] ?? false; };
$exists = function ( $id ) { return in_array( $id, [ 7, 8 ], true ); };
$clean = ISXM_File_Manager_Access::sanitize( [
    'roles' => [
        'editor'      => [ 'actions' => [ 'view', 'upload', 'hack' ], 'root' => 'download' ],
        'ghost_role'  => [ 'actions' => [ 'view' ], 'root' => '' ],
        'contributor' => [ 'actions' => [ 'view' ], 'root' => '../etc' ],
        'author'      => [ 'actions' => [], 'root' => '' ],
    ],
    'users' => [
        '7'  => [ 'actions' => [ 'delete', 'view' ], 'root' => '' ],
        '8'  => [ 'actions' => [], 'root' => '' ],
        '99' => [ 'actions' => [ 'view' ], 'root' => '' ],
        'x'  => [ 'actions' => [ 'view' ], 'root' => '' ],
    ],
], [ 'editor', 'contributor', 'author' ], $exists, $folder_ok );
check( 'unknown actions are dropped, folder gets its slash', $clean['roles']['editor'] === [ 'actions' => [ 'view', 'upload' ], 'root' => 'download/' ] );
check( 'a role that does not exist is dropped', ! isset( $clean['roles']['ghost_role'] ) );
check( 'a folder that is not allowed drops the row', ! isset( $clean['roles']['contributor'] ) );
check( 'a role with no actions and no folder is not stored', ! isset( $clean['roles']['author'] ) );
$kept = ISXM_File_Manager_Access::sanitize( [ 'roles' => [ 'author' => [ 'actions' => [], 'root' => 'download' ] ] ], [ 'author' ], $exists, $folder_ok );
check( 'a folder picked before any box is ticked is kept (and grants nothing)', $kept['roles']['author'] === [ 'actions' => [], 'root' => 'download/' ] && ISXM_File_Manager_Access::resolve_rules( [ 'author' ], 1, false, $kept )['actions'] === [] );
check( 'actions keep their fixed order', $clean['users'][7]['actions'] === [ 'view', 'delete' ] );
check( 'an empty user row is kept (it shuts them out)', $clean['users'][8] === [ 'actions' => [], 'root' => '' ] );
check( 'unknown users and bad ids are dropped', array_keys( $clean['users'] ) === [ 7, 8 ] );
check( 'garbage input gives an empty config', ISXM_File_Manager_Access::sanitize( 'x', [ 'editor' ], $exists, $folder_ok ) === [ 'roles' => [], 'users' => [] ] );

group( 'Folder check against the real disk' );
$root = fresh_root();
check( 'the default folder check accepts an existing folder', ISXM_File_Manager_Access::sanitize( [ 'roles' => [ 'editor' => [ 'actions' => [ 'view' ], 'root' => 'download/Specification' ] ] ], [ 'editor' ], $exists )['roles']['editor']['root'] === 'download/Specification/' );
check( '…and rejects one that does not exist', ISXM_File_Manager_Access::sanitize( [ 'roles' => [ 'editor' => [ 'actions' => [ 'view' ], 'root' => 'nope' ] ] ], [ 'editor' ], $exists )['roles'] === [] );
check( '…and rejects ../', ISXM_File_Manager_Access::sanitize( [ 'roles' => [ 'editor' => [ 'actions' => [ 'view' ], 'root' => '../' ] ] ], [ 'editor' ], $exists )['roles'] === [] );

finish();

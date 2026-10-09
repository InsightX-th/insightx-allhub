<?php
/**
 * InsightX Offload → File Manager: the page, its AJAX endpoint and
 * downloads. Every file operation is ISXM_File_Manager's; this class only
 * checks who is asking and turns requests into calls.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class ISXM_File_Manager_Admin {

    const SLUG  = 'insightx-offload-files';
    const NONCE = 'isxs_fm';

    private $hook = '';

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ], 20 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_ajax_isxs_fm', [ $this, 'ajax' ] );
        add_action( 'admin_post_isxs_fm_download', [ $this, 'download' ] );
    }

    public function register_menu() {
        if ( current_user_can( 'manage_options' ) ) {
            $this->hook = (string) add_submenu_page( ISXM_Admin::PAGE_SLUG, __( 'File Manager', 'insightx-offload' ), __( 'File Manager', 'insightx-offload' ), 'manage_options', self::SLUG, [ $this, 'render_page' ] );
        } elseif ( ISXM_File_Manager_Access::can( 'view' ) ) {
            // Everyone else given access sees File Manager on its own — never
            // the Offload settings. The page and every request check access
            // themselves; "read" only gets the menu item drawn.
            $this->hook = (string) add_menu_page( __( 'File Manager', 'insightx-offload' ), __( 'File Manager', 'insightx-offload' ), 'read', self::SLUG, [ $this, 'render_page' ], 'dashicons-portfolio', 77 );
        }
    }

    /** This user's file system: locked to their folder. */
    private function fs() {
        return ( new ISXM_File_Manager( ABSPATH, null, ISXM_File_Manager_Access::rules()['root'], current_user_can( 'unfiltered_html' ) ) )
            ->force_hidden( current_user_can( 'manage_options' ) );
    }

    public function enqueue_assets( $hook ) {
        if ( $hook === '' || $hook !== $this->hook ) return;
        wp_enqueue_style( 'isxs-admin', ISXM_PLUGIN_URL . 'assets/css/admin.css', [], filemtime( ISXM_PLUGIN_DIR . 'assets/css/admin.css' ) );
        wp_enqueue_script( 'isxs-fm', ISXM_PLUGIN_URL . 'assets/js/file-manager.js', [ 'jquery' ], filemtime( ISXM_PLUGIN_DIR . 'assets/js/file-manager.js' ), true );
        $fs    = $this->fs();
        $rules = ISXM_File_Manager_Access::rules();
        $admin = current_user_can( 'manage_options' );
        wp_localize_script( 'isxs-fm', 'isxsFm', [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'downloadUrl' => admin_url( 'admin-post.php?action=isxs_fm_download' ),
            'nonce'       => wp_create_nonce( self::NONCE ),
            'maxUpload'   => (int) wp_max_upload_size(),
            'readOnly'    => $fs->read_only(),
            'jail'        => $fs->jail(),
            'start'       => $this->start_path( $fs ),
            'pageUrl'     => menu_page_url( self::SLUG, false ),
            'siteUrl'     => trailingslashit( site_url() ),
            'bucketUrl'   => trailingslashit( ISXM_Settings::public_base_url() ),
            'can'         => $rules['actions'],
            'access'      => $admin ? $this->access_data() : null,
            'i18n'        => [
                'root'          => __( 'WordPress', 'insightx-offload' ),
                'name'          => __( 'Name', 'insightx-offload' ),
                'size'          => __( 'Size', 'insightx-offload' ),
                'modified'      => __( 'Modified', 'insightx-offload' ),
                'empty'         => __( 'This folder is empty. Drop files here to upload.', 'insightx-offload' ),
                'loading'       => __( 'Loading…', 'insightx-offload' ),
                'readOnly'      => __( 'View only: this location holds the site\'s code.', 'insightx-offload' ),
                'newFolder'     => __( 'New folder', 'insightx-offload' ),
                'folderName'    => __( 'Folder name', 'insightx-offload' ),
                'newFile'       => __( 'New file', 'insightx-offload' ),
                'fileName'      => __( 'File name, e.g. readme.txt', 'insightx-offload' ),
                'fileCreated'   => __( 'File created', 'insightx-offload' ),
                'rename'        => __( 'Rename', 'insightx-offload' ),
                'newName'       => __( 'New name', 'insightx-offload' ),
                'moveTo'        => __( 'Move to', 'insightx-offload' ),
                'moveHere'      => __( 'Move here', 'insightx-offload' ),
                'delete'        => __( 'Delete', 'insightx-offload' ),
                /* translators: %d: number of selected items. */
                'deleteAsk'     => __( 'Delete %d item(s)? Folders are deleted with everything in them. This cannot be undone.', 'insightx-offload' ),
                'cancel'        => __( 'Cancel', 'insightx-offload' ),
                'save'          => __( 'Save', 'insightx-offload' ),
                'replace'       => __( 'Replace', 'insightx-offload' ),
                'skip'          => __( 'Skip', 'insightx-offload' ),
                'replaceAsk'    => __( 'These files already exist. Replace them?', 'insightx-offload' ),
                /* translators: %s: maximum upload size, e.g. 64 MB. */
                'tooBig'        => __( 'Larger than the server allows (%s)', 'insightx-offload' ),
                'uploaded'      => __( 'Uploaded', 'insightx-offload' ),
                'created'       => __( 'Folder created', 'insightx-offload' ),
                'renamed'       => __( 'Renamed', 'insightx-offload' ),
                'moved'         => __( 'Moved', 'insightx-offload' ),
                'deleted'       => __( 'Deleted', 'insightx-offload' ),
                'onBucket'      => __( 'On the bucket', 'insightx-offload' ),
                'failed'        => __( 'Request failed. Please try again.', 'insightx-offload' ),
                'perm'          => __( 'Permissions', 'insightx-offload' ),
                'kind'          => __( 'Kind', 'insightx-offload' ),
                'readWrite'     => __( 'read and write', 'insightx-offload' ),
                'readOnlyShort' => __( 'read only', 'insightx-offload' ),
                /* translators: %s: destination, e.g. "Cloudflare R2 · bucket shop". */
                'deleteBucket'  => __( 'Files on %s are deleted too; links to them will stop working.', 'insightx-offload' ),
                'destination'   => ISXM_Tools::destination_label(),
                'kindFolder'    => __( 'Folder', 'insightx-offload' ),
                /* translators: %s: file extension in capitals, e.g. PDF. */
                'kindFile'      => __( '%s file', 'insightx-offload' ),
                'kindNone'      => __( 'File', 'insightx-offload' ),
                /* translators: 1: number of items, 2: total size. */
                'items'         => __( '%1$d items, %2$s', 'insightx-offload' ),
                /* translators: 1: number of selected items, 2: their size. */
                'selectedItems' => __( '%1$d selected, %2$s', 'insightx-offload' ),
                /* translators: 1: search text, 2: folder, 3: number of results. */
                'results'       => __( 'Results for "%1$s" in /%2$s: %3$d', 'insightx-offload' ),
                'resultsCut'    => __( '(first results only, narrow the search)', 'insightx-offload' ),
                'clear'         => __( 'Clear', 'insightx-offload' ),
                'searching'     => __( 'Searching…', 'insightx-offload' ),
                'noResults'     => __( 'Nothing found.', 'insightx-offload' ),
                'saved'         => __( 'Saved', 'insightx-offload' ),
                'choose'        => __( 'Choose', 'insightx-offload' ),
                'whole'         => __( 'Whole site', 'insightx-offload' ),
                'chooseFolder'  => __( 'Choose folder', 'insightx-offload' ),
                'useFolder'     => __( 'Use this folder', 'insightx-offload' ),
                'remove'        => __( 'Remove', 'insightx-offload' ),
                'role'          => __( 'Role', 'insightx-offload' ),
                'menu'          => [
                    'open'      => __( 'Open', 'insightx-offload' ),
                    'openTab'   => __( 'Open in new tab', 'insightx-offload' ),
                    'download'  => __( 'Download', 'insightx-offload' ),
                    'preview'   => __( 'Preview', 'insightx-offload' ),
                    'upload'    => __( 'Upload files here', 'insightx-offload' ),
                    'mkdir'     => __( 'New folder', 'insightx-offload' ),
                    'mkfile'    => __( 'New file', 'insightx-offload' ),
                    'copy'      => __( 'Copy', 'insightx-offload' ),
                    'cut'       => __( 'Cut', 'insightx-offload' ),
                    'paste'     => __( 'Paste', 'insightx-offload' ),
                    'duplicate' => __( 'Duplicate', 'insightx-offload' ),
                    'delete'    => __( 'Delete', 'insightx-offload' ),
                    'empty'     => __( 'Empty the folder', 'insightx-offload' ),
                    'rename'    => __( 'Rename', 'insightx-offload' ),
                    'all'       => __( 'Select all', 'insightx-offload' ),
                    'invert'    => __( 'Invert selection', 'insightx-offload' ),
                    'info'      => __( 'Get info', 'insightx-offload' ),
                    'compress'  => __( 'Shrink PDF', 'insightx-offload' ),
                ],
                /* translators: %s: folder name. */
                'emptyAsk'      => __( 'Delete everything inside "%s"? The folder itself is kept. This cannot be undone.', 'insightx-offload' ),
                'emptied'       => __( 'Folder emptied', 'insightx-offload' ),
                'copied'        => __( 'Copied', 'insightx-offload' ),
                'duplicated'    => __( 'Duplicated', 'insightx-offload' ),
                /* translators: %d: number of items. */
                'clipCopy'      => __( '%d item(s) copied, paste them in another folder', 'insightx-offload' ),
                /* translators: %d: number of items. */
                'clipCut'       => __( '%d item(s) cut, paste them in another folder', 'insightx-offload' ),
                'compressing'   => __( 'Shrinking…', 'insightx-offload' ),
                /* translators: 1: size before, 2: size after. */
                'shrunk'        => __( 'Shrunk from %1$s to %2$s', 'insightx-offload' ),
                'notShrunk'     => __( 'Already small — left as it is', 'insightx-offload' ),
                'bucketUpdated' => __( 'bucket copy replaced', 'insightx-offload' ),
                'noPreview'     => __( 'This file type cannot be previewed. Download it instead.', 'insightx-offload' ),
                'close'         => __( 'Close', 'insightx-offload' ),
                'infoPath'      => __( 'Path', 'insightx-offload' ),
                'infoUrl'       => __( 'Bucket URL', 'insightx-offload' ),
                'infoSiteUrl'   => __( 'Server URL', 'insightx-offload' ),
                'infoBucket'    => __( 'On the bucket', 'insightx-offload' ),
                'yes'           => __( 'Yes', 'insightx-offload' ),
                'checkBucket'   => __( 'Check on the bucket', 'insightx-offload' ),
                'checking'      => __( 'Checking…', 'insightx-offload' ),
                /* translators: 1: destination, 2: file size. */
                'foundOn'       => __( 'On %1$s · %2$s', 'insightx-offload' ),
                /* translators: %s: destination. */
                'notFoundOn'    => __( 'Not found on %s', 'insightx-offload' ),
                'openOnBucket'  => __( 'Open the bucket copy', 'insightx-offload' ),
                'healed'        => __( 'recorded as uploaded', 'insightx-offload' ),
                'no'            => __( 'No', 'insightx-offload' ),
                'folder'        => __( 'Folder', 'insightx-offload' ),
                'actions'       => [
                    'view'     => __( 'View', 'insightx-offload' ),
                    'upload'   => __( 'Upload', 'insightx-offload' ),
                    'mkdir'    => __( 'New folder', 'insightx-offload' ),
                    'rename'   => __( 'Rename', 'insightx-offload' ),
                    'move'     => __( 'Move', 'insightx-offload' ),
                    'download' => __( 'Download', 'insightx-offload' ),
                    'delete'   => __( 'Delete', 'insightx-offload' ),
                ],
            ],
        ] );
    }

    public function render_page() {
        if ( ! ISXM_File_Manager_Access::can( 'view' ) ) return;
        $admin = current_user_can( 'manage_options' );
        ?>
        <div class="isxs-wrap isxs-fm" id="isxs-fm">
            <header class="isxs-header">
                <div class="isxs-header-top">
                    <div class="isxs-header-brand">
                        <div class="isxs-logo" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>
                        </div>
                        <div>
                            <h1><?php esc_html_e( 'File Manager', 'insightx-offload' ); ?></h1>
                            <p class="isxs-tagline"><?php esc_html_e( 'Files on this server. Files added outside the Media Library go to the bucket at once while Offload Media is on.', 'insightx-offload' ); ?>
                                <span class="isxs-fm-dest"><?php
                                    /* translators: %s: destination, e.g. "Cloudflare R2 · bucket shop". */
                                    printf( esc_html__( 'Destination: %s', 'insightx-offload' ), '<strong>' . esc_html( ISXM_Tools::destination_label() ) . '</strong>' );
                                    if ( ! ISXM_Settings::get( 'offload_enabled' ) ) echo ' · ' . esc_html__( 'Offload Media is off', 'insightx-offload' );
                                ?></span></p>
                        </div>
                    </div>
                </div>
            </header>

            <?php if ( $admin ) : ?>
            <nav class="isxs-fm-tabs" role="tablist">
                <button type="button" role="tab" class="is-active" aria-selected="true" data-tab="files"><?php esc_html_e( 'Files', 'insightx-offload' ); ?></button>
                <button type="button" role="tab" aria-selected="false" data-tab="access"><?php esc_html_e( 'Access', 'insightx-offload' ); ?></button>
            </nav>
            <?php endif; ?>

            <div class="isxs-card isxs-fm-card" data-panel="files">
                <div class="isxs-fm-toolbar">
                    <nav class="isxs-fm-crumbs" id="isxs-fm-crumbs" aria-label="<?php esc_attr_e( 'Folder path', 'insightx-offload' ); ?>"></nav>
                    <form class="isxs-fm-search" id="isxs-fm-search" role="search">
                        <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input type="search" id="isxs-fm-q" placeholder="<?php esc_attr_e( 'Search files in this folder', 'insightx-offload' ); ?>" aria-label="<?php esc_attr_e( 'Search files', 'insightx-offload' ); ?>">
                    </form>
                    <div class="isxs-fm-actions">
                        <?php
                        /* translators: %s: maximum upload size, e.g. 64 MB. */
                        $isxm_max = sprintf( __( 'Maximum upload size: %s per file', 'insightx-offload' ), size_format( wp_max_upload_size() ) );
                        // op => [label, class, permission, icon path(s), title]
                        $isxm_tools = [
                            'upload'   => [ __( 'Upload', 'insightx-offload' ), 'is-primary', 'upload', '<path d="M12 16V4"/><path d="m7 9 5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>', $isxm_max ],
                            'mkfile'   => [ __( 'New file', 'insightx-offload' ), '', 'upload', '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M12 12v6M9 15h6"/>', '' ],
                            'mkdir'    => [ __( 'New folder', 'insightx-offload' ), '', 'mkdir', '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 10v6M9 13h6"/>', '' ],
                            'rename'   => [ __( 'Rename', 'insightx-offload' ), 'is-sep', 'rename', '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="m13.5 6.5 4 4"/>', '' ],
                            'move'     => [ __( 'Move', 'insightx-offload' ), '', 'move', '<path d="M5 12h14"/><path d="m13 6 6 6-6 6"/>', '' ],
                            'download' => [ __( 'Download', 'insightx-offload' ), '', 'download', '<path d="M12 4v12"/><path d="m7 11 5 5 5-5"/><path d="M4 20h16"/>', '' ],
                            'delete'   => [ __( 'Delete', 'insightx-offload' ), 'is-danger is-sep', 'delete', '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/>', '' ],
                        ];
                        foreach ( $isxm_tools as $isxm_op => $isxm_tool ) :
                            ?>
                        <button type="button" class="isxs-fm-tool <?php echo esc_attr( $isxm_tool[1] ); ?>" data-fm="<?php echo esc_attr( $isxm_op ); ?>" data-can="<?php echo esc_attr( $isxm_tool[2] ); ?>" title="<?php echo esc_attr( $isxm_tool[4] !== '' ? $isxm_tool[4] : $isxm_tool[0] ); ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><?php echo $isxm_tool[3]; // phpcs:ignore WordPress.Security.EscapeOutput -- fixed markup above ?></svg>
                            <span><?php echo esc_html( $isxm_tool[0] ); ?></span>
                        </button>
                        <?php endforeach; ?>
                        <input type="file" id="isxs-fm-input" multiple hidden>
                    </div>
                </div>
                <div class="isxs-fm-body">
                    <aside class="isxs-fm-tree" id="isxs-fm-tree"></aside>
                    <section class="isxs-fm-main" id="isxs-fm-main">
                        <p class="isxs-fm-note" id="isxs-fm-note" hidden></p>
                        <p class="isxs-fm-results" id="isxs-fm-results" hidden><span></span> <button type="button" class="isxs-fm-results-clear"><?php esc_html_e( 'Clear', 'insightx-offload' ); ?></button></p>
                        <div class="isxs-fm-table-wrap"><table class="isxs-fm-table" id="isxs-fm-table"></table></div>
                        <div class="isxs-fm-drop" id="isxs-fm-drop" hidden><?php esc_html_e( 'Drop files to upload here', 'insightx-offload' ); ?></div>
                    </section>
                </div>
                <ul class="isxs-fm-queue" id="isxs-fm-queue" hidden></ul>
                <div class="isxs-fm-status"><span id="isxs-fm-status-path"></span><span id="isxs-fm-status-count"></span></div>
            </div>

            <?php if ( $admin ) : ?>
            <div class="isxs-card" data-panel="access" hidden>
                <div class="isxs-card-head">
                    <div class="isxs-card-head-title">
                        <div>
                            <h2><?php esc_html_e( 'Who may use File Manager', 'insightx-offload' ); ?></h2>
                            <p class="isxs-card-sub"><?php esc_html_e( 'Administrators can always do everything. Everyone else gets what their role allows.', 'insightx-offload' ); ?></p>
                        </div>
                    </div>
                </div>
                <div class="isxs-card-body">
                    <h3 class="isxs-fm-access-title"><?php esc_html_e( 'By role', 'insightx-offload' ); ?></h3>
                    <div class="isxs-fm-table-wrap"><table class="isxs-fm-access" id="isxs-fm-access-roles"></table></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="isxs-fm-modal" id="isxs-fm-modal" hidden>
                <div class="isxs-fm-dialog" role="dialog" aria-modal="true" aria-labelledby="isxs-fm-modal-title">
                    <h2 id="isxs-fm-modal-title"></h2>
                    <div class="isxs-fm-modal-body"></div>
                    <div class="isxs-fm-modal-actions">
                        <button type="button" class="isxs-btn isxs-btn-ghost" data-modal="cancel"></button>
                        <button type="button" class="isxs-btn isxs-btn-primary" data-modal="ok"></button>
                    </div>
                </div>
            </div>
            <div class="isxs-toast" id="isxs-toast"></div>
        </div>
        <?php
    }

    /** The action each request needs; admin-only ops need manage_options. */
    const OP_ACTION = [ 'list' => 'view', 'search' => 'view', 'check' => 'view', 'sizes' => 'view', 'mkdir' => 'mkdir', 'upload' => 'upload', 'rename' => 'rename', 'move' => 'move', 'delete' => 'delete',
        // A copy is new files, like an upload; emptying a folder is deleting.
        'copy' => 'upload', 'duplicate' => 'upload', 'mkfile' => 'upload', 'empty' => 'delete' ];

    /** Shown inline by Preview. Anything else (SVG, HTML …) is always a download: it could run script on this admin origin. */
    const INLINE_TYPES = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif',
        'pdf' => 'application/pdf', 'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'txt' => 'text/plain', 'csv' => 'text/plain', 'log' => 'text/plain' ];

    public function ajax() {
        check_ajax_referer( self::NONCE, 'nonce' );
        $op      = isset( $_POST['op'] ) ? sanitize_key( $_POST['op'] ) : '';
        $allowed = in_array( $op, [ 'save_access', 'compress' ], true )
            ? current_user_can( 'manage_options' )
            : isset( self::OP_ACTION[ $op ] ) && ISXM_File_Manager_Access::can( self::OP_ACTION[ $op ] );
        if ( ! $allowed ) wp_send_json_error( [ 'code' => 'isxfm_denied', 'message' => __( 'You do not have permission to do this.', 'insightx-offload' ) ], 403 );
        $fs   = $this->fs();
        $path = isset( $_POST['path'] ) ? (string) wp_unslash( $_POST['path'] ) : '';
        $name = isset( $_POST['name'] ) ? (string) wp_unslash( $_POST['name'] ) : '';

        switch ( $op ) {
            case 'search':
                $result = $fs->search( $path, isset( $_POST['q'] ) ? (string) wp_unslash( $_POST['q'] ) : '' );
                break;
            case 'save_access':
                $raw    = json_decode( isset( $_POST['config'] ) ? (string) wp_unslash( $_POST['config'] ) : '', true );
                $clean  = ISXM_File_Manager_Access::sanitize( is_array( $raw ) ? $raw : [], $this->role_slugs() );
                update_option( ISXM_File_Manager_Access::OPTION, $clean, false );
                $result = $this->access_data();
                break;
            case 'list':
                $result = $fs->listing( $path );
                if ( ! is_wp_error( $result ) ) {
                    $manifest = ISXM_Tools::server_manifest();
                    foreach ( $result['files'] as &$file ) {
                        $file['bucket'] = isset( $manifest[ $file['path'] ] ) && (int) $manifest[ $file['path'] ] === $file['mtime'];
                    }
                    unset( $file );
                }
                break;
            case 'mkdir':
                $result = $fs->make_dir( $path, $name );
                break;
            case 'check':
                // Only files this user can see may be looked up on the bucket.
                $file   = $fs->resolve( $path );
                if ( ! is_wp_error( $file ) && $fs->readable( $file ) !== true ) $file = $fs->readable( $file );
                $result = is_wp_error( $file ) ? $file : ( is_file( $file ) ? ISXM_Tools::server_file_status( $fs->normalize( $path ), true ) : new WP_Error( 'isxfm_not_file', __( 'File not found.', 'insightx-offload' ) ) );
                break;
            case 'compress':
                // Heavy (Ghostscript), so administrators only — see the guard above.
                $file = $fs->resolve( $path );
                if ( is_wp_error( $file ) ) { $result = $file; break; }
                // Rewrites the file in place: same rules as any other change.
                $ok = $fs->changeable( $file );
                if ( is_wp_error( $ok ) ) { $result = $ok; break; }
                $result = ( is_file( $file ) && strtolower( substr( $file, -4 ) ) === '.pdf' )
                    ? ISXM_Tools::compress_pdf_file( $fs->normalize( $path ) )
                    : new WP_Error( 'isxfm_not_pdf', __( 'Not a PDF file.', 'insightx-offload' ) );
                break;
            case 'mkfile':
                $result = $fs->make_file( $path, $name );
                break;
            case 'upload':
                $file = $_FILES['file'] ?? null;
                if ( ! is_array( $file ) || (int) $file['error'] !== UPLOAD_ERR_OK ) {
                    $too_big = is_array( $file ) && in_array( (int) $file['error'], [ UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ], true );
                    $result  = new WP_Error( 'isxfm_upload', $too_big
                        /* translators: %s: maximum upload size, e.g. 64 MB. */
                        ? sprintf( __( 'Larger than the server allows (%s)', 'insightx-offload' ), size_format( wp_max_upload_size() ) )
                        : __( 'The upload did not reach the server.', 'insightx-offload' ) );
                    break;
                }
                $result = $fs->upload( $path, (string) $file['name'], (string) $file['tmp_name'], ! empty( $_POST['replace'] ) );
                break;
            case 'rename':
                $result = $fs->rename( $path, $name );
                break;
            case 'move':
                $result = $fs->move( $path, isset( $_POST['to'] ) ? (string) wp_unslash( $_POST['to'] ) : '' );
                break;
            case 'delete':
                $result = $fs->delete( $path );
                break;
            case 'copy':
                $result = $fs->copy( $path, isset( $_POST['to'] ) ? (string) wp_unslash( $_POST['to'] ) : '' );
                break;
            case 'duplicate':
                $result = $fs->duplicate( $path );
                break;
            case 'empty':
                $result = $fs->empty_dir( $path );
                break;
            case 'sizes':
                $result = $this->folder_sizes( $fs, isset( $_POST['paths'] ) ? (array) wp_unslash( $_POST['paths'] ) : [] );
                break;
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ] );
        }
        // Anything that changed files: cached folder sizes up the tree are stale.
        if ( ! in_array( $op, [ 'list', 'search', 'check', 'sizes' ], true ) ) {
            $this->forget_sizes( [ $fs->normalize( $path ), isset( $_POST['to'] ) ? $fs->normalize( (string) wp_unslash( $_POST['to'] ) ) : '' ] );
        }
        wp_send_json_success( $result );
    }

    const SIZE_CACHE = 'isxs_fm_size_';

    /**
     * Folder totals for the size column: from a 10-minute cache, the rest
     * worked out now (about 5 seconds per request; the page asks again for
     * what is still pending).
     *
     * @return array{sizes: array<string,int>, pending: string[]}
     */
    private function folder_sizes( ISXM_File_Manager $fs, array $paths ) {
        $sizes = []; $todo = [];
        foreach ( array_slice( $paths, 0, 200 ) as $path ) {
            $norm = $fs->normalize( (string) $path );
            if ( is_wp_error( $norm ) ) continue;
            $hit = get_transient( self::SIZE_CACHE . md5( $norm ) );
            if ( $hit !== false ) $sizes[ $norm ] = (int) $hit; else $todo[] = $norm;
        }
        $done = $fs->dir_sizes( $todo, 5.0 );
        foreach ( $done['sizes'] as $path => $bytes ) {
            set_transient( self::SIZE_CACHE . md5( $path ), $bytes, 10 * MINUTE_IN_SECONDS );
            $sizes[ $path ] = $bytes;
        }
        return [ 'sizes' => $sizes, 'pending' => $done['pending'] ];
    }

    /** Drops cached totals of these folders and every folder above them. */
    private function forget_sizes( array $paths ) {
        foreach ( $paths as $path ) {
            if ( is_wp_error( $path ) || $path === null ) continue;
            $parts = $path === '' ? [] : explode( '/', $path );
            for ( $i = count( $parts ); $i >= 0; $i-- ) {
                delete_transient( self::SIZE_CACHE . md5( implode( '/', array_slice( $parts, 0, $i ) ) ) );
            }
        }
    }

    public function download() {
        check_admin_referer( self::NONCE );
        if ( ! ISXM_File_Manager_Access::can( 'download' ) ) wp_die( esc_html__( 'You do not have permission to do this.', 'insightx-offload' ), 403 );
        $fs   = $this->fs();
        $file = $fs->resolve( isset( $_GET['path'] ) ? (string) wp_unslash( $_GET['path'] ) : '' );
        if ( is_wp_error( $file ) || ! is_file( $file ) || $fs->readable( $file ) !== true ) wp_die( esc_html__( 'File not found.', 'insightx-offload' ), 404 );
        $name   = basename( $file );
        $ext    = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        $inline = ! empty( $_GET['inline'] ) && isset( self::INLINE_TYPES[ $ext ] );
        nocache_headers();
        header( 'Content-Type: ' . ( $inline ? self::INLINE_TYPES[ $ext ] : 'application/octet-stream' ) );
        header( 'Content-Length: ' . filesize( $file ) );
        header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . str_replace( [ '"', '\\' ], '_', $name ) . '"; filename*=UTF-8\'\'' . rawurlencode( $name ) );
        header( 'X-Content-Type-Options: nosniff' );
        while ( ob_get_level() ) ob_end_clean();
        readfile( $file );
        exit;
    }

    /** Role slugs that can be given access (administrators already have it). */
    private function role_slugs() {
        return array_values( array_diff( array_keys( wp_roles()->roles ), [ 'administrator' ] ) );
    }

    /** What the Access tab shows: roles, saved rules, and who the user rows are. */
    private function access_data() {
        $config = ISXM_File_Manager_Access::config();
        $roles  = [];
        foreach ( $this->role_slugs() as $slug ) {
            $roles[] = [ 'slug' => $slug, 'name' => translate_user_role( wp_roles()->roles[ $slug ]['name'] ) ];
        }
        return [ 'roles' => $roles, 'config' => $config ];
    }

    /** ?path= from "Open in new tab" when it is a folder this user may see; else their folder or download/. */
    private function start_path( ISXM_File_Manager $fs ) {
        $asked = isset( $_GET['path'] ) ? (string) wp_unslash( $_GET['path'] ) : '';
        if ( $asked !== '' ) {
            $real = $fs->resolve( $asked );
            if ( ! is_wp_error( $real ) && is_dir( $real ) ) return $fs->normalize( $asked );
        }
        if ( $fs->jail() !== '' ) return $fs->jail();
        return is_dir( ABSPATH . 'download' ) ? 'download' : '';
    }
}

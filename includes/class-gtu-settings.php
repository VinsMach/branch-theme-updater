<?php
/**
 * Pagina di impostazioni nel pannello amministrativo WordPress.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GTU_Settings {

    private static ?GTU_Settings $instance = null;

    public static function get_instance(): GTU_Settings {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_notices',         [ $this, 'admin_notices' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    public function add_menu(): void {
        add_options_page(
            __( 'Branch Theme Updater', 'branch-theme-updater' ),
            __( 'Branch Theme Updater', 'branch-theme-updater' ),
            'manage_options',
            'branch-theme-updater',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings(): void {
        register_setting( 'gtu_settings_group', GTU_OPTION_KEY, [ $this, 'sanitize' ] );

        add_settings_section( 'gtu_main', __( 'Configurazione repository', 'branch-theme-updater' ), '__return_false', 'branch-theme-updater' );

        $fields = [
            'repo_url'    => __( 'URL del repository', 'branch-theme-updater' ),
            'theme_slug'    => __( 'Slug del tema', 'branch-theme-updater' ),
            'repo_owner'  => __( 'Owner (utente / organizzazione)', 'branch-theme-updater' ),
            'repo_name'   => __( 'Nome del repository', 'branch-theme-updater' ),
            'repo_branch' => __( 'Branch da sincronizzare', 'branch-theme-updater' ),
            'repo_token'  => __( 'Personal Access Token', 'branch-theme-updater' ),
            'user_agent'    => __( 'User-Agent (il tuo username)', 'branch-theme-updater' ),
        ];

        foreach ( $fields as $key => $label ) {
            add_settings_field( 'gtu_' . $key, $label, [ $this, 'render_field' ], 'branch-theme-updater', 'gtu_main', [ 'key' => $key ] );
        }
    }

    public function sanitize( $input ): array {
        $old = get_option( GTU_OPTION_KEY, [] );
        if ( ! is_array( $old ) ) {
            $old = [];
        }

        $clean = [];
        foreach ( [ 'repo_url', 'theme_slug', 'repo_owner', 'repo_name', 'repo_branch', 'repo_token', 'user_agent' ] as $k ) {
            $clean[ $k ] = isset( $input[ $k ] ) ? sanitize_text_field( $input[ $k ] ) : '';
        }

        if ( '' === $clean['repo_token'] && empty( $input['clear_repo_token'] ) ) {
            $clean['repo_token'] = isset( $old['repo_token'] ) ? sanitize_text_field( (string) $old['repo_token'] ) : '';
        }

        $parsed      = $this->parse_repo_url( $clean['repo_url'] ?? '' );
        $url_changed = ( $clean['repo_url'] ?? '' ) !== ( $old['repo_url'] ?? '' );

        // Se l'utente cambia l'URL repository, aggiorniamo owner/repo/branch dall'URL.
        // Se invece cambia manualmente owner/repo/branch, NON li sovrascriviamo con il vecchio URL salvato.
        if ( $parsed && $url_changed ) {
            $clean['repo_owner']  = $parsed['owner'];
            $clean['repo_name']   = $parsed['repo'];
            $clean['repo_branch'] = $parsed['branch'] ?: ( $clean['repo_branch'] ?: 'main' );
        }

        if ( empty( $clean['repo_branch'] ) ) {
            $clean['repo_branch'] = 'main';
        }

        // Manteniamo l'URL coerente con i campi manuali salvati.
        if ( ! empty( $clean['repo_owner'] ) && ! empty( $clean['repo_name'] ) ) {
            $clean['repo_url'] = gtu_repo_tree_url( $clean['repo_owner'], $clean['repo_name'], $clean['repo_branch'] );
        }

        delete_transient( 'gtu_release_cache' );
        return $clean;
    }


    private function parse_repo_url( string $url ): ?array {
        $url = trim( $url );
        if ( '' === $url ) {
            return null;
        }

        $parts = wp_parse_url( $url );
        if ( empty( $parts['host'] ) || empty( $parts['path'] ) ) {
            return null;
        }

        if ( ! in_array( strtolower( $parts['host'] ), [ 'github.com', 'www.github.com' ], true ) ) {
            return null;
        }

        $segments = array_values( array_filter( explode( '/', trim( $parts['path'], '/' ) ) ) );
        if ( count( $segments ) < 2 ) {
            return null;
        }

        $repo = preg_replace( '/\.git$/', '', $segments[1] );
        $branch = '';

        if ( isset( $segments[2], $segments[3] ) && 'tree' === $segments[2] ) {
            $branch = implode( '/', array_slice( $segments, 3 ) );
        }

        return [
            'owner'  => sanitize_text_field( $segments[0] ),
            'repo'   => sanitize_text_field( $repo ),
            'branch' => sanitize_text_field( $branch ),
        ];
    }

    public function render_field( array $args ): void {
        $options = gtu_get_settings();
        $key     = $args['key'];
        $value   = $options[ $key ] ?? '';

        $descriptions = [
            'repo_url'    => __( 'Incolla un URL tipo <code>https://github.com/owner/repo</code> oppure <code>https://github.com/owner/repo/tree/branch</code>. Se lo cambi, owner, repository e branch vengono compilati da lì.', 'branch-theme-updater' ),
            'theme_slug'    => __( 'Cartella del tema in <code>wp-content/themes/</code>. Puoi scegliere un tema già installato o scrivere lo slug di uno nuovo.', 'branch-theme-updater' ),
            'repo_owner'  => __( 'Utente o organizzazione, per esempio <code>octocat</code>.', 'branch-theme-updater' ),
            'repo_name'   => __( 'Nome del repository, per esempio <code>my-theme</code>.', 'branch-theme-updater' ),
            'repo_branch' => __( 'Branch da installare, per esempio <code>main</code>.', 'branch-theme-updater' ),
            'repo_token'  => sprintf(
                /* translators: %s: URL of the page where a personal access token can be created. */
                __( 'Serve solo per i repository privati. Usa un token con accesso in lettura ai contenuti. <a href="%s" target="_blank" rel="noopener noreferrer">Crea un token</a>', 'branch-theme-updater' ),
                'https://github.com/settings/personal-access-tokens/new'
            ),
            'user_agent'    => __( 'Opzionale. Se vuoto, il plugin invia un User-Agent generico.', 'branch-theme-updater' ),
        ];

        if ( 'repo_token' === $key ) {
            $has_token = '' !== $value;
            printf(
                '<input type="password" name="%1$s[repo_token]" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s">',
                esc_attr( GTU_OPTION_KEY ),
                $has_token ? esc_attr__( 'Token salvato. Lascialo vuoto per mantenerlo.', 'branch-theme-updater' ) : ''
            );
            if ( $has_token ) {
                printf(
                    '<label style="display:block;margin-top:6px;"><input type="checkbox" name="%1$s[clear_repo_token]" value="1"> %2$s</label>',
                    esc_attr( GTU_OPTION_KEY ),
                    esc_html__( 'Rimuovi il token salvato', 'branch-theme-updater' )
                );
            }
        } else {
            printf(
                '<input type="text" name="%1$s[%2$s]" value="%3$s" class="regular-text" autocomplete="off"%4$s>',
                esc_attr( GTU_OPTION_KEY ),
                esc_attr( $key ),
                esc_attr( $value ),
                'theme_slug' === $key ? ' list="gtu-installed-themes"' : ''
            );
        }

        if ( 'theme_slug' === $key ) {
            echo '<datalist id="gtu-installed-themes">';
            foreach ( wp_get_themes() as $slug => $theme ) {
                printf(
                    '<option value="%s">%s</option>',
                    esc_attr( $slug ),
                    esc_attr( $theme->get( 'Name' ) )
                );
            }
            echo '</datalist>';
        }

        if ( ! empty( $descriptions[ $key ] ) ) {
            printf( '<p class="description">%s</p>', wp_kses_post( $descriptions[ $key ] ) );
        }
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $opts = gtu_get_settings();
        ?>
        <div class="wrap gtu-wrap">
            <h1>
                <span class="dashicons dashicons-update" style="vertical-align:middle;margin-right:6px;"></span>
                <?php esc_html_e( 'Branch Theme Updater', 'branch-theme-updater' ); ?>
            </h1>
            <p>
                <?php esc_html_e( 'Collega la cartella di un tema a un repository remoto. WordPress proporrà gli aggiornamenti da quel branch. Il token serve solo se il repository è privato.', 'branch-theme-updater' ); ?>
            </p>

            <div class="gtu-status-box">
                <?php $this->render_status( $opts ); ?>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'gtu_settings_group' );
                do_settings_sections( 'branch-theme-updater' );
                submit_button( __( 'Salva impostazioni', 'branch-theme-updater' ) );
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e( 'Strumenti', 'branch-theme-updater' ); ?></h2>
            <p>
                <button class="button" id="gtu-clear-cache">
                    <?php esc_html_e( 'Svuota cache', 'branch-theme-updater' ); ?>
                </button>
                <span id="gtu-cache-msg" style="margin-left:10px;display:none;color:green;">
                    <?php esc_html_e( 'Cache eliminata. Ricarica la pagina.', 'branch-theme-updater' ); ?>
                </span>
            </p>
            <p>
                <button class="button" id="gtu-diagnose">
                    🔍 <?php esc_html_e( 'Mostra branch disponibili', 'branch-theme-updater' ); ?>
                </button>
                &nbsp;
                <button class="button button-primary" id="gtu-force-install">
                    ⬇ <?php esc_html_e( 'Forza installazione branch', 'branch-theme-updater' ); ?>
                </button>
            </p>
            <div id="gtu-debug-box" style="display:none;margin-top:16px;">
                <h3><?php esc_html_e( 'Debug', 'branch-theme-updater' ); ?></h3>
                <pre id="gtu-debug-output" style="background:#1e1e1e;color:#d4d4d4;padding:16px;border-radius:4px;overflow:auto;max-height:400px;font-size:12px;white-space:pre-wrap;"></pre>
            </div>
        </div>

        <script>
        (function() {
            var ajaxUrl = '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>';
            var nonces  = {
                clear:   '<?php echo esc_js( wp_create_nonce( 'gtu_clear_cache' ) ); ?>',
                install: '<?php echo esc_js( wp_create_nonce( 'gtu_force_install' ) ); ?>',
                diag:    '<?php echo esc_js( wp_create_nonce( 'gtu_diagnose' ) ); ?>'
            };

            function ajaxCall( action, nonce, btnEl, btnLabel ) {
                btnEl.disabled    = true;
                btnEl.textContent = '⏳ In corso...';
                document.getElementById('gtu-debug-box').style.display  = 'block';
                document.getElementById('gtu-debug-output').textContent = action + '...\n';

                fetch( ajaxUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    'action=' + action + '&_ajax_nonce=' + nonce
                })
                .then( function(r) { return r.json(); } )
                .then( function(data) {
                    document.getElementById('gtu-debug-output').textContent = JSON.stringify( data, null, 2 );
                    btnEl.disabled    = false;
                    btnEl.textContent = data.success ? '✅ ' + btnLabel + ' — OK' : '❌ Errore — vedi debug';
                })
                .catch( function(err) {
                    document.getElementById('gtu-debug-output').textContent = 'Errore JS: ' + err;
                    btnEl.disabled    = false;
                    btnEl.textContent = btnLabel;
                });
            }

            document.getElementById('gtu-clear-cache').addEventListener('click', function(e) {
                e.preventDefault();
                fetch( ajaxUrl, {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    'action=gtu_clear_cache&_ajax_nonce=' + nonces.clear
                }).then( function() {
                    document.getElementById('gtu-cache-msg').style.display = 'inline';
                });
            });

            document.getElementById('gtu-diagnose').addEventListener('click', function(e) {
                e.preventDefault();
                ajaxCall( 'gtu_diagnose', nonces.diag, this, '🔍 Mostra branch disponibili' );
            });

            document.getElementById('gtu-force-install').addEventListener('click', function(e) {
                e.preventDefault();
                if ( ! window.confirm( '<?php echo esc_js( __( 'Sostituire la cartella del tema con il branch selezionato?', 'branch-theme-updater' ) ); ?>' ) ) {
                    return;
                }
                ajaxCall( 'gtu_force_install', nonces.install, this, '⬇ Forza installazione branch' );
            });
        })();
        </script>
        <?php
    }

    private function render_status( array $opts ): void {
        $theme_slug = $opts['theme_slug'] ?? '';
        if ( '' === $theme_slug || empty( $opts['repo_owner'] ) || empty( $opts['repo_name'] ) ) {
            echo '<p>' . esc_html__( 'Nessun tema collegato. Incolla l’URL del repository, indica la cartella del tema e salva.', 'branch-theme-updater' ) . '</p>';
            return;
        }

        $theme      = wp_get_theme( $theme_slug );
        $current    = $theme->exists() ? $theme->get( 'Version' ) : __( 'tema non trovato', 'branch-theme-updater' );
        $stored_sha = get_option( 'gtu_installed_sha_' . $theme_slug, '—' );

        echo '<table class="gtu-table">';
        printf( '<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__( 'Tema monitorato', 'branch-theme-updater' ), esc_html( $theme_slug ?: '—' ) );
        printf( '<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__( 'Versione installata', 'branch-theme-updater' ), esc_html( $current ) );
        printf( '<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__( 'SHA installato', 'branch-theme-updater' ), esc_html( $stored_sha !== '—' ? substr( $stored_sha, 0, 7 ) : '—' ) );
        printf( '<tr><th>%s</th><td><code>%s</code></td></tr>', esc_html__( 'Branch configurato', 'branch-theme-updater' ), esc_html( $opts['repo_branch'] ?? 'main' ) );
        echo '</table>';
    }

    public function admin_notices(): void {
        $screen = get_current_screen();
        if ( ! $screen || 'settings_page_branch-theme-updater' !== $screen->id ) {
            return;
        }

        $opts = gtu_get_settings();
        if ( empty( $opts['theme_slug'] ) || empty( $opts['repo_owner'] ) || empty( $opts['repo_name'] ) ) {
            printf(
                '<div class="notice notice-warning"><p>%s</p></div>',
                esc_html__( 'Branch Theme Updater non è ancora collegato a un tema. Scegli repository e cartella, poi salva.', 'branch-theme-updater' )
            );
            return;
        }

        if ( empty( $opts['repo_token'] ) ) {
            printf(
                '<div class="notice notice-info"><p>%s</p></div>',
                esc_html__( 'Nessun token salvato. I repository pubblici funzionano lo stesso; per un repository privato aggiungi un Personal Access Token.', 'branch-theme-updater' )
            );
        }
    }

    public function enqueue_styles( string $hook ): void {
        if ( 'settings_page_branch-theme-updater' !== $hook ) {
            return;
        }
        wp_add_inline_style( 'wp-admin', '
            .gtu-wrap { max-width: 780px; }
            .gtu-status-box { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:16px 20px; margin:20px 0; }
            .gtu-table { width:100%; border-collapse:collapse; }
            .gtu-table th { text-align:left; width:220px; padding:6px 0; color:#50575e; font-weight:600; }
            .gtu-table td { padding:6px 0; }
        ' );
    }
}


function gtu_remote_headers( array $opts, string $accept = 'application/vnd.github+json' ): array {
    $headers = [
        'User-Agent' => $opts['user_agent'] ?: 'wordpress-branch-theme-updater',
        'Accept'     => $accept,
    ];
    if ( ! empty( $opts['repo_token'] ) ) {
        $headers['Authorization'] = 'token ' . $opts['repo_token'];
    }
    return $headers;
}

function gtu_rrmdir( string $path ): bool {
    $fs = gtu_filesystem();
    if ( ! $fs ) {
        return false;
    }
    if ( ! $fs->exists( $path ) ) {
        return true;
    }

    return (bool) $fs->delete( $path, true );
}


function gtu_copy_dir( string $source, string $target ): bool {
    $source = untrailingslashit( $source );
    $target = untrailingslashit( $target );

    if ( ! is_dir( $source ) ) {
        return false;
    }

    if ( ! wp_mkdir_p( $target ) ) {
        return false;
    }

    $items = scandir( $source );
    if ( false === $items ) {
        return false;
    }

    foreach ( $items as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }

        $from = $source . DIRECTORY_SEPARATOR . $item;
        $to   = $target . DIRECTORY_SEPARATOR . $item;

        if ( is_dir( $from ) && ! is_link( $from ) ) {
            if ( ! gtu_copy_dir( $from, $to ) ) {
                return false;
            }
            continue;
        }

        if ( is_link( $from ) ) {
            $link_target = readlink( $from );
            if ( false === $link_target || ! @symlink( $link_target, $to ) ) {
                // In hosting dove symlink è disabilitato, prova a copiare il file risolto.
                if ( ! @copy( $from, $to ) ) {
                    return false;
                }
            }
            continue;
        }

        if ( ! @copy( $from, $to ) ) {
            return false;
        }

        $fs = gtu_filesystem();
        $mode = fileperms( $from );
        if ( $fs && false !== $mode ) {
            $fs->chmod( $to, $mode & 0777 );
        }
    }

    $fs = gtu_filesystem();
    $mode = fileperms( $source );
    if ( $fs && false !== $mode ) {
        $fs->chmod( $target, $mode & 0777 );
    }
    return true;
}

function gtu_find_theme_source_dir( string $dir ): ?string {
    $direct_style = trailingslashit( $dir ) . 'style.css';
    if ( file_exists( $direct_style ) ) {
        return $dir;
    }

    $items = scandir( $dir );
    if ( false === $items ) {
        return null;
    }

    foreach ( $items as $item ) {
        if ( '.' === $item || '..' === $item ) {
            continue;
        }
        $candidate = $dir . DIRECTORY_SEPARATOR . $item;
        if ( is_dir( $candidate ) && file_exists( trailingslashit( $candidate ) . 'style.css' ) ) {
            return $candidate;
        }
    }

    return null;
}

function gtu_replace_theme_from_zip( string $zip_path, string $slug, array &$debug ) {
    if ( ! class_exists( 'ZipArchive' ) ) {
        return new WP_Error( 'gtu_no_ziparchive', 'Estensione PHP ZipArchive non disponibile.' );
    }

    $themes_dir = get_theme_root();
    $fs         = gtu_filesystem();
    if ( ! is_dir( $themes_dir ) || ! $fs || ! $fs->is_writable( $themes_dir ) ) {
        return new WP_Error( 'gtu_theme_root_not_writable', 'La cartella temi non è scrivibile: ' . $themes_dir );
    }

    $tmp_dir = trailingslashit( get_temp_dir() ) . 'gtu-unpack-' . $slug . '-' . time() . '-' . wp_rand( 1000, 9999 );
    if ( ! wp_mkdir_p( $tmp_dir ) ) {
        return new WP_Error( 'gtu_tmp_failed', 'Impossibile creare la cartella temporanea.' );
    }

    $zip = new ZipArchive();
    $opened = $zip->open( $zip_path );
    if ( true !== $opened ) {
        gtu_rrmdir( $tmp_dir );
        return new WP_Error( 'gtu_zip_open_failed', 'Impossibile aprire lo zip del repository.' );
    }

    if ( ! $zip->extractTo( $tmp_dir ) ) {
        $zip->close();
        gtu_rrmdir( $tmp_dir );
        return new WP_Error( 'gtu_zip_extract_failed', 'Impossibile estrarre lo zip del repository.' );
    }
    $zip->close();

    $source = gtu_find_theme_source_dir( $tmp_dir );
    if ( ! $source ) {
        gtu_rrmdir( $tmp_dir );
        return new WP_Error( 'gtu_not_theme_zip', 'Lo zip non contiene un tema WordPress valido: style.css non trovato nella cartella principale.' );
    }

    $style_css = trailingslashit( $source ) . 'style.css';
    $style_head = function_exists( 'get_file_data' )
        ? get_file_data( $style_css, [ 'name' => 'Theme Name', 'version' => 'Version' ] )
        : [ 'name' => '', 'version' => '' ];

    if ( empty( $style_head['name'] ) ) {
        gtu_rrmdir( $tmp_dir );
        return new WP_Error( 'gtu_invalid_style', 'style.css trovato, ma manca l\'header Theme Name.' );
    }

    $target = trailingslashit( $themes_dir ) . $slug;
    $backup = trailingslashit( $themes_dir ) . $slug . '.gtu-backup-' . time();
    $had_existing = is_dir( $target );

    if ( $had_existing && ! $fs->move( $target, $backup, true ) ) {
        gtu_rrmdir( $tmp_dir );
        return new WP_Error( 'gtu_backup_failed', 'Impossibile spostare temporaneamente il tema esistente. Controlla permessi file/cartelle.' );
    }

    $moved = $fs->move( $source, $target, true );
    if ( ! $moved ) {
        $copied = gtu_copy_dir( $source, $target );
        if ( ! $copied ) {
            gtu_rrmdir( $target );
            if ( $had_existing && is_dir( $backup ) ) {
                $fs->move( $backup, $target, true );
            }
            gtu_rrmdir( $tmp_dir );
            return new WP_Error(
                'gtu_replace_failed',
                'Impossibile copiare il nuovo tema nella cartella finale. Controlla permessi, owner dei file e spazio disco in wp-content/themes.'
            );
        }
    }

    if ( ! file_exists( trailingslashit( $target ) . 'style.css' ) ) {
        gtu_rrmdir( $target );
        if ( $had_existing && is_dir( $backup ) ) {
            $fs->move( $backup, $target, true );
        }
        gtu_rrmdir( $tmp_dir );
        return new WP_Error( 'gtu_replace_validation_failed', 'Il tema copiato non contiene style.css nella cartella finale.' );
    }

    gtu_rrmdir( $tmp_dir );
    $backup_removed = null;
    if ( $had_existing && is_dir( $backup ) ) {
        $backup_removed = gtu_rrmdir( $backup );
    }

    return [
        'source_folder'   => basename( $source ),
        'backup_removed'  => $backup_removed,
        'theme_name'      => $style_head['name'] ?? '',
        'theme_version'   => $style_head['version'] ?? '',
        'install_method'  => isset( $copied ) && $copied ? 'copy_dir' : 'move',
    ];
}

// ─── AJAX handlers ────────────────────────────────────────────────────────────

add_action( 'wp_ajax_gtu_clear_cache', function (): void {
    check_ajax_referer( 'gtu_clear_cache' );
    if ( current_user_can( 'manage_options' ) ) {
        delete_transient( 'gtu_release_cache' );
    }
    wp_send_json_success( [ 'cleared' => true ] );
} );

add_action( 'wp_ajax_gtu_diagnose', function (): void {
    check_ajax_referer( 'gtu_diagnose' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => 'Permessi insufficienti.' ] );
    }

    $opts  = gtu_get_settings();
    $owner = $opts['repo_owner'] ?? '';
    $repo  = $opts['repo_name']  ?? '';
    $out   = [ 'repo_url' => $opts['repo_url'] ?? '', 'owner' => $owner, 'repo' => $repo, 'branches' => [], 'repo_info' => null, 'error' => null ];

    if ( '' === $owner || '' === $repo ) {
        $out['error'] = 'Configura owner e repository prima della diagnosi.';
        wp_send_json_error( $out );
    }

    $r = wp_remote_get( gtu_remote_api( $owner, $repo ), [
        'headers' => gtu_remote_headers( $opts ),
        'timeout' => 10,
    ] );

    if ( is_wp_error( $r ) || 200 !== wp_remote_retrieve_response_code( $r ) ) {
        $out['error'] = is_wp_error( $r ) ? $r->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $r );
        wp_send_json_error( $out );
    }

    $b = json_decode( wp_remote_retrieve_body( $r ) );
    $out['repo_info'] = [
        'full_name'      => $b->full_name      ?? '?',
        'default_branch' => $b->default_branch ?? '?',
        'private'        => $b->private        ?? '?',
    ];

    $r2 = wp_remote_get( gtu_remote_api( $owner, $repo, 'branches?per_page=50' ), [
        'headers' => gtu_remote_headers( $opts ),
        'timeout' => 10,
    ] );

    if ( ! is_wp_error( $r2 ) && 200 === wp_remote_retrieve_response_code( $r2 ) ) {
        $branches = json_decode( wp_remote_retrieve_body( $r2 ) );
        if ( is_array( $branches ) ) {
            $out['branches'] = array_map( fn( $br ) => $br->name, $branches );
        }
    }

    wp_send_json_success( $out );
} );

add_action( 'wp_ajax_gtu_force_install', function (): void {
    check_ajax_referer( 'gtu_force_install' );
    if ( ! current_user_can( 'update_themes' ) ) {
        wp_send_json_error( [ 'message' => 'Permessi insufficienti.' ] );
    }

    $opts   = gtu_get_settings();
    $owner  = $opts['repo_owner']  ?? '';
    $repo   = $opts['repo_name']   ?? '';
    $slug   = $opts['theme_slug']    ?? '';
    $branch = $opts['repo_branch'] ?? 'main';

    if ( '' === $owner || '' === $repo || '' === $slug ) {
        wp_send_json_error( [
            'raw_error' => 'Configura tema, owner e repository prima di installare.',
        ] );
    }

    $package_url = gtu_remote_api( $owner, $repo, 'zipball/' . rawurlencode( $branch ) );
    $zip_path    = get_temp_dir() . 'gtu-' . $slug . '-' . time() . '.zip';

    $debug = [
        'package_url' => $package_url,
        'branch'      => $branch,
        'raw_error'   => null,
        'install'     => null,
        'hint'        => null,
    ];

    // Pre-check: distingue repo inesistente, branch errato e token mancante/non autorizzato.
    $repo_check = wp_remote_get( gtu_remote_api( $owner, $repo ), [
        'headers' => gtu_remote_headers( $opts ),
        'timeout' => 15,
    ] );

    if ( is_wp_error( $repo_check ) ) {
        $debug['raw_error'] = 'Repo check: ' . $repo_check->get_error_message();
        wp_send_json_error( $debug );
    }

    $repo_code = wp_remote_retrieve_response_code( $repo_check );
    $repo_body = json_decode( wp_remote_retrieve_body( $repo_check ), true );
    $debug['repo_check_code'] = $repo_code;
    if ( 200 !== $repo_code ) {
        $debug['remote_message'] = $repo_body['message'] ?? null;
        $debug['raw_error'] = "Repository non accessibile: HTTP {$repo_code}.";
        $debug['hint'] = 'Controlla owner/repo. Se è privato, il token deve avere accesso al repository. Controlla che owner/repo esistano e che il token abbia accesso al repository privato.';
        wp_send_json_error( $debug );
    }

    $default_branch = $repo_body['default_branch'] ?? null;
    $branch_check = wp_remote_get( gtu_remote_api( $owner, $repo, 'commits/' . rawurlencode( $branch ) ), [
        'headers' => gtu_remote_headers( $opts ),
        'timeout' => 15,
    ] );
    $branch_code = is_wp_error( $branch_check ) ? 0 : wp_remote_retrieve_response_code( $branch_check );
    $debug['branch_check_code'] = $branch_code;
    $debug['default_branch'] = $default_branch;
    if ( 200 !== $branch_code ) {
        $debug['raw_error'] = "Branch/ref '{$branch}' non trovato: HTTP {$branch_code}.";
        $debug['hint'] = $default_branch ? "Prova branch '{$default_branch}'." : 'Verifica il nome del branch o usa un tag/SHA valido.';
        wp_send_json_error( $debug );
    }

    $fetched = gtu_fetch_zip( $package_url, $opts, $zip_path );
    if ( is_wp_error( $fetched ) ) {
        $data = $fetched->get_error_data();
        $debug['step1_code']   = is_array( $data ) ? ( $data['step1'] ?? null ) : null;
        $debug['step2_code']   = is_array( $data ) ? ( $data['step2'] ?? null ) : null;
        $debug['download_url'] = is_array( $data ) ? ( $data['download_url'] ?? null ) : null;
        $debug['raw_error']    = $fetched->get_error_message();
        $debug['hint']         = $debug['hint'] ?: 'Se vedi HTTP 404, repository, branch o token non sono corretti.';
        wp_send_json_error( $debug );
    }

    $debug['step1_code']   = $fetched['step1'];
    $debug['step2_code']   = $fetched['step2'];
    $debug['download_url'] = $fetched['download_url'];
    $debug['zip_size_kb']  = round( $fetched['bytes'] / 1024, 1 );

    // Installa dal file locale con sostituzione della cartella tema.
    // Theme_Upgrader::install() su alcuni WordPress non sovrascrive davvero la
    // cartella esistente e restituisce solo "Il pacchetto non può essere installato".
    $result = gtu_replace_theme_from_zip( $zip_path, $slug, $debug );
    wp_delete_file( $zip_path );

    if ( is_wp_error( $result ) ) {
        $debug['install'] = [
            'result'       => $result->get_error_message(),
            'error_code'   => $result->get_error_code(),
            'theme_exists' => is_dir( get_theme_root() . '/' . $slug ),
        ];
        wp_send_json_error( $debug );
    }

    // Salva SHA installato
    $updater = GTU_Updater::get_instance();
    $sha = $updater->fetch_branch_sha( $opts, $branch );
    if ( $sha ) {
        update_option( 'gtu_installed_sha_' . $slug, $sha );
        $debug['installed_sha'] = substr( $sha, 0, 7 );
    }

    $debug['install'] = [
        'result'          => 'ok',
        'method'          => 'manual_replace',
        'source_folder'   => $result['source_folder'] ?? null,
        'theme_exists'    => is_dir( get_theme_root() . '/' . $slug ),
        'previous_backup' => $result['backup_removed'] ?? null,
    ];

    wp_send_json_success( $debug );
} );

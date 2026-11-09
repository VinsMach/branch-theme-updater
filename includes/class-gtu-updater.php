<?php
/**
 * Hooking nel sistema nativo di aggiornamenti di WordPress per i temi.
 *
 * Strategia (nessuna release necessaria):
 *  1. pre_set_site_transient_update_themes
 *     → chiama /repos/{owner}/{repo}/commits/{branch} per ottenere il commit SHA
 *     → se il SHA è diverso dall'ultimo installato, propone aggiornamento
 *  2. http_request_args
 *     → aggiunge Authorization e header Accept corretti per API zipball/codeload
 *  3. upgrader_process_complete
 *     → rinomina la cartella estratta (owner-repo-sha → slug atteso)
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class GTU_Updater {

    private static ?GTU_Updater $instance = null;
    private array $opts = [];

    public static function get_instance(): GTU_Updater {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->opts = gtu_get_settings();

        add_action( 'update_option_' . GTU_OPTION_KEY, [ $this, 'reload_opts' ] );

        add_filter( 'pre_set_site_transient_update_themes', [ $this, 'check_for_update' ], 100 );
        add_filter( 'http_request_args',                   [ $this, 'inject_auth_header' ], 10, 2 );
        add_filter( 'upgrader_source_selection',           [ $this, 'fix_zipball_source' ], 10, 4 );
        add_filter( 'upgrader_package_options',            [ $this, 'allow_theme_overwrite' ], 10, 1 );
        add_action( 'upgrader_process_complete',           [ $this, 'after_upgrade' ], 10, 2 );
    }

    public function reload_opts(): void {
        $this->opts = gtu_get_settings();
    }

    /* ------------------------------------------------------------------ */
    /* 1. Controllo aggiornamenti via commit SHA                            */
    /* ------------------------------------------------------------------ */

    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $opts = $this->opts;

        if ( empty( $opts['theme_slug'] ) || empty( $opts['repo_owner'] ) ||
             empty( $opts['repo_name'] ) ) {
            return $transient;
        }

        $theme_slug = $opts['theme_slug'];
        $theme      = wp_get_theme( $theme_slug );

        if ( ! $theme->exists() ) {
            return $transient;
        }

        $branch = $opts['repo_branch'] ?? 'main';
        $owner  = $opts['repo_owner'];
        $repo   = $opts['repo_name'];

        $remote_sha = $this->fetch_branch_sha( $opts, $branch );

        if ( ! $remote_sha ) {
            return $transient;
        }

        $stored_sha = get_option( 'gtu_installed_sha_' . $theme_slug, '' );

        if ( $remote_sha !== $stored_sha ) {
            $current     = $theme->get( 'Version' );
            $short_sha   = substr( $remote_sha, 0, 7 );
            $new_version = $current . '+git.' . $short_sha;

            $transient->response[ $theme_slug ] = [
                'theme'       => $theme_slug,
                'new_version' => $new_version,
                'url'         => gtu_repo_tree_url( $owner, $repo, $branch ),
                'package'     => gtu_remote_api( $owner, $repo, 'zipball/' . rawurlencode( $branch ) ),
            ];
        } else {
            unset( $transient->response[ $theme_slug ] );
        }

        return $transient;
    }

    /* ------------------------------------------------------------------ */
    /* 2. Inject Authorization per download zipball                         */
    /* ------------------------------------------------------------------ */

    public function inject_auth_header( array $parsed_args, string $url ): array {
        $opts = $this->opts;

        if ( empty( $opts['repo_owner'] ) || empty( $opts['repo_name'] ) ) {
            return $parsed_args;
        }

        $owner = $opts['repo_owner'];
        $repo  = $opts['repo_name'];

        $api_repo_path = "/repos/{$owner}/{$repo}";
        $is_remote_api  = ( false !== strpos( $url, 'api.github.com' ) && false !== strpos( $url, $api_repo_path ) );
        $is_zipball     = ( $is_remote_api && false !== strpos( $url, '/zipball/' ) );
        $is_codeload    = ( false !== strpos( $url, 'codeload.github.com' ) && false !== strpos( $url, "/{$owner}/{$repo}/" ) );

        // Intervieni solo sulle richieste di download del repository monitorato.
        if ( ! $is_zipball && ! $is_codeload ) {
            return $parsed_args;
        }

        if ( ! isset( $parsed_args['headers'] ) || ! is_array( $parsed_args['headers'] ) ) {
            $parsed_args['headers'] = [];
        }
        if ( ! empty( $opts['repo_token'] ) ) {
            $parsed_args['headers']['Authorization'] = 'token ' . $opts['repo_token'];
        }
        $parsed_args['headers']['User-Agent'] = $opts['user_agent'] ?: 'wordpress-branch-theme-updater';

        // /zipball è ancora un endpoint dell'API: con application/octet-stream risponde 415.
        // Il file vero arriva poi dall'host di download.
        $parsed_args['headers']['Accept'] = $is_zipball
            ? 'application/vnd.github+json'
            : 'application/zip, application/octet-stream;q=0.9, */*;q=0.8';

        $parsed_args['redirection']        = 5;
        $parsed_args['reject_unsafe_urls'] = false;

        return $parsed_args;
    }

    /**
     * Scarica lo zip del branch e restituisce il percorso del file temporaneo,
     * oppure null in caso di errore. Funziona sia con repository pubblici sia privati.
     */
    public function download_zipball( array $opts, string $branch ): ?string {
        $url  = gtu_remote_api( $opts['repo_owner'], $opts['repo_name'], 'zipball/' . rawurlencode( $branch ) );
        $dest = get_temp_dir() . 'gtu-' . $opts['theme_slug'] . '-' . time() . '.zip';
        $fetched = gtu_fetch_zip( $url, $opts, $dest );

        if ( is_wp_error( $fetched ) ) {
            return null;
        }

        return $dest;
    }


    /**
     * WordPress valida il tema prima di chiamare upgrader_process_complete.
     * Lo zip del repository viene estratto in una cartella tipo owner-repo-sha.
     * Qui rinominiamo la sorgente PRIMA della validazione/installazione.
     */
    public function fix_zipball_source( $source, $remote_source, $upgrader, $hook_extra ) {
        $opts       = $this->opts;
        $theme_slug = $opts['theme_slug'] ?? '';

        if ( ! $theme_slug || empty( $opts['repo_owner'] ) || empty( $opts['repo_name'] ) ) {
            return $source;
        }

        if ( empty( $hook_extra['theme'] ) && empty( $hook_extra['themes'] ) && empty( $hook_extra['type'] ) ) {
            return $source;
        }

        if ( ! is_dir( $source ) ) {
            return $source;
        }

        $style_css = trailingslashit( $source ) . 'style.css';
        if ( ! file_exists( $style_css ) ) {
            return $source;
        }

        $basename = basename( untrailingslashit( $source ) );
        if ( $basename === $theme_slug ) {
            return $source;
        }

        $owner  = strtolower( $opts['repo_owner'] );
        $repo   = strtolower( $opts['repo_name'] );
        $prefix = strtolower( $owner . '-' . $repo . '-' );

        // Evita di toccare pacchetti non provenienti da questo repository.
        if ( strpos( strtolower( $basename ), $prefix ) !== 0 ) {
            return $source;
        }

        global $wp_filesystem;
        $target = trailingslashit( $remote_source ) . $theme_slug;

        if ( $wp_filesystem && $wp_filesystem->exists( $target ) ) {
            $wp_filesystem->delete( $target, true );
        }

        if ( $wp_filesystem && $wp_filesystem->move( $source, $target, true ) ) {
            return $target;
        }

        return $source;
    }


    /**
     * Quando l'aggiornamento passa dal sistema nativo WordPress, consenti
     * la sovrascrittura della cartella tema esistente. Senza questo,
     * install_package() può fermarsi con "Il pacchetto non può essere installato"
     * se la destinazione wp-content/themes/{slug} esiste già.
     */
    public function allow_theme_overwrite( array $options ): array {
        $opts       = $this->opts;
        $theme_slug = $opts['theme_slug'] ?? '';

        if ( ! $theme_slug ) {
            return $options;
        }

        $hook_extra = $options['hook_extra'] ?? [];
        $is_target_theme = false;

        if ( isset( $hook_extra['theme'] ) && $hook_extra['theme'] === $theme_slug ) {
            $is_target_theme = true;
        }
        if ( isset( $hook_extra['themes'] ) && in_array( $theme_slug, (array) $hook_extra['themes'], true ) ) {
            $is_target_theme = true;
        }

        if ( ! $is_target_theme ) {
            return $options;
        }

        $options['clear_destination']           = true;
        $options['abort_if_destination_exists'] = false;

        return $options;
    }

    /* ------------------------------------------------------------------ */
    /* 3. Post-upgrade: rinomina cartella estratta dal zipball              */
    /* ------------------------------------------------------------------ */

    public function after_upgrade( WP_Upgrader $upgrader, array $hook_extra ): void {
        if ( empty( $hook_extra['themes'] ) ) {
            return;
        }

        $opts       = $this->opts;
        $theme_slug = $opts['theme_slug'] ?? '';

        if ( ! in_array( $theme_slug, (array) $hook_extra['themes'], true ) ) {
            return;
        }

        $this->rename_extracted_folder( $opts, $theme_slug );

        // Salva il SHA appena installato per il confronto futuro
        $branch = $opts['repo_branch'] ?? 'main';
        $sha    = $this->fetch_branch_sha( $opts, $branch );
        if ( $sha ) {
            update_option( 'gtu_installed_sha_' . $theme_slug, $sha );
        }

        $cache_key = 'gtu_branch_sha_' . md5( $opts['repo_owner'] . $opts['repo_name'] . $branch );
        delete_transient( $cache_key );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers pubblici (usati anche dall'AJAX handler)                     */
    /* ------------------------------------------------------------------ */

    public function rename_extracted_folder( array $opts, string $theme_slug ): void {
        $themes_dir   = get_theme_root();
        $expected_dir = $themes_dir . '/' . $theme_slug;

        if ( is_dir( $expected_dir ) ) {
            return;
        }

        $owner    = $opts['repo_owner'] ?? '';
        $repo     = $opts['repo_name']  ?? '';
        $prefix   = strtolower( $owner . '-' . $repo . '-' );

        $iterator = new DirectoryIterator( $themes_dir );
        foreach ( $iterator as $item ) {
            if ( $item->isDot() || ! $item->isDir() ) {
                continue;
            }
            if ( strpos( strtolower( $item->getFilename() ), $prefix ) === 0 ) {
                $fs = gtu_filesystem();
                if ( $fs && $fs->move( $item->getPathname(), $expected_dir, true ) ) {
                    if ( get_option( 'stylesheet' ) === $theme_slug || get_option( 'template' ) === $theme_slug ) {
                        switch_theme( $theme_slug );
                    }
                }
                break;
            }
        }
    }

    public function fetch_branch_sha( array $opts, string $branch ): ?string {
        $cache_key = 'gtu_branch_sha_' . md5( $opts['repo_owner'] . $opts['repo_name'] . $branch );
        $cached    = get_transient( $cache_key );
        if ( $cached ) {
            return $cached;
        }

        $response = wp_remote_get(
            gtu_remote_api( $opts['repo_owner'], $opts['repo_name'], 'commits/' . rawurlencode( $branch ) ),
            [
                'headers' => gtu_remote_headers( $opts ),
                'timeout' => 10,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return null;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( 200 !== $code ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ) );
        $sha  = $body->sha ?? null;

        if ( $sha ) {
            set_transient( $cache_key, $sha, 15 * MINUTE_IN_SECONDS );
        }

        return $sha;
    }
}

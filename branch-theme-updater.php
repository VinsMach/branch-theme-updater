<?php
/**
 * Plugin Name:       Branch Theme Updater
 * Plugin URI:        https://wordpress.org/plugins/branch-theme-updater/
 * Description:       Aggiorna qualsiasi tema WordPress da un repository remoto, pubblico o privato, con gli aggiornamenti nativi di WordPress.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            mesciu
 * Author URI:        https://profiles.wordpress.org/mesciu/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       branch-theme-updater
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GTU_VERSION',     '1.1.0' );
define( 'GTU_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'GTU_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'GTU_OPTION_KEY',  'gtu_settings' );

/**
 * Impostazioni vuote. Nessun tema o repository è preconfigurato:
 * chi installa il plugin sceglie il proprio.
 *
 * @return array<string, string>
 */
function gtu_default_settings(): array {
    return [
        'theme_slug'    => '',
        'repo_url'    => '',
        'repo_owner'  => '',
        'repo_name'   => '',
        'repo_branch' => 'main',
        'repo_token'  => '',
        'user_agent'    => '',
    ];
}

/**
 * @return array<string, string>
 */
function gtu_get_settings(): array {
    $saved = get_option( GTU_OPTION_KEY, [] );
    if ( ! is_array( $saved ) ) {
        $saved = [];
    }

    return array_merge( gtu_default_settings(), $saved );
}

function gtu_remote_api( string $owner, string $repo, string $path = '' ): string {
    $url = 'https://api.github.com/repos/' . rawurlencode( $owner ) . '/' . rawurlencode( $repo );
    if ( '' !== $path ) {
        $url .= '/' . ltrim( $path, '/' );
    }

    return $url;
}

function gtu_repo_tree_url( string $owner, string $repo, string $branch ): string {
    return sprintf(
        'https://github.com/%s/%s/tree/%s',
        rawurlencode( $owner ),
        rawurlencode( $repo ),
        str_replace( '%2F', '/', rawurlencode( $branch ) )
    );
}

function gtu_filesystem() {
    global $wp_filesystem;

    if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
        return $wp_filesystem;
    }

    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    WP_Filesystem();

    global $wp_filesystem;

    return ( $wp_filesystem instanceof WP_Filesystem_Base ) ? $wp_filesystem : null;
}

/**
 * Scarica lo zip di un branch. La prima risposta è spesso un redirect:
 * il file vero si scarica senza inviare di nuovo il token.
 *
 * @return array{step1:int,step2:?int,download_url:?string,bytes:int}|WP_Error
 */
function gtu_fetch_zip( string $package_url, array $opts, string $dest ) {
    $ua    = $opts['user_agent'] ?: 'wordpress-branch-theme-updater';
    $first = wp_remote_get(
        $package_url,
        [
            'headers'            => gtu_remote_headers( $opts ),
            'timeout'            => 30,
            'redirection'        => 0,
            'reject_unsafe_urls' => false,
        ]
    );

    if ( is_wp_error( $first ) ) {
        return $first;
    }

    $code     = (int) wp_remote_retrieve_response_code( $first );
    $location = wp_remote_retrieve_header( $first, 'location' );
    if ( is_array( $location ) ) {
        $location = (string) end( $location );
    }
    $location = is_string( $location ) ? trim( $location ) : '';

    if ( '' !== $location && in_array( $code, [ 301, 302, 303, 307, 308 ], true ) ) {
        $second = wp_remote_get(
            $location,
            [
                'headers'            => [ 'User-Agent' => $ua ],
                'timeout'            => 120,
                'redirection'        => 5,
                'reject_unsafe_urls' => false,
            ]
        );

        if ( is_wp_error( $second ) ) {
            return $second;
        }

        $code2 = (int) wp_remote_retrieve_response_code( $second );
        $body  = wp_remote_retrieve_body( $second );
        if ( 200 !== $code2 || '' === $body ) {
            return new WP_Error(
                'gtu_download_failed',
                'Step2 HTTP ' . $code2,
                [
                    'step1'        => $code,
                    'step2'        => $code2,
                    'download_url' => $location,
                ]
            );
        }

        if ( ! gtu_write_file( $dest, $body ) ) {
            return new WP_Error( 'gtu_write_failed', 'Impossibile scrivere lo zip.' );
        }

        return [
            'step1'        => $code,
            'step2'        => $code2,
            'download_url' => $location,
            'bytes'        => strlen( $body ),
        ];
    }

    $body = wp_remote_retrieve_body( $first );
    if ( 200 === $code && strlen( $body ) > 10000 ) {
        if ( ! gtu_write_file( $dest, $body ) ) {
            return new WP_Error( 'gtu_write_failed', 'Impossibile scrivere lo zip.' );
        }

        return [
            'step1'        => $code,
            'step2'        => null,
            'download_url' => null,
            'bytes'        => strlen( $body ),
        ];
    }

    return new WP_Error(
        'gtu_download_failed',
        "Step1 HTTP {$code} — nessun redirect e nessun body zip.",
        [
            'step1'        => $code,
            'download_url' => '' !== $location ? $location : null,
        ]
    );
}

function gtu_write_file( string $path, string $contents ): bool {
    $fs = gtu_filesystem();
    if ( ! $fs ) {
        return false;
    }

    return false !== $fs->put_contents( $path, $contents, FS_CHMOD_FILE );
}

require_once GTU_PLUGIN_DIR . 'includes/class-gtu-settings.php';
require_once GTU_PLUGIN_DIR . 'includes/class-gtu-updater.php';

function gtu_init(): void {
    GTU_Settings::get_instance();
    GTU_Updater::get_instance();
}
add_action( 'plugins_loaded', 'gtu_init' );

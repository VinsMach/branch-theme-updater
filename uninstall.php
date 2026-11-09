<?php
/**
 * Pulizia alla disinstallazione. WordPress carica questo file da solo,
 * senza il resto del plugin.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'gtu_settings' );
delete_transient( 'gtu_release_cache' );

global $wpdb;

// Non esiste un'API per cancellare opzioni e transient per prefisso.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like( 'gtu_installed_sha_' ) . '%',
        $wpdb->esc_like( '_transient_gtu_' ) . '%',
        $wpdb->esc_like( '_transient_timeout_gtu_' ) . '%'
    )
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

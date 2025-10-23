<?php
/**
 * Plugin Name: M&I Master Class Registration
 * Plugin URI: https://m-and-i.pl
 * Description: Formularz rejestracyjny na wydarzenie M&I Master Class z Marco Illiges
 * Version: 1.0.0
 * Author: M&I
 * Text Domain: mi-masterclass
 */

// Zabezpieczenie przed bezpośrednim dostępem
if (!defined('ABSPATH')) {
    exit;
}

// Stałe wtyczki
define('MI_MASTERCLASS_VERSION', '1.0.0');
define('MI_MASTERCLASS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MI_MASTERCLASS_PLUGIN_URL', plugin_dir_url(__FILE__));

// Aktywacja wtyczki - tworzenie tabeli w bazie danych
register_activation_hook(__FILE__, 'mi_masterclass_activate');
function mi_masterclass_activate() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'mi_masterclass_registrations';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        salon_name varchar(100) NOT NULL,
        email varchar(100) NOT NULL,
        phone varchar(20) NOT NULL,
        event_date varchar(50) NOT NULL,
        event_time varchar(20) NOT NULL,
        participation_type varchar(50) NOT NULL,
        is_partner varchar(5) NOT NULL,
        registration_date datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Dodanie domyślnych opcji
    add_option('mi_masterclass_notification_emails', get_option('admin_email'));
    add_option('mi_masterclass_active_limit', 8);
    add_option('mi_masterclass_observer_limit', 22);
}

// Deaktywacja wtyczki
register_deactivation_hook(__FILE__, 'mi_masterclass_deactivate');
function mi_masterclass_deactivate() {
    // Czyszczenie cache itp.
}

// Ładowanie plików wtyczki tylko jeśli klasy nie istnieją
if (!class_exists('MI_Masterclass_Admin')) {
    require_once MI_MASTERCLASS_PLUGIN_DIR . 'includes/class-mi-masterclass-admin.php';
}
if (!class_exists('MI_Masterclass_Form')) {
    require_once MI_MASTERCLASS_PLUGIN_DIR . 'includes/class-mi-masterclass-form.php';
}
if (!class_exists('MI_Masterclass_Ajax')) {
    require_once MI_MASTERCLASS_PLUGIN_DIR . 'includes/class-mi-masterclass-ajax.php';
}

// Inicjalizacja klas
add_action('plugins_loaded', 'mi_masterclass_init_classes');
function mi_masterclass_init_classes() {
    // Sprawdź czy klasy istnieją i nie zostały już zainicjalizowane
    static $initialized = false;
    
    if (!$initialized) {
        if (class_exists('MI_Masterclass_Admin') && is_admin()) {
            new MI_Masterclass_Admin();
        }
        if (class_exists('MI_Masterclass_Form')) {
            new MI_Masterclass_Form();
        }
        if (class_exists('MI_Masterclass_Ajax')) {
            new MI_Masterclass_Ajax();
        }
        $initialized = true;
    }
}

// Ładowanie stylów i skryptów
add_action('wp_enqueue_scripts', 'mi_masterclass_enqueue_scripts');
function mi_masterclass_enqueue_scripts() {
    wp_enqueue_style('mi-masterclass-style', MI_MASTERCLASS_PLUGIN_URL . 'assets/css/style.css', array(), MI_MASTERCLASS_VERSION);
    wp_enqueue_script('mi-masterclass-script', MI_MASTERCLASS_PLUGIN_URL . 'assets/js/script.js', array('jquery'), MI_MASTERCLASS_VERSION, true);

    if (!is_admin() && is_user_logged_in()) {
        wp_enqueue_style('dashicons');
    }

    wp_localize_script('mi-masterclass-script', 'mi_masterclass_ajax', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('mi_masterclass_nonce')
    ));
}

// Ładowanie stylów admina
add_action('admin_enqueue_scripts', 'mi_masterclass_admin_enqueue_scripts');
function mi_masterclass_admin_enqueue_scripts($hook) {
    if (strpos($hook, 'mi-masterclass') !== false) {
        wp_enqueue_style('mi-masterclass-admin-style', MI_MASTERCLASS_PLUGIN_URL . 'assets/css/admin-style.css', array(), MI_MASTERCLASS_VERSION);
    }
}
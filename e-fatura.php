<?php
/**
 * Plugin Name: E-Fatura
 * Plugin URI: https://github.com/erayusta/efatura
 * Description: WooCommerce siparişlerine PDF fatura yükleme ve müşteri indirme özelliği
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL v2 or later
 * Text Domain: e-fatura
 */

// Direkt erişimi engelle
if (!defined('ABSPATH')) {
    exit;
}

// Eklenti sabitleri
define('EFATURA_VERSION', '1.0.0');
define('EFATURA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EFATURA_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EFATURA_UPLOAD_DIR', wp_upload_dir()['basedir'] . '/e-fatura-invoices');
define('EFATURA_UPLOAD_URL', wp_upload_dir()['baseurl'] . '/e-fatura-invoices');

// Not: WordPress 4.6+ için load_plugin_textdomain otomatikleşmiştir (Plugin Check önerisi)

// Eklenti aktivasyonu
register_activation_hook(__FILE__, 'efatura_activate');
function efatura_activate() {
    efatura_create_db_table();
    efatura_create_upload_directory();
    
    // Güvenlik için .htaccess dosyası oluştur
    $htaccess_file = EFATURA_UPLOAD_DIR . '/.htaccess';
    if (!file_exists($htaccess_file)) {
        $htaccess_content = "Options -Indexes\n";
        $htaccess_content .= "<Files ~ \"\\.(php|php\\.|php3|php4|php5|php6|phps|cgi|pl|asp|aspx|shtml|shtm|fcgi|fpl|jsp|htm|html|wml)$\">\n";
        $htaccess_content .= "Order Deny,Allow\n";
        $htaccess_content .= "Deny from all\n";
        $htaccess_content .= "</Files>\n";
        file_put_contents($htaccess_file, $htaccess_content);
    }
    
    // index.php dosyası oluştur (dizin listesini engelle)
    $index_file = EFATURA_UPLOAD_DIR . '/index.php';
    if (!file_exists($index_file)) {
        file_put_contents($index_file, '<?php // Silence is golden');
    }
}

// Veritabanı tablosu oluştur
function efatura_create_db_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'efatura_invoices';
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        order_id bigint(20) NOT NULL,
        file_name varchar(255) NOT NULL,
        file_path varchar(500) NOT NULL,
        upload_date datetime DEFAULT CURRENT_TIMESTAMP,
        uploaded_by bigint(20) NOT NULL,
        PRIMARY KEY (id),
        KEY order_id (order_id)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Upload dizini oluştur
function efatura_create_upload_directory() {
    if (!file_exists(EFATURA_UPLOAD_DIR)) {
        wp_mkdir_p(EFATURA_UPLOAD_DIR);
    }
}

// Eklenti deaktivasyonu
register_deactivation_hook(__FILE__, 'efatura_deactivate');
function efatura_deactivate() {
    // Temizlik işlemleri
}

// Gerekli dosyaları dahil et
require_once EFATURA_PLUGIN_DIR . 'includes/class-efatura-security.php';
require_once EFATURA_PLUGIN_DIR . 'includes/class-efatura-admin.php';
require_once EFATURA_PLUGIN_DIR . 'includes/class-efatura-public.php';

// Sınıfları başlat
if (is_admin()) {
    new EFatura_Admin();
}
new EFatura_Public();
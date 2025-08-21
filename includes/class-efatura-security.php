<?php
/**
 * Güvenlik sınıfı
 */

if (!defined('ABSPATH')) {
    exit;
}

class EFatura_Security {
    
    /**
     * Dosya yükleme güvenlik kontrolü
     */
    public static function validate_upload($file) {
        $allowed_types = array('application/pdf');
        $max_size = 10 * 1024 * 1024; // 10MB
        
        // MIME tip kontrolü
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if (!in_array($mime_type, $allowed_types)) {
            return new WP_Error('invalid_file_type', __('Sadece PDF dosyaları yüklenebilir.', 'e-fatura'));
        }
        
        // Dosya uzantısı kontrolü
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file_ext !== 'pdf') {
            return new WP_Error('invalid_extension', __('Geçersiz dosya uzantısı.', 'e-fatura'));
        }
        
        // Dosya boyutu kontrolü
        if ($file['size'] > $max_size) {
            return new WP_Error('file_too_large', __('Dosya boyutu 10MB\'dan büyük olamaz.', 'e-fatura'));
        }
        
        // PDF içeriği kontrolü (magic bytes)
        // WP_Filesystem ile kontrol
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        $header = '';
        if ($wp_filesystem && method_exists($wp_filesystem, 'get_contents')) {
            $header = substr($wp_filesystem->get_contents($file['tmp_name']), 0, 5);
        } else {
            return new WP_Error('fs_error', __('Dosya sistemi başlatılamadı.', 'e-fatura'));
        }
        
        if ($header !== '%PDF-') {
            return new WP_Error('invalid_pdf', __('Geçersiz PDF dosyası.', 'e-fatura'));
        }
        
        return true;
    }
    
    /**
     * Kullanıcı yetkisi kontrolü - Admin
     */
    public static function can_upload_invoice($user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        return user_can($user_id, 'manage_woocommerce');
    }
    
    /**
     * Kullanıcı yetkisi kontrolü - Müşteri
     */
    public static function can_download_invoice($order_id, $user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        // Admin her zaman indirebilir
        if (user_can($user_id, 'manage_woocommerce')) {
            return true;
        }
        
        // Sipariş sahibi kontrolü
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        // Müşteri kendi siparişini görebilir
        if ($order->get_customer_id() == $user_id) {
            return true;
        }
        
        // Misafir siparişler için order key kontrolü (WooCommerce standart akışı)
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!is_user_logged_in() && isset($_GET['key'])) {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Recommended
            $order_key = sanitize_text_field( wp_unslash( $_GET['key'] ) );
            // WooCommerce order key doğrulaması - nonce gerektirmez
            // Bu WooCommerce'ın standart güvenlik mekanizmasıdır
            if ($order->get_order_key() === $order_key) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Güvenli dosya adı oluştur
     */
    public static function generate_secure_filename($order_id, $original_name) {
        $ext = pathinfo($original_name, PATHINFO_EXTENSION);
        $hash = wp_hash($order_id . time() . wp_rand());
        return 'invoice_' . $order_id . '_' . $hash . '.' . $ext;
    }
    
    /**
     * XSS koruması için veri temizleme
     */
    public static function sanitize_output($data) {
        if (is_array($data)) {
            return array_map([self::class, 'sanitize_output'], $data);
        }
        return esc_html($data);
    }
    
    /**
     * Nonce doğrulama
     */
    public static function verify_nonce($nonce, $action) {
        return wp_verify_nonce($nonce, $action);
    }
    
    /**
     * Rate limiting kontrolü
     */
    public static function check_rate_limit($action, $user_id = null) {
        if (!$user_id) {
            $user_id = get_current_user_id();
        }
        
        $transient_key = 'efatura_rate_' . $action . '_' . $user_id;
        $attempts = get_transient($transient_key);
        
        if ($attempts && $attempts >= 10) {
            return false;
        }
        
        $attempts = $attempts ? $attempts + 1 : 1;
        set_transient($transient_key, $attempts, 3600); // 1 saat
        
        return true;
    }
}
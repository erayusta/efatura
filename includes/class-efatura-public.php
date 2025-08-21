<?php
/**
 * Müşteri tarafı işlemleri
 */

if (!defined('ABSPATH')) {
    exit;
}

class EFatura_Public {
    
    public function __construct() {
        add_action('init', array($this, 'handle_invoice_download'));
        add_action('woocommerce_order_details_after_order_table', array($this, 'display_invoice_download_section'));
        add_action('woocommerce_account_orders_columns', array($this, 'add_invoice_column'));
        add_action('woocommerce_my_account_my_orders_column_invoice', array($this, 'add_invoice_column_content'));
        add_filter('woocommerce_email_attachments', array($this, 'attach_invoice_to_email'), 10, 4);
        
        // Veritabanı tablo adı
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'efatura_invoices';
    }
    
    /**
     * Fatura indirme işlemi
     */
    public function handle_invoice_download() {
        if (!isset($_GET['efatura_download'])) {
            return;
        }
        
        $invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $nonce = isset($_GET['nonce']) ? sanitize_text_field(wp_unslash($_GET['nonce'])) : '';
        
        // Nonce doğrulama
        if (!wp_verify_nonce($nonce, 'efatura_download_' . $invoice_id)) {
            wp_die(esc_html__('Güvenlik doğrulaması başarısız.', 'e-fatura'));
        }
        
        // Yetki kontrolü
        if (!EFatura_Security::can_download_invoice($order_id)) {
            wp_die(esc_html__('Bu faturayı indirme yetkiniz yok.', 'e-fatura'));
        }
        
        // Rate limiting
        if (!EFatura_Security::check_rate_limit('download')) {
            wp_die(esc_html__('Çok fazla indirme denemesi. Lütfen daha sonra tekrar deneyin.', 'e-fatura'));
        }
        
        // Fatura bilgisini al
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . "efatura_invoices WHERE id = %d AND order_id = %d",
            $invoice_id,
            $order_id
        ));
        
        if (!$invoice) {
            wp_die(esc_html__('Fatura bulunamadı.', 'e-fatura'));
        }
        
        $file_path = EFATURA_UPLOAD_DIR . '/' . $invoice->file_path;
        
        if (!file_exists($file_path)) {
            wp_die(esc_html__('Fatura dosyası bulunamadı.', 'e-fatura'));
        }
        
        // Güvenlik başlıkları
        nocache_headers();
        
        // PDF dosyasını gönder
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($invoice->file_name) . '"');
        header('Content-Transfer-Encoding: binary');
        header('Content-Length: ' . filesize($file_path));
        header('Accept-Ranges: bytes');
        
        // XSS koruması
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        
        // WP_Filesystem kullan
        global $wp_filesystem;
        if (empty($wp_filesystem)) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        if ($wp_filesystem && method_exists($wp_filesystem, 'get_contents')) {
            $pdf_contents = $wp_filesystem->get_contents($file_path);
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo $pdf_contents;
        } else {
            wp_die(esc_html__('Fatura dosyası okunamadı.', 'e-fatura'));
        }
        exit;
    }
    
    /**
     * Sipariş detay sayfasında fatura indirme bölümü
     */
    public function display_invoice_download_section($order) {
        if (!$order) {
            return;
        }
        
        $order_id = $order->get_id();
        
        // Yetki kontrolü
        if (!EFatura_Security::can_download_invoice($order_id)) {
            return;
        }
        
        $invoices = $this->get_order_invoices($order_id);
        
        if (empty($invoices)) {
            return;
        }
        ?>
        <div class="efatura-download-section">
            <h2><?php echo esc_html__('Faturalar', 'e-fatura'); ?></h2>
            <table class="woocommerce-table woocommerce-table--invoices shop_table">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Fatura', 'e-fatura'); ?></th>
                        <th><?php echo esc_html__('Tarih', 'e-fatura'); ?></th>
                        <th><?php echo esc_html__('İşlem', 'e-fatura'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $invoice) : ?>
                        <tr>
                            <td><?php echo esc_html($invoice->file_name); ?></td>
                            <td><?php echo esc_html(date_i18n(get_option('date_format'), strtotime($invoice->upload_date))); ?></td>
                            <td>
                                <a href="<?php echo esc_url($this->get_download_url($invoice->id, $order_id)); ?>" 
                                   class="woocommerce-button button" target="_blank">
                                    <?php echo esc_html__('İndir', 'e-fatura'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <style>
            .efatura-download-section {
                margin-top: 2em;
            }
            .woocommerce-table--invoices {
                margin-top: 1em;
            }
        </style>
        <?php
    }
    
    /**
     * Hesabım > Siparişler tablosuna fatura sütunu ekle
     */
    public function add_invoice_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            
            if ($key === 'order-status') {
                $new_columns['invoice'] = __('Fatura', 'e-fatura');
            }
        }
        
        return $new_columns;
    }
    
    /**
     * Fatura sütunu içeriği
     */
    public function add_invoice_column_content($order) {
        if (!$order) {
            return;
        }
        
        $order_id = $order->get_id();
        
        // Yetki kontrolü
        if (!EFatura_Security::can_download_invoice($order_id)) {
            echo '-';
            return;
        }
        
        $invoices = $this->get_order_invoices($order_id);
        
        if (empty($invoices)) {
            echo '-';
            return;
        }
        
        // Sadece ilk faturayı göster (en son yüklenen)
        $invoice = $invoices[0];
        ?>
        <a href="<?php echo esc_url($this->get_download_url($invoice->id, $order_id)); ?>" 
           class="woocommerce-button button" target="_blank">
            <?php echo esc_html__('İndir', 'e-fatura'); ?>
        </a>
        <?php
    }
    
    /**
     * E-posta ekine fatura ekle (opsiyonel)
     */
    public function attach_invoice_to_email($attachments, $email_id, $order, $email) {
        // Sadece tamamlanmış sipariş e-postalarına ekle
        $allowed_emails = array(
            'customer_completed_order',
            'customer_invoice'
        );
        
        if (!in_array($email_id, $allowed_emails)) {
            return $attachments;
        }
        
        if (!$order) {
            return $attachments;
        }
        
        $order_id = $order->get_id();
        $invoices = $this->get_order_invoices($order_id);
        
        if (empty($invoices)) {
            return $attachments;
        }
        
        // Son yüklenen faturayı ekle
        $invoice = $invoices[0];
        $file_path = EFATURA_UPLOAD_DIR . '/' . $invoice->file_path;
        
        if (file_exists($file_path)) {
            $attachments[] = $file_path;
        }
        
        return $attachments;
    }
    
    /**
     * Sipariş faturalarını getir
     */
    private function get_order_invoices($order_id) {
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . "efatura_invoices WHERE order_id = %d ORDER BY upload_date DESC",
            $order_id
        ));
    }
    
    /**
     * İndirme URL'i oluştur
     */
    private function get_download_url($invoice_id, $order_id) {
        return add_query_arg(array(
            'efatura_download' => 1,
            'invoice_id' => $invoice_id,
            'order_id' => $order_id,
            'nonce' => wp_create_nonce('efatura_download_' . $invoice_id)
        ), home_url());
    }
}
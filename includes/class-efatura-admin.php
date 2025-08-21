<?php
/**
 * Admin panel işlemleri
 */

if (!defined('ABSPATH')) {
    exit;
}

class EFatura_Admin {
    
    public function __construct() {
        add_action('add_meta_boxes', array($this, 'add_invoice_meta_box'));
        add_action('wp_ajax_efatura_upload_invoice', array($this, 'handle_invoice_upload'));
        add_action('wp_ajax_efatura_delete_invoice', array($this, 'handle_invoice_delete'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Veritabanı tablo adı
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'efatura_invoices';
    }
    
    /**
     * Meta box ekle
     */
    public function add_invoice_meta_box() {
        add_meta_box(
            'efatura_invoice',
            esc_html__('E-Fatura Yönetimi', 'e-fatura'),
            array($this, 'render_invoice_meta_box'),
            'shop_order',
            'side',
            'high'
        );
        
        // HPOS desteği
        add_meta_box(
            'efatura_invoice',
            esc_html__('E-Fatura Yönetimi', 'e-fatura'),
            array($this, 'render_invoice_meta_box'),
            'woocommerce_page_wc-orders',
            'side',
            'high'
        );
    }
    
    /**
     * Meta box içeriği
     */
    public function render_invoice_meta_box($post_or_order) {
        // HPOS uyumluluğu
        $order = ($post_or_order instanceof WP_Post) ? wc_get_order($post_or_order->ID) : $post_or_order;
        if (!$order) {
            return;
        }
        
        $order_id = $order->get_id();
        $invoices = $this->get_order_invoices($order_id);
        
        wp_nonce_field('efatura_upload_nonce', 'efatura_nonce');
        ?>
        <div id="efatura-invoice-box" data-order-id="<?php echo esc_attr($order_id); ?>">
            
            <?php if (!empty($invoices)) : ?>
                <div class="efatura-existing-invoices">
                    <h4><?php echo esc_html__('Mevcut Faturalar', 'e-fatura'); ?></h4>
                    <ul>
                        <?php foreach ($invoices as $invoice) : ?>
                            <li>
                                <span><?php echo esc_html($invoice->file_name); ?></span>
                                <small>(<?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($invoice->upload_date))); ?>)</small>
                                <div class="efatura-actions">
                                    <a href="<?php echo esc_url($this->get_download_url($invoice->id, $order_id)); ?>" 
                                       class="button button-small" target="_blank">
                                        <?php echo esc_html__('İndir', 'e-fatura'); ?>
                                    </a>
                                    <button type="button" class="button button-small efatura-delete-btn" 
                                            data-invoice-id="<?php echo esc_attr($invoice->id); ?>">
                                        <?php echo esc_html__('Sil', 'e-fatura'); ?>
                                    </button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <div class="efatura-upload-section">
                <h4><?php echo esc_html__('Yeni Fatura Yükle', 'e-fatura'); ?></h4>
                <input type="file" id="efatura-file-input" accept=".pdf" />
                <button type="button" id="efatura-upload-btn" class="button button-primary">
                    <?php echo esc_html__('Fatura Yükle', 'e-fatura'); ?>
                </button>
                <div id="efatura-message"></div>
            </div>
            
        </div>
        
        <style>
            #efatura-invoice-box {
                padding: 10px 0;
            }
            .efatura-existing-invoices ul {
                margin: 0;
                padding: 0;
            }
            .efatura-existing-invoices li {
                list-style: none;
                padding: 8px 0;
                border-bottom: 1px solid #ddd;
            }
            .efatura-existing-invoices li:last-child {
                border-bottom: none;
            }
            .efatura-actions {
                margin-top: 5px;
            }
            .efatura-actions .button {
                margin-right: 5px;
            }
            .efatura-upload-section {
                margin-top: 15px;
                padding-top: 15px;
                border-top: 1px solid #ddd;
            }
            #efatura-file-input {
                width: 100%;
                margin-bottom: 10px;
            }
            #efatura-message {
                margin-top: 10px;
                padding: 5px;
                display: none;
            }
            #efatura-message.success {
                color: green;
                display: block;
            }
            #efatura-message.error {
                color: red;
                display: block;
            }
        </style>
        <?php
    }
    
    /**
     * Fatura yükleme işlemi
     */
    public function handle_invoice_upload() {
        // Güvenlik kontrolleri
        check_ajax_referer('efatura_upload_nonce', 'nonce');
        
        if (!EFatura_Security::can_upload_invoice()) {
            wp_send_json_error(__('Bu işlem için yetkiniz yok.', 'e-fatura'));
        }
        
        if (!EFatura_Security::check_rate_limit('upload')) {
            wp_send_json_error(__('Çok fazla deneme yaptınız. Lütfen daha sonra tekrar deneyin.', 'e-fatura'));
        }
        
        if (empty($_FILES['invoice'])) {
            wp_send_json_error(__('Dosya yüklenmedi.', 'e-fatura'));
        }
        
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file = isset($_FILES['invoice']) ? $_FILES['invoice'] : null;
        if ( ! $order_id || ! $file ) {
            wp_send_json_error(__('Geçersiz istek.', 'e-fatura'));
        }
        
        // Dosya validasyonu
        $validation = EFatura_Security::validate_upload($file);
        if (is_wp_error($validation)) {
            wp_send_json_error($validation->get_error_message());
        }
        
        // Güvenli dosya adı oluştur
        $secure_filename = EFatura_Security::generate_secure_filename($order_id, $file['name']);
        $upload_path = EFATURA_UPLOAD_DIR . '/' . $secure_filename;
        
        // Dosyayı taşı
        // WordPress upload API kullan
        require_once ABSPATH . 'wp-admin/includes/file.php';
        add_filter('upload_dir', function($dirs) {
            $dirs['subdir'] = '/e-fatura-invoices';
            $dirs['path'] = $dirs['basedir'] . $dirs['subdir'];
            $dirs['url']  = $dirs['baseurl'] . $dirs['subdir'];
            return $dirs;
        });
        $upload_overrides = array(
            'test_form' => false,
            'mimes' => array('pdf' => 'application/pdf')
        );
        $_files = array('name' => $secure_filename, 'type' => $file['type'], 'tmp_name' => $file['tmp_name'], 'error' => $file['error'], 'size' => $file['size']);
        $wp_file = wp_handle_sideload($_files, $upload_overrides);
        remove_all_filters('upload_dir');
        if (isset($wp_file['error'])) {
            wp_send_json_error(__('Dosya yüklenirken hata oluştu.', 'e-fatura'));
        }
        // Gerçek kaydedilen dosya adını al
        $upload_path = $wp_file['file'];
        
        // Veritabanına kaydet
        global $wpdb;
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'order_id' => $order_id,
                'file_name' => sanitize_file_name($file['name']),
                'file_path' => basename($upload_path),
                'uploaded_by' => get_current_user_id()
            ),
            array('%d', '%s', '%s', '%d')
        );
        
        if ($result === false) {
            wp_delete_file($upload_path);
            wp_send_json_error(__('Veritabanı hatası.', 'e-fatura'));
        }
        
        // Sipariş notu ekle
        $order = wc_get_order($order_id);
        if ($order) {
            // translators: %s uploaded invoice file name
            $order->add_order_note(
                // translators: %s uploaded invoice file name
                sprintf(__('Fatura yüklendi: %s', 'e-fatura'), sanitize_file_name($file['name']))
            );
        }
        
        wp_send_json_success(__('Fatura başarıyla yüklendi.', 'e-fatura'));
    }
    
    /**
     * Fatura silme işlemi
     */
    public function handle_invoice_delete() {
        // Güvenlik kontrolleri
        check_ajax_referer('efatura_upload_nonce', 'nonce');
        
        if (!EFatura_Security::can_upload_invoice()) {
            wp_send_json_error(__('Bu işlem için yetkiniz yok.', 'e-fatura'));
        }
        
        $invoice_id = isset($_POST['invoice_id']) ? intval($_POST['invoice_id']) : 0;
        
        global $wpdb;
        
        // Fatura bilgisini al
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $invoice = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . $wpdb->prefix . "efatura_invoices WHERE id = %d",
            $invoice_id
        ));
        
        if (!$invoice) {
            wp_send_json_error(__('Fatura bulunamadı.', 'e-fatura'));
        }
        
        // Dosyayı sil
        $file_path = EFATURA_UPLOAD_DIR . '/' . $invoice->file_path;
        if (file_exists($file_path)) {
            wp_delete_file($file_path);
        }
        
        // Veritabanından sil
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $wpdb->delete($this->table_name, array('id' => $invoice_id), array('%d'));
        
        if ($result === false) {
            wp_send_json_error(__('Silme işlemi başarısız.', 'e-fatura'));
        }
        
        // Sipariş notu ekle
        $order = wc_get_order($invoice->order_id);
        if ($order) {
            // translators: %s deleted invoice file name
            $order->add_order_note(
                // translators: %s deleted invoice file name
                sprintf(__('Fatura silindi: %s', 'e-fatura'), $invoice->file_name)
            );
        }
        
        wp_send_json_success(__('Fatura başarıyla silindi.', 'e-fatura'));
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
    
    /**
     * Admin scriptleri
     */
    public function enqueue_admin_scripts($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php', 'woocommerce_page_wc-orders'))) {
            return;
        }
        
        wp_enqueue_script(
            'efatura-admin',
            EFATURA_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            EFATURA_VERSION,
            true
        );
        
        wp_localize_script('efatura-admin', 'efatura_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'upload_error' => __('Yükleme hatası', 'e-fatura'),
            'delete_confirm' => __('Bu faturayı silmek istediğinizden emin misiniz?', 'e-fatura')
        ));
    }
}
jQuery(document).ready(function($) {
    
    // Fatura yükleme
    $('#efatura-upload-btn').on('click', function(e) {
        e.preventDefault();
        
        var fileInput = $('#efatura-file-input')[0];
        var messageDiv = $('#efatura-message');
        var orderId = $('#efatura-invoice-box').data('order-id');
        var nonce = $('#efatura_nonce').val();
        
        // Dosya kontrolü
        if (!fileInput.files.length) {
            messageDiv.removeClass('success').addClass('error').text('Lütfen bir dosya seçin.').show();
            return;
        }
        
        var file = fileInput.files[0];
        
        // Dosya tipi kontrolü
        if (file.type !== 'application/pdf') {
            messageDiv.removeClass('success').addClass('error').text('Sadece PDF dosyaları yüklenebilir.').show();
            return;
        }
        
        // Dosya boyutu kontrolü (10MB)
        if (file.size > 10 * 1024 * 1024) {
            messageDiv.removeClass('success').addClass('error').text('Dosya boyutu 10MB\'dan büyük olamaz.').show();
            return;
        }
        
        // FormData oluştur
        var formData = new FormData();
        formData.append('action', 'efatura_upload_invoice');
        formData.append('invoice', file);
        formData.append('order_id', orderId);
        formData.append('nonce', nonce);
        
        // Butonu devre dışı bırak
        $(this).prop('disabled', true).text('Yükleniyor...');
        messageDiv.hide();
        
        // AJAX isteği
        $.ajax({
            url: efatura_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    messageDiv.removeClass('error').addClass('success').text(response.data).show();
                    
                    // Sayfayı yenile
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    messageDiv.removeClass('success').addClass('error').text(response.data).show();
                    $('#efatura-upload-btn').prop('disabled', false).text('Fatura Yükle');
                }
            },
            error: function() {
                messageDiv.removeClass('success').addClass('error').text(efatura_ajax.upload_error).show();
                $('#efatura-upload-btn').prop('disabled', false).text('Fatura Yükle');
            }
        });
    });
    
    // Fatura silme
    $(document).on('click', '.efatura-delete-btn', function(e) {
        e.preventDefault();
        
        if (!confirm(efatura_ajax.delete_confirm)) {
            return;
        }
        
        var button = $(this);
        var invoiceId = button.data('invoice-id');
        var nonce = $('#efatura_nonce').val();
        
        button.prop('disabled', true).text('Siliniyor...');
        
        $.ajax({
            url: efatura_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'efatura_delete_invoice',
                invoice_id: invoiceId,
                nonce: nonce
            },
            success: function(response) {
                if (response.success) {
                    // Satırı kaldır
                    button.closest('li').fadeOut(function() {
                        $(this).remove();
                        
                        // Eğer başka fatura yoksa bölümü gizle
                        if ($('.efatura-existing-invoices li').length === 0) {
                            $('.efatura-existing-invoices').hide();
                        }
                    });
                } else {
                    alert(response.data);
                    button.prop('disabled', false).text('Sil');
                }
            },
            error: function() {
                alert('Silme işlemi başarısız.');
                button.prop('disabled', false).text('Sil');
            }
        });
    });
    
    // Dosya seçildiğinde mesajı temizle
    $('#efatura-file-input').on('change', function() {
        $('#efatura-message').hide();
    });
});
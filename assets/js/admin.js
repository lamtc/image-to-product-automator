jQuery(document).ready(function($) {
    var mediaUploader;
    var selectedImages = [];

    $('#select-images').on('click', function(e) {
        e.preventDefault();

        if (mediaUploader) {
            mediaUploader.open();
            return;
        }

        mediaUploader = wp.media({
            title: 'Select Images',
            button: {
                text: 'Select Images'
            },
            multiple: true,
            library: {
                type: 'image'
            }
        });

        mediaUploader.on('select', function() {
            var attachments = mediaUploader.state().get('selection').toJSON();
            selectedImages = attachments.map(function(attachment) {
                return attachment.id;
            });

            // Show preview
            var previewHtml = attachments.map(function(attachment) {
                var imageUrl;
                // Check if thumbnail exists, if not use full size or any available size
                if (attachment.sizes && attachment.sizes.thumbnail && attachment.sizes.thumbnail.url) {
                    imageUrl = attachment.sizes.thumbnail.url;
                } else if (attachment.sizes && attachment.sizes.full && attachment.sizes.full.url) {
                    imageUrl = attachment.sizes.full.url;
                } else if (attachment.url) {
                    imageUrl = attachment.url;
                } else {
                    // If no URL is available, use a placeholder or skip
                    console.warn('No image URL available for attachment:', attachment);
                    return '';
                }
                return '<div class="image-preview"><img src="' + imageUrl + '" alt="' + (attachment.title || 'Image preview') + '" style="max-width: 150px; height: auto;"></div>';
            }).join('');

            $('#selected-images-preview').html(previewHtml);
            $('#process-images').removeClass('hidden');
        });

        mediaUploader.open();
    });

    $('#process-images').on('click', function() {
        if (!selectedImages.length) {
            alert('Please select at least one image first.');
            return;
        }

        var $button = $(this);
        var $status = $('#processing-status');

        $button.prop('disabled', true);
        $status.html('<p>Processing images...</p>');

        $.ajax({
            url: itpAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'process_bulk_images',
                image_ids: selectedImages,
                nonce: itpAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    var results = response.data;
                    var successCount = results.filter(function(r) { return r.success; }).length;
                    $status.html('<p>Successfully created ' + successCount + ' products!</p>');
                    
                    // Clear selection after successful processing
                    selectedImages = [];
                    $('#selected-images-preview').empty();
                    $('#process-images').addClass('hidden');
                } else {
                    $status.html('<p>Error processing images: ' + (response.data || 'Unknown error') + '</p>');
                }
            },
            error: function(xhr, status, error) {
                $status.html('<p>Error processing images: ' + error + '</p>');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    // Add some basic styles for the image preview
    $('<style>')
        .text(`
            #selected-images-preview {
                display: flex;
                flex-wrap: wrap;
                gap: 10px;
                margin: 15px 0;
            }
            .image-preview {
                border: 1px solid #ddd;
                padding: 5px;
                border-radius: 4px;
            }
            .image-preview img {
                display: block;
                max-width: 150px;
                height: auto;
            }
        `)
        .appendTo('head');
});

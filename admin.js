jQuery(document).ready(function($) {
    if (typeof kritiData === 'undefined') return;

    let searchData = kritiData.searchData || [];
    let fontsData = kritiData.fontsData || [];
    let currentPage = 1;
    let itemsPerPage = 12;
    let filteredFonts = searchData;
    let currentModalFont = null;

    function renderGrid() {
        let start = (currentPage - 1) * itemsPerPage;
        let end = start + itemsPerPage;
        let paginated = filteredFonts.slice(start, end);

        let html = '';
        paginated.forEach(function(font) {
            let fontName = font.n || font.s; // 'n' for name, 's' for slug
            let fontData = fontsData.find(f => f.slug === font.s);
            
            if (fontData && fontData.styles && fontData.styles.length > 0) {
                let style = fontData.styles.find(s => s.weight === "400") || fontData.styles[0];
                let woffUrl = `https://kriti.app${style.woff2Url}`;
                
                let fontId = 'Kriti-' + font.s;
                if (!document.getElementById('style-' + fontId)) {
                    let styleEl = document.createElement('style');
                    styleEl.id = 'style-' + fontId;
                    styleEl.innerHTML = `@font-face { font-family: '${fontId}'; src: url('${woffUrl}') format('woff2'); }`;
                    document.head.appendChild(styleEl);
                }
            }

            html += `<div class="kriti-font-card" data-slug="${font.s}">
                <h3 style="font-family: 'Kriti-${font.s}', sans-serif;">${fontName}</h3>
                <div class="kriti-font-preview-snippet" style="font-family: 'Kriti-${font.s}', sans-serif;">
                    এখানে টাইপ করুন
                </div>
            </div>`;
        });
        $('#kriti-fonts-grid').html(html);
        renderPagination();
    }

    function renderPagination() {
        let totalPages = Math.ceil(filteredFonts.length / itemsPerPage);
        
        let html = '<span class="pagination-links">';

        if (totalPages <= 1) {
            $('#kriti-pagination').html('');
            return;
        }

        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, currentPage + 2);

        if (currentPage > 1) {
            html += `<a href="#" data-page="${currentPage - 1}">&laquo; Prev</a>`;
        }

        if (startPage > 1) {
            html += `<a href="#" data-page="1">1</a>`;
            if (startPage > 2) html += `<span class="dots">...</span>`;
        }

        for (let i = startPage; i <= endPage; i++) {
            if (i === currentPage) {
                html += `<span class="page-numbers current">${i}</span>`;
            } else {
                html += `<a class="page-numbers" href="#" data-page="${i}">${i}</a>`;
            }
        }

        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += `<span class="dots">...</span>`;
            html += `<a class="page-numbers" href="#" data-page="${totalPages}">${totalPages}</a>`;
        }

        if (currentPage < totalPages) {
            html += `<a href="#" data-page="${currentPage + 1}">Next &raquo;</a>`;
        }
        
        html += '</span>';
        $('#kriti-pagination').html(html);
    }

    $('#kriti-pagination').on('click', 'a.page-numbers, a[data-page]', function(e) {
        e.preventDefault();
        let page = $(this).data('page');
        if (page) {
            currentPage = page;
            renderGrid();
        }
    });

    $('#kriti-search').on('keyup', function() {
        let kw = $(this).val().toLowerCase();
        filteredFonts = searchData.filter(function(f) {
            return f.n.toLowerCase().includes(kw) || f.s.toLowerCase().includes(kw);
        });
        currentPage = 1;
        renderGrid();
    });

    $(document).on('click', '.kriti-font-card', function() {
        let slug = $(this).data('slug');
        let fontData = fontsData.find(f => f.slug === slug);
        if(!fontData) return;

        currentModalFont = fontData;
        $('#kriti-modal-title').text(fontData.name || slug);
        $('#kriti-modal-preview').css('font-family', `'Kriti-${slug}', sans-serif`);
        $('#kriti-modal-preview-box').css('font-family', `'Kriti-${slug}', sans-serif`);
        
        // Setup initial text
        $('#kriti-modal-preview-text').val('এখানে টাইপ করুন...');
        $('#kriti-modal-preview-box').text('এখানে টাইপ করুন...');

        $('#kriti-metadata-content').html('<p style="text-align:center;">Loading metadata...</p>');
        
        // Reset tabs
        $('.kriti-modal-tabs a').removeClass('nav-tab-active');
        $('.kriti-modal-tabs a[data-tab="preview"]').addClass('nav-tab-active');
        $('.kriti-tab-content').hide();
        $('#kriti-tab-preview').fadeIn('fast');

        // Always start with Global mode to avoid stale custom state from previous edits.
        $('input[name="kriti_assignment_mode"][value="global"]').prop('checked', true);
        $('#kriti-custom-targets-wrap').hide();

        $('#kriti-modal').fadeIn('fast').css('display', 'flex');
    });

    $('.kriti-close').on('click', function(e) {
        e.preventDefault();
        $('#kriti-modal').fadeOut('fast');
        currentModalFont = null;
    });

    $('.kriti-modal-tabs a').on('click', function(e) {
        e.preventDefault();
        $('.kriti-modal-tabs a').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');

        let target = $(this).data('tab');
        $('.kriti-tab-content').hide();
        $('#kriti-tab-' + target).show();

        // Fetch Metadata via API when clicking Metadata tab
        if (target === 'metadata' && currentModalFont && !currentModalFont.hasLoadedMeta) {
            let metaSlug = currentModalFont.slug;
            $.get(`https://kriti.app/api/fonts/${metaSlug}`)
                .done(function(res) {
                    currentModalFont.hasLoadedMeta = true;
                    // Format response neatly
                    let metaHtml = `
                        <div style="font-size: 14px; line-height: 1.6; display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                            <div>
                                <p><strong>Name:</strong> ${res.name || 'N/A'}</p>
                                <p><strong>Family Name:</strong> ${res.familyName || 'N/A'} (<em>${res.subfamilyName || 'Regular'}</em>)</p>
                                <p><strong>PostScript Name:</strong> ${res.postScriptName || 'N/A'}</p>
                                <p><strong>File Name:</strong> ${res.fileName || 'N/A'} (${res.fileSizeFormatted || 'N/A'})</p>
                                <p><strong>Version:</strong> ${res.version || 'N/A'}</p>
                                <p><strong>Description:</strong> ${res.description || 'N/A'}</p>
                                <p><strong>Author / Designer:</strong> ${res.designer ? (res.designerURL ? `<a href="${(!res.designerURL.startsWith('http') ? 'https://' : '') + res.designerURL}" target="_blank">${res.designer}</a>` : res.designer) : 'N/A'}</p>
                                <p><strong>Manufacturer:</strong> ${res.manufacturer ? (res.manufacturerURL ? `<a href="${(!res.manufacturerURL.startsWith('http') ? 'https://' : '') + res.manufacturerURL}" target="_blank">${res.manufacturer}</a>` : res.manufacturer) : 'N/A'}</p>
                            </div>
                            <div>
                                <p><strong>Copyright:</strong> ${res.copyright || 'N/A'}</p>
                                <p><strong>Trademark:</strong> ${res.trademark || 'N/A'}</p>
                                <p><strong>License:</strong> <em>${res.licenseType || res.license || 'N/A'}</em> - ${res.licenseURL ? `<a href="${(!res.licenseURL.startsWith('http') ? 'https://' : '') + res.licenseURL}" target="_blank">View License</a>` : 'N/A'}</p>
                                <p><strong>Supported Scripts:</strong> ${(res.unicodeRanges || []).join(', ') || 'N/A'}</p>
                                <p><strong>Glyphs:</strong> ${res.numGlyphs || 'N/A'}</p>
                                <p><strong>Units Per Em:</strong> ${res.unitsPerEm || 'N/A'}</p>
                                <p><strong>Ascender / Descender:</strong> ${res.ascender || 'N/A'} / ${res.descender || 'N/A'}</p>
                            </div>
                        </div>
                    `;
                    $('#kriti-metadata-content').html(metaHtml);
                })
                .fail(function() {
                    $('#kriti-metadata-content').html('<p style="color:red; text-align:center;">Failed to load font metadata.</p>');
                });
        }
    });

    $('#kriti-modal-preview-text').on('input', function() {
        let text = $(this).val();
        if (!text.trim()) text = 'এখানে টাইপ করুন...';
        $('#kriti-modal-preview-box').text(text);
    });

    // Custom Target Toggle
    $('input[name="kriti_assignment_mode"]').on('change', function() {
        if ($(this).val() === 'custom') {
            $('#kriti-custom-targets-wrap').slideDown('fast');
        } else {
            $('#kriti-custom-targets-wrap').slideUp('fast');
        }
    });

    // Save Font
    $('#kriti-select-font').on('click', function(e) {
        e.preventDefault();
        if (!currentModalFont) return;
        
        let $btn = $(this);
        let origText = $btn.text();
        
        let mode = $('input[name="kriti_assignment_mode"]:checked').val();
        let targetId = (mode === 'custom') ? $('#kriti-font-target').val() : 'global';
        let deliveryMethod = $('input[name="kriti_delivery_method"]:checked').val() || 'cdn';
        let downloadUrl = currentModalFont.styles && currentModalFont.styles[0] ? 'https://kriti.app' + currentModalFont.styles[0].woff2Url : '';
        
        $btn.prop('disabled', true).text(kritiData.i18n.saving);
        $('#kriti-save-status').html('').hide();

        $.ajax({
            url: kritiData.ajax_url,
            type: 'POST',
            data: {
                action: 'kriti_save_font',
                nonce: kritiData.nonce,
                font_id: currentModalFont.slug,
                font_name: currentModalFont.name || currentModalFont.slug,
                target: targetId,
                download_url: downloadUrl,
                delivery_method: deliveryMethod
            },
            success: function(response) {
                if (response.success) {
                    $('#kriti-save-status').html(`<span style="color: #00a32a; font-weight: 600; margin-left: 15px;">${kritiData.i18n.saved}</span>`).fadeIn('fast');
                    setTimeout(() => {
                        window.location.reload();
                    }, 1000);
                } else {
                    $('#kriti-save-status').html(`<span style="color: #d63638; font-weight: 600; margin-left: 15px;">${response.data || kritiData.i18n.error}</span>`).fadeIn('fast');
                    $btn.prop('disabled', false).text(origText);
                }
            },
            error: function() {
                $('#kriti-save-status').html(`<span style="color: #d63638; font-weight: 600; margin-left: 15px;">${kritiData.i18n.error}</span>`).fadeIn('fast');
                $btn.prop('disabled', false).text(origText);
            }
        });
    });

    // Reset Font
    $('.kriti-reset-font').on('click', function(e) {
        e.preventDefault();
        let $btn = $(this);
        let targetId = $btn.data('target');
        let originalText = $btn.text();
        $btn.prop('disabled', true).text(kritiData.i18n.resetting);

        $.ajax({
            url: kritiData.ajax_url,
            type: 'POST',
            data: {
                action: 'kriti_reset_font',
                nonce: kritiData.nonce,
                target: targetId
            },
            success: function(response) {
                if (response.success) {
                    $('#kriti-reset-status').text(kritiData.i18n.resetMsg).fadeIn();
                    setTimeout(() => { location.reload(); }, 1000);
                } else {
                    alert('Error removing font.');
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function() {
                alert('Server error while removing font.');
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // Run Initial Load
    renderGrid();
});
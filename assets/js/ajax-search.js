/**
 * MiniLoad AJAX Search Pro - Frontend
 * Better than FiboSearch with comprehensive features!
 */

(function($) {
    'use strict';

    var MiniLoadSearch = {
        searchTimer: null,
        currentRequest: null,
        cache: {},
        selectedIndex: -1,
        activeSearches: {},

        // Helper function to count Unicode characters correctly
        getCharLength: function(str) {
            // This correctly counts multi-byte characters like Persian/Arabic
            return [...str].length;
        },

        init: function() {
            this.bindEvents();
            this.initKeyboardShortcuts();
            this.initIconTriggers();
            this.initFloatingButton();
            this.initMobileFeatures();
            this.loadPopularSearches();
            this.updatePlaceholders();
        },

        updatePlaceholders: function() {
            // Update all search input placeholders with the saved setting
            if (miniload_ajax_search.placeholder) {
                $('.miniload-search-input').attr('placeholder', miniload_ajax_search.placeholder);
            }
        },

        bindEvents: function() {
            var self = this;

            // Preload on hover for faster perceived response
            $(document).on('mouseenter focus', '.miniload-search-input', function() {
                // Preconnect to speed up AJAX requests
                if (!self.preconnected) {
                    var link = document.createElement('link');
                    link.rel = 'preconnect';
                    link.href = miniload_ajax_search.ajax_url.replace(/\/[^\/]*$/, '');
                    document.head.appendChild(link);
                    self.preconnected = true;
                }
            });

            // Live search on input
            $(document).on('input', '.miniload-search-input', function() {
                var $input = $(this);
                var term = $input.val();
                var $wrapper = $input.closest('.miniload-search-wrapper');
                var $inputWrapper = $wrapper.find('.miniload-search-input-wrapper');
                var $clearButton = $wrapper.find('.miniload-search-clear');

                // Show/hide clear button based on input content
                if (term.length > 0) {
                    $clearButton.addClass('visible');
                    $inputWrapper.addClass('has-clear-button');
                    // Padding is now handled by CSS classes
                } else {
                    $clearButton.removeClass('visible');
                    $inputWrapper.removeClass('has-clear-button');
                    // Padding is now handled by CSS classes
                }

                clearTimeout(self.searchTimer);

                if (self.getCharLength(term) < miniload_ajax_search.min_chars) {
                    self.hideResults($input);
                    return;
                }

                // Check cache first
                if (self.cache[term]) {
                    self.displayResults($input, self.cache[term]);
                    return;
                }

                // Show loading
                self.showLoading($input);

                // Debounce search using configured delay
                var delay = miniload_ajax_search.search_delay || 300;
                self.searchTimer = setTimeout(function() {
                    self.performSearch($input, term);
                }, delay);
            });

            // Category filter
            $(document).on('change', '.miniload-search-category', function() {
                var $input = $(this).closest('form').find('.miniload-search-input');
                if (self.getCharLength($input.val()) >= miniload_ajax_search.min_chars) {
                    self.performSearch($input, $input.val());
                }
            });

            // Click outside to close
            $(document).on('click', function(e) {
                if (!$(e.target).closest('.miniload-search-wrapper').length) {
                    $('.miniload-search-results').hide();
                }
            });

            // Keyboard navigation
            $(document).on('keydown', '.miniload-search-input', function(e) {
                self.handleKeyboard(e, $(this));
            });

            // Track clicks
            $(document).on('click', '.miniload-search-results a', function() {
                var term = $(this).closest('.miniload-search-wrapper').find('.miniload-search-input').val();
                var productId = $(this).data('product-id');

                if (productId) {
                    self.trackClick(term, productId);
                }
            });

            // Modal trigger
            $(document).on('click', '[data-miniload-search]', function(e) {
                e.preventDefault();
                self.openModal();
            });

            // Close modal
            $(document).on('click', '.miniload-search-close, .miniload-search-modal', function(e) {
                if (e.target === this) {
                    self.closeModal();
                }
            });
        },

        performSearch: function($input, term) {
            var self = this;
            var category = $input.closest('form').find('.miniload-search-category').val();

            // Abort previous request
            if (self.currentRequest) {
                self.currentRequest.abort();
            }

            self.currentRequest = $.ajax({
                url: miniload_ajax_search.ajax_url,
                type: 'POST',
                data: {
                    action: 'miniload_ajax_search',
                    term: term,
                    category: category,
                    type: 'all',
                    max_results: miniload_ajax_search.max_results || 8,
                    nonce: miniload_ajax_search.nonce
                },
                success: function(response) {
                    if (response.success) {
                        self.cache[term] = response.data;
                        self.displayResults($input, response.data);
                    }
                },
                complete: function() {
                    self.currentRequest = null;
                }
            });
        },

        displayResults: function($input, data) {
            var self = this;
            var $results = $input.closest('.miniload-search-wrapper').find('.miniload-search-results');
            var html = '';

            // Products
            if (data.results.products && data.results.products.length > 0) {
                html += '<div class="miniload-search-section">';
                html += '<h4>Products</h4>';
                html += '<ul class="miniload-search-products">';

                $.each(data.results.products, function(i, product) {
                    html += '<li>';
                    html += '<a href="' + product.url + '" data-product-id="' + product.id + '">';

                    // Show image only if enabled in settings
                    if (product.image && miniload_ajax_search.show_image !== '0') {
                        html += '<img src="' + product.image + '" alt="' + product.title + '">';
                    }

                    html += '<div class="miniload-search-product-info">';
                    html += '<span class="title">' + self.highlightTerm(product.title, data.term) + '</span>';

                    if (product.sku) {
                        html += '<span class="sku">SKU: ' + self.highlightTerm(product.sku, data.term) + '</span>';
                    }

                    // Show price only if enabled in settings
                    if (miniload_ajax_search.show_price !== '0') {
                        html += '<span class="price">' + product.price;
                        if (product.sale_price) {
                            html += ' <del>' + product.sale_price + '</del>';
                        }
                        html += '</span>';
                    }

                    html += '</div>';
                    html += '</a>';
                    html += '</li>';
                });

                html += '</ul>';
                html += '</div>';
            }

            // Categories - only show if enabled in settings
            if (data.results.categories && data.results.categories.length > 0 && miniload_ajax_search.show_categories_results !== '0') {
                html += '<div class="miniload-search-section">';
                html += '<h4>Categories</h4>';
                html += '<ul class="miniload-search-categories">';

                $.each(data.results.categories, function(i, cat) {
                    html += '<li>';
                    html += '<a href="' + cat.url + '">';
                    html += cat.title + ' (' + cat.count + ')';
                    html += '</a>';
                    html += '</li>';
                });

                html += '</ul>';
                html += '</div>';
            }

            // Suggestions
            if (data.suggestions && data.suggestions.length > 0) {
                html += '<div class="miniload-search-section miniload-search-suggestions">';
                html += '<h4>Did you mean?</h4>';
                html += '<ul>';

                $.each(data.suggestions, function(i, suggestion) {
                    html += '<li>';
                    html += '<a href="#" class="miniload-suggestion" data-term="' + suggestion.term + '">';
                    html += suggestion.term;
                    if (suggestion.popularity) {
                        html += ' <span class="popularity">(' + suggestion.popularity + ' searches)</span>';
                    }
                    html += '</a>';
                    html += '</li>';
                });

                html += '</ul>';
                html += '</div>';
            }

            // No results
            if (!html) {
                html = '<div class="miniload-search-no-results">' + miniload_ajax_search.no_results + '</div>';
            }

            // Results count footer
            if (data.results) {
                // Use server-provided count if available, otherwise count locally
                var totalResults = 0;
                if (data.total_count !== undefined) {
                    totalResults = data.total_count;
                } else {
                    // Count total results locally
                    if (data.results.products && data.results.products.length > 0) {
                        totalResults += data.results.products.length;
                    }
                    if (data.results.categories && data.results.categories.length > 0) {
                        totalResults += data.results.categories.length;
                    }
                    if (data.results.tags && data.results.tags.length > 0) {
                        totalResults += data.results.tags.length;
                    }
                    if (data.results.posts && data.results.posts.length > 0) {
                        totalResults += data.results.posts.length;
                    }
                }

                if (totalResults > 0) {
                    html += '<div class="miniload-search-footer">';

                    // If we have more products than displayed, show that info
                    var displayedProducts = data.results.products ? data.results.products.length : 0;
                    var totalProducts = data.total_products || displayedProducts;

                    if (totalProducts > displayedProducts && displayedProducts > 0) {
                        // We're showing only some of the results
                        html += '<span class="search-results-count">نمایش ' + displayedProducts + ' از ' + totalResults + ' نتیجه</span>';
                    } else {
                        // We're showing all results
                        html += '<span class="search-results-count">' + totalResults + ' نتیجه یافت شد</span>';
                    }

                    html += '<a href="' + $input.closest('form').attr('action') + '?s=' + encodeURIComponent(data.term) + '&post_type=product">' + miniload_ajax_search.view_all + '</a>';
                    html += '</div>';
                }
            }

            $results.html(html).show();
            self.selectedIndex = -1;
        },

        highlightTerm: function(text, term) {
            // Don't highlight if text contains HTML tags (like price spans)
            if (/<[^>]*>/.test(text)) {
                return text;
            }

            // Escape special regex characters in the search term
            var escapedTerm = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

            // Only highlight whole words or word boundaries to avoid partial matches in HTML
            var regex = new RegExp('\\b(' + escapedTerm + ')', 'gi');
            return text.replace(regex, '<mark>$1</mark>');
        },

        showLoading: function($input) {
            var $results = $input.closest('.miniload-search-wrapper').find('.miniload-search-results');
            $results.html('<div class="miniload-search-loading">' + miniload_ajax_search.searching + '</div>').show();
        },

        hideResults: function($input) {
            $input.closest('.miniload-search-wrapper').find('.miniload-search-results').hide();
        },

        handleKeyboard: function(e, $input) {
            var self = this;
            var $results = $input.closest('.miniload-search-wrapper').find('.miniload-search-results');
            var $items = $results.find('a');

            switch(e.keyCode) {
                case 38: // Up
                    e.preventDefault();
                    self.selectedIndex = Math.max(0, self.selectedIndex - 1);
                    self.highlightItem($items);
                    break;

                case 40: // Down
                    e.preventDefault();
                    self.selectedIndex = Math.min($items.length - 1, self.selectedIndex + 1);
                    self.highlightItem($items);
                    break;

                case 13: // Enter
                    if (self.selectedIndex >= 0 && $items.length > 0) {
                        e.preventDefault();
                        window.location.href = $items.eq(self.selectedIndex).attr('href');
                    }
                    break;

                case 27: // Escape
                    self.hideResults($input);
                    break;
            }
        },

        highlightItem: function($items) {
            $items.removeClass('selected');
            if (this.selectedIndex >= 0) {
                $items.eq(this.selectedIndex).addClass('selected');
            }
        },

        trackClick: function(term, productId) {
            $.ajax({
                url: miniload_ajax_search.ajax_url,
                type: 'POST',
                data: {
                    action: 'miniload_track_search',
                    term: term,
                    product_id: productId,
                    nonce: miniload_ajax_search.nonce
                }
            });
        },

        initKeyboardShortcuts: function() {
            var self = this;

            // Ctrl+/ or Cmd+/ to focus search
            $(document).on('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.keyCode === 191) {
                    e.preventDefault();
                    self.focusSearch();
                }
            });
        },

        focusSearch: function() {
            var $input = $('.miniload-search-input:first');
            if ($input.length) {
                $input.focus().select();
            } else {
                this.openModal();
            }
        },

        openModal: function() {
            $('#miniload-search-modal').fadeIn(200);
            $('#miniload-search-modal .miniload-search-input').focus();
        },

        closeModal: function() {
            $('#miniload-search-modal').fadeOut(200);
        },

        // Initialize icon triggers
        initIconTriggers: function() {
            var self = this;

            // Handle icon click to open search
            $(document).on('click', '.miniload-search-icon-trigger', function(e) {
                e.preventDefault();
                var $trigger = $(this);
                var searchId = $trigger.closest('.miniload-search-icon-wrapper').data('search-id');
                var $searchWrapper = $('#' + searchId);

                if ($searchWrapper.length) {
                    // Toggle search visibility
                    if ($searchWrapper.hasClass('miniload-search-hidden')) {
                        self.showSearchBox($searchWrapper);
                    } else {
                        self.hideSearchBox($searchWrapper);
                    }
                }
            });
        },

        // Initialize floating button
        initFloatingButton: function() {
            var self = this;

            // Handle floating button click
            $(document).on('click', '.miniload-floating-trigger', function(e) {
                e.preventDefault();
                var $searchWrapper = $('.miniload-search-wrapper').first();

                if ($searchWrapper.hasClass('miniload-search-hidden')) {
                    self.showSearchBox($searchWrapper);
                } else {
                    // If already visible, just focus
                    $searchWrapper.find('.miniload-search-input').focus();
                }
            });

            // Add scroll behavior for floating button
            var lastScroll = 0;
            $(window).on('scroll', function() {
                var currentScroll = $(this).scrollTop();
                var $floatingButton = $('.miniload-search-floating-button');

                if ($floatingButton.length) {
                    if (currentScroll > lastScroll && currentScroll > 100) {
                        // Scrolling down - hide button
                        $floatingButton.addClass('miniload-floating-hidden');
                    } else {
                        // Scrolling up - show button
                        $floatingButton.removeClass('miniload-floating-hidden');
                    }
                    lastScroll = currentScroll;
                }
            });
        },

        // Initialize mobile features
        initMobileFeatures: function() {
            var self = this;

            // Handle mobile back button
            $(document).on('click', '.miniload-mobile-back', function(e) {
                e.preventDefault();
                var $wrapper = $(this).closest('.miniload-search-wrapper');
                self.hideSearchBox($wrapper);
            });

            // Handle clear button
            $(document).on('click', '.miniload-search-clear', function(e) {
                e.preventDefault();
                var $wrapper = $(this).closest('.miniload-search-wrapper');
                var $inputWrapper = $wrapper.find('.miniload-search-input-wrapper');
                var $input = $wrapper.find('.miniload-search-input');

                // Clear the input (padding handled by CSS)
                $input.val('').focus();
                $(this).removeClass('visible');
                $inputWrapper.removeClass('has-clear-button');
                self.hideResults($input);

                // Trigger input event to update any dependent elements
                $input.trigger('input');
            });

            // Voice search placeholder (future feature)
            $(document).on('click', '.miniload-voice-search', function(e) {
                e.preventDefault();
                alert('Voice search will be available in the next update!');
            });

            // Detect mobile and add class
            if (/Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent)) {
                $('body').addClass('miniload-is-mobile');
            }

            // Handle touch events for better mobile experience
            if ('ontouchstart' in window) {
                $(document).on('touchstart', '.miniload-search-products a', function() {
                    $(this).addClass('touch-active');
                });

                $(document).on('touchend', '.miniload-search-products a', function() {
                    $(this).removeClass('touch-active');
                });
            }
        },

        // Show search box with animation
        showSearchBox: function($wrapper) {
            var isMobile = $('body').hasClass('miniload-is-mobile');
            var isFullscreen = $wrapper.data('mobile-fullscreen') === 'true';

            if (isMobile && isFullscreen) {
                // Mobile fullscreen mode
                $wrapper.removeClass('miniload-search-hidden').addClass('miniload-search-active');
                $('body').addClass('miniload-search-open');

                // Prevent scrolling on body
                $('body').css('overflow', 'hidden');
            } else {
                // Regular mode
                $wrapper.removeClass('miniload-search-hidden').hide().fadeIn(300);
            }

            // Focus input
            setTimeout(function() {
                $wrapper.find('.miniload-search-input').focus();
            }, 100);
        },

        // Hide search box with animation
        hideSearchBox: function($wrapper) {
            var isMobile = $('body').hasClass('miniload-is-mobile');
            var isFullscreen = $wrapper.data('mobile-fullscreen') === 'true';

            if (isMobile && isFullscreen) {
                // Mobile fullscreen mode
                $wrapper.removeClass('miniload-search-active');
                $('body').removeClass('miniload-search-open');

                // Restore scrolling
                $('body').css('overflow', '');

                setTimeout(function() {
                    $wrapper.addClass('miniload-search-hidden');
                }, 300);
            } else {
                // Regular mode
                $wrapper.fadeOut(300, function() {
                    $(this).addClass('miniload-search-hidden');
                });
            }

            // Clear search
            $wrapper.find('.miniload-search-input').val('');
            this.hideResults($wrapper.find('.miniload-search-input'));
        },

        // Load popular searches
        loadPopularSearches: function() {
            var self = this;

            $('.miniload-popular-searches').each(function() {
                var $container = $(this);
                var $tagsContainer = $container.find('.miniload-popular-tags');

                // Load from local storage or make AJAX call
                var popularSearches = localStorage.getItem('miniload_popular_searches');
                if (popularSearches) {
                    self.displayPopularSearches($tagsContainer, JSON.parse(popularSearches));
                }

                // Update from server periodically
                $.ajax({
                    url: miniload_ajax_search.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'miniload_get_popular_searches',
                        nonce: miniload_ajax_search.nonce
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            localStorage.setItem('miniload_popular_searches', JSON.stringify(response.data));
                            self.displayPopularSearches($tagsContainer, response.data);
                        }
                    }
                });
            });
        },

        // Display popular searches
        displayPopularSearches: function($container, searches) {
            var html = '';
            if (searches && searches.length > 0) {
                $.each(searches.slice(0, 8), function(i, search) {
                    html += '<a href="#" class="miniload-popular-tag" data-term="' + search.term + '">';
                    html += search.term;
                    html += '</a>';
                });
                $container.html(html);
                $container.closest('.miniload-popular-searches').show();
            }
        }
    };

    // Initialize
    $(document).ready(function() {
        MiniLoadSearch.init();
    });

    // Handle suggestion clicks
    $(document).on('click', '.miniload-suggestion', function(e) {
        e.preventDefault();
        var term = $(this).data('term');
        var $input = $(this).closest('.miniload-search-wrapper').find('.miniload-search-input');
        $input.val(term).trigger('input');
    });

    // Handle popular search tag clicks
    $(document).on('click', '.miniload-popular-tag', function(e) {
        e.preventDefault();
        var term = $(this).data('term');
        var $input = $(this).closest('.miniload-search-wrapper').find('.miniload-search-input');
        $input.val(term).trigger('input');
    });

    // Add CSS for floating button hidden state
    var style = document.createElement('style');
    style.textContent = '.miniload-floating-hidden { transform: translateY(100px); opacity: 0; }';
    document.head.appendChild(style);

})(jQuery);
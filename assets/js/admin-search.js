/**
 * MiniLoad Admin Search - MantiLoad Style Tabbed Search Modal
 * Professional tabbed search for Products, Posts, Orders, Customers
 */

(function($) {
    'use strict';

    var MiniLoadAdminSearch = {
        isOpen: false,
        selectedIndex: -1,
        searchTimer: null,
        cache: {},
        activeTab: 'products',
        currentRequest: null,

        init: function() {
            this.createModal();
            this.bindEvents();
        },

        createModal: function() {
            if ($('#miniload-admin-search-modal').length === 0) {
                var modalHTML = `
                    <div id="miniload-admin-search-modal" class="miniload-admin-search-modal">
                        <div class="miniload-admin-search-content">
                            <div class="miniload-admin-search-header">
                                <h2>Search Dashboard</h2>
                                <button class="miniload-admin-close-btn">&times;</button>
                            </div>

                            <div class="miniload-admin-search-tabs">
                                <button class="miniload-admin-tab active" data-tab="products">
                                    <span class="dashicons dashicons-cart"></span>
                                    <span>Products</span>
                                </button>
                                <button class="miniload-admin-tab" data-tab="posts">
                                    <span class="dashicons dashicons-admin-post"></span>
                                    <span>Posts</span>
                                </button>
                                <button class="miniload-admin-tab" data-tab="orders">
                                    <span class="dashicons dashicons-clipboard"></span>
                                    <span>Orders</span>
                                </button>
                                <button class="miniload-admin-tab" data-tab="customers">
                                    <span class="dashicons dashicons-admin-users"></span>
                                    <span>Customers</span>
                                </button>
                            </div>

                            <div class="miniload-admin-search-input-wrapper">
                                <span class="dashicons dashicons-search"></span>
                                <input type="text" id="miniload-admin-search-input"
                                       placeholder="Search products..."
                                       autocomplete="off">
                            </div>

                            <div id="miniload-admin-search-results"></div>

                            <div class="miniload-admin-search-footer">
                                <div class="miniload-admin-search-stats">
                                    <span id="miniload-results-count">0 results</span>
                                    <span id="miniload-search-time"></span>
                                </div>
                                <div class="miniload-admin-search-shortcuts">
                                    <span><kbd>↑</kbd> <kbd>↓</kbd> Navigate</span>
                                    <span><kbd>Enter</kbd> Select</span>
                                    <span><kbd>Ctrl</kbd>+<kbd>Enter</kbd> New Tab</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
                $('body').append(modalHTML);
            }
        },

        bindEvents: function() {
            var self = this;

            // Only bind keyboard shortcut if enabled in settings
            if (window.miniload_admin_search && window.miniload_admin_search.enable_modal === 'true') {
                // Use Alt+K instead of Ctrl+K to avoid conflicts
                $(document).on('keydown', function(e) {
                    // Alt+K or Ctrl+Shift+K to open (avoiding conflict)
                    if ((e.altKey && e.keyCode === 75) ||
                        (e.ctrlKey && e.shiftKey && e.keyCode === 75)) {
                        e.preventDefault();
                        e.stopPropagation();
                        self.toggle();
                        return false;
                    }
                });
            }

            // ESC to close
            $(document).on('keydown', function(e) {
                if (e.keyCode === 27 && self.isOpen) {
                    e.preventDefault();
                    self.close();
                }
            });

            // Close button
            $(document).on('click', '.miniload-admin-close-btn', function(e) {
                e.preventDefault();
                self.close();
            });

            // Click outside to close
            $('#miniload-admin-search-modal').on('click', function(e) {
                if (e.target === this) {
                    self.close();
                }
            });

            // Tab switching
            $(document).on('click', '.miniload-admin-tab', function(e) {
                e.preventDefault();
                var tab = $(this).data('tab');
                self.switchTab(tab);
            });

            // Search input
            $(document).on('input', '#miniload-admin-search-input', function() {
                var term = $(this).val();

                clearTimeout(self.searchTimer);

                if (term.length < 2) {
                    self.clearResults();
                    $('#miniload-results-count').text('0 results');
                    return;
                }

                // Check cache
                var cacheKey = self.activeTab + '_' + term;
                if (self.cache[cacheKey]) {
                    self.displayResults(self.cache[cacheKey]);
                    return;
                }

                // Show loading
                self.showLoading();

                // Debounce search
                self.searchTimer = setTimeout(function() {
                    self.performSearch(term);
                }, 300);
            });

            // Keyboard navigation
            $(document).on('keydown', '#miniload-admin-search-input', function(e) {
                self.handleKeyboard(e);
            });

            // Result clicks
            $(document).on('click', '.miniload-admin-result', function(e) {
                e.preventDefault();
                var url = $(this).data('url');
                if (url) {
                    // Check if Ctrl/Cmd is held for new tab
                    if (e.ctrlKey || e.metaKey) {
                        window.open(url, '_blank');
                    } else {
                        window.location.href = url;
                    }
                }
                self.close();
            });

            // Add keyboard shortcut hint to admin menu
            this.addShortcutHint();

            // Handle copy link button clicks
            $(document).on('click', '.miniload-copy-link', function(e) {
                e.preventDefault();
                e.stopPropagation();

                var $button = $(this);
                var permalink = $button.data('permalink');

                if (permalink) {
                    // Copy to clipboard
                    self.copyToClipboard(permalink);

                    // Visual feedback
                    $button.addClass('copied');
                    $button.find('.copy-text').text('Copied!');

                    // Reset after 2 seconds
                    setTimeout(function() {
                        $button.removeClass('copied');
                        $button.find('.copy-text').text('Copy');
                    }, 2000);
                }
            });
        },

        copyToClipboard: function(text) {
            // Modern approach
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text);
            } else {
                // Fallback for older browsers
                var $temp = $('<input>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
            }
        },

        addShortcutHint: function() {
            // Removed Alt+K hint from menu as requested
            // The shortcut still works but is not displayed in the menu
        },

        switchTab: function(tab) {
            this.activeTab = tab;

            // Update active tab
            $('.miniload-admin-tab').removeClass('active');
            $('.miniload-admin-tab[data-tab="' + tab + '"]').addClass('active');

            // Update placeholder
            var placeholders = {
                'products': 'Search products by name, SKU, or ID...',
                'posts': 'Search posts and pages by title...',
                'orders': 'Search orders by ID, customer, or email...',
                'customers': 'Search customers by name or email...'
            };
            $('#miniload-admin-search-input').attr('placeholder', placeholders[tab]);

            // Clear and refocus
            $('#miniload-admin-search-input').val('').focus();
            this.clearResults();
            $('#miniload-results-count').text('0 results');
        },

        toggle: function() {
            if (this.isOpen) {
                this.close();
            } else {
                this.open();
            }
        },

        open: function() {
            var self = this;
            $('#miniload-admin-search-modal').fadeIn(200, function() {
                $('#miniload-admin-search-input').focus().select();
            });
            this.isOpen = true;
            $('body').addClass('miniload-search-open');

            // Reset to products tab
            this.switchTab('products');
        },

        close: function() {
            $('#miniload-admin-search-modal').fadeOut(200);
            $('#miniload-admin-search-input').val('');
            this.clearResults();
            this.isOpen = false;
            this.selectedIndex = -1;
            $('body').removeClass('miniload-search-open');

            // Abort any pending request
            if (this.currentRequest) {
                this.currentRequest.abort();
                this.currentRequest = null;
            }
        },

        performSearch: function(term) {
            var self = this;
            var startTime = Date.now();

            // Abort previous request
            if (this.currentRequest) {
                this.currentRequest.abort();
            }

            this.currentRequest = $.ajax({
                url: miniload_admin_search.ajax_url,
                type: 'POST',
                data: {
                    action: 'miniload_admin_tabbed_search',
                    term: term,
                    tab: self.activeTab,
                    nonce: miniload_admin_search.nonce
                },
                success: function(response) {
                    if (response.success) {
                        var searchTime = Date.now() - startTime;
                        response.data.searchTime = searchTime;

                        var cacheKey = self.activeTab + '_' + term;
                        self.cache[cacheKey] = response.data;
                        self.displayResults(response.data);
                    } else {
                        self.showError(response.data || 'Search failed');
                    }
                },
                error: function() {
                    if (self.currentRequest && self.currentRequest.statusText !== 'abort') {
                        self.showError('Connection error');
                    }
                },
                complete: function() {
                    self.currentRequest = null;
                }
            });
        },

        displayResults: function(data) {
            var html = '';
            var self = this;

            if (data.results && data.results.length > 0) {
                html += '<div class="miniload-admin-results-list">';

                $.each(data.results, function(i, item) {
                    html += '<div class="miniload-admin-result" data-url="' + (item.url || '#') + '" data-index="' + i + '">';

                    // Icon column
                    html += '<div class="miniload-result-icon">';
                    html += self.getIcon(item);
                    html += '</div>';

                    // Main content
                    html += '<div class="miniload-result-content">';
                    html += '<div class="miniload-result-title">' + self.highlightTerm(item.title, data.term) + '</div>';

                    if (item.meta) {
                        html += '<div class="miniload-result-meta">';
                        html += item.meta;
                        html += '</div>';
                    }
                    html += '</div>';

                    // Action column
                    html += '<div class="miniload-result-action">';

                    // Add copy link button for products and posts
                    if (item.permalink && (self.activeTab === 'products' || self.activeTab === 'posts')) {
                        html += '<button class="miniload-copy-link" data-permalink="' + item.permalink + '" title="Copy link">';
                        html += '<span class="dashicons dashicons-admin-links"></span>';
                        html += '<span class="copy-text">Copy</span>';
                        html += '</button>';
                    }

                    if (item.status) {
                        html += '<span class="miniload-status miniload-status-' + item.status + '">' + item.status + '</span>';
                    }
                    if (item.badge) {
                        html += '<span class="miniload-badge">' + item.badge + '</span>';
                    }
                    html += '</div>';

                    html += '</div>';
                });

                html += '</div>';

                // Add "View All Results" link
                if (data.term && data.term.length > 0) {
                    var viewAllUrl = '';

                    switch(self.activeTab) {
                        case 'products':
                            viewAllUrl = '/wp-admin/edit.php?s=' + encodeURIComponent(data.term) + '&post_type=product';
                            break;
                        case 'posts':
                            viewAllUrl = '/wp-admin/edit.php?s=' + encodeURIComponent(data.term);
                            break;
                        case 'orders':
                            viewAllUrl = '/wp-admin/edit.php?s=' + encodeURIComponent(data.term) + '&post_type=shop_order';
                            break;
                        case 'customers':
                            viewAllUrl = '/wp-admin/users.php?s=' + encodeURIComponent(data.term);
                            break;
                    }

                    if (viewAllUrl) {
                        html += '<a href="' + viewAllUrl + '" class="miniload-view-all">';
                        html += '<span class="dashicons dashicons-arrow-right-alt"></span> ';
                        html += 'View all results for "' + data.term + '"';
                        html += '</a>';
                    }
                }

                // Update count
                $('#miniload-results-count').text(data.results.length + ' results');
            } else {
                html = '<div class="miniload-admin-no-results">';
                html += '<span class="dashicons dashicons-search"></span>';
                html += '<p>No results found for "' + data.term + '"</p>';
                html += '<small>Try searching with different keywords</small>';
                html += '</div>';

                $('#miniload-results-count').text('0 results');
            }

            // Show search time
            if (data.searchTime) {
                $('#miniload-search-time').text('in ' + data.searchTime + 'ms');
            }

            $('#miniload-admin-search-results').html(html);
            this.selectedIndex = -1;
        },

        getIcon: function(item) {
            var icon = '';

            switch(this.activeTab) {
                case 'products':
                    if (item.image) {
                        icon = '<img src="' + item.image + '" alt="">';
                    } else {
                        icon = '<span class="dashicons dashicons-cart"></span>';
                    }
                    break;
                case 'posts':
                    icon = '<span class="dashicons dashicons-' + (item.type === 'page' ? 'admin-page' : 'admin-post') + '"></span>';
                    break;
                case 'orders':
                    icon = '<span class="dashicons dashicons-clipboard"></span>';
                    break;
                case 'customers':
                    if (item.avatar) {
                        icon = '<img src="' + item.avatar + '" alt="">';
                    } else {
                        icon = '<span class="dashicons dashicons-admin-users"></span>';
                    }
                    break;
                default:
                    icon = '<span class="dashicons dashicons-admin-generic"></span>';
            }

            return icon;
        },

        handleKeyboard: function(e) {
            var $results = $('.miniload-admin-result');
            var maxIndex = $results.length - 1;

            switch(e.keyCode) {
                case 38: // Up arrow
                    e.preventDefault();
                    this.selectedIndex = Math.max(-1, this.selectedIndex - 1);
                    this.highlightResult();
                    break;

                case 40: // Down arrow
                    e.preventDefault();
                    this.selectedIndex = Math.min(maxIndex, this.selectedIndex + 1);
                    this.highlightResult();
                    break;

                case 13: // Enter
                    e.preventDefault();
                    if (this.selectedIndex >= 0) {
                        var $selected = $results.eq(this.selectedIndex);
                        if ($selected.length) {
                            var url = $selected.data('url');
                            if (url && url !== '#') {
                                if (e.ctrlKey || e.metaKey) {
                                    window.open(url, '_blank');
                                } else {
                                    window.location.href = url;
                                }
                                this.close();
                            }
                        }
                    }
                    break;

                case 9: // Tab - switch tabs
                    e.preventDefault();
                    var tabs = ['products', 'posts', 'orders', 'customers'];
                    var currentIndex = tabs.indexOf(this.activeTab);
                    var nextIndex = e.shiftKey ?
                        (currentIndex - 1 + tabs.length) % tabs.length :
                        (currentIndex + 1) % tabs.length;
                    this.switchTab(tabs[nextIndex]);
                    break;
            }
        },

        highlightResult: function() {
            $('.miniload-admin-result').removeClass('selected');
            if (this.selectedIndex >= 0) {
                var $selected = $('.miniload-admin-result').eq(this.selectedIndex);
                $selected.addClass('selected');

                // Scroll into view if needed
                var $container = $('#miniload-admin-search-results');
                var containerTop = $container.scrollTop();
                var containerBottom = containerTop + $container.height();
                var elemTop = $selected.position().top + containerTop;
                var elemBottom = elemTop + $selected.height();

                if (elemTop < containerTop) {
                    $container.scrollTop(elemTop);
                } else if (elemBottom > containerBottom) {
                    $container.scrollTop(elemBottom - $container.height());
                }
            }
        },

        highlightTerm: function(text, term) {
            if (!term || !text) return text;
            var regex = new RegExp('(' + term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
            return text.replace(regex, '<mark>$1</mark>');
        },

        showLoading: function() {
            $('#miniload-admin-search-results').html(
                '<div class="miniload-admin-loading">' +
                '<span class="spinner is-active"></span>' +
                '<span>Searching...</span>' +
                '</div>'
            );
            $('#miniload-results-count').text('Searching...');
            $('#miniload-search-time').text('');
        },

        showError: function(message) {
            $('#miniload-admin-search-results').html(
                '<div class="miniload-admin-error">' +
                '<span class="dashicons dashicons-warning"></span>' +
                '<p>' + message + '</p>' +
                '</div>'
            );
            $('#miniload-results-count').text('Error');
            $('#miniload-search-time').text('');
        },

        clearResults: function() {
            $('#miniload-admin-search-results').empty();
            this.selectedIndex = -1;
        }
    };

    // Initialize when document is ready
    $(document).ready(function() {
        // Only initialize in admin area
        if ($('body').hasClass('wp-admin')) {
            MiniLoadAdminSearch.init();
        }
    });

})(jQuery);
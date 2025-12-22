# MiniLoad - WooCommerce Performance Optimizer

[![WordPress Version](https://img.shields.io/badge/WordPress-5.0%2B-blue)](https://wordpress.org)
[![WooCommerce Version](https://img.shields.io/badge/WooCommerce-3.0%2B-purple)](https://woocommerce.com)
[![PHP Version](https://img.shields.io/badge/PHP-7.2%2B-green)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL%20v2-red)](https://www.gnu.org/licenses/gpl-2.0.html)

MySQL Turbo Mode for WooCommerce - Optimize your store with pure MySQL performance, intelligent caching, and blazing-fast search without removing core functionality.

## 🚀 Features

### MySQL Turbo Mode
- **Smart SQL_CALC_FOUND_ROWS optimization** - Caches results instead of removing
- **Native MySQL FULLTEXT search** - 67% faster than default search
- **Denormalized sort indexes** - 90% faster product sorting
- **Query result caching** - Eliminates repeated expensive queries

### Lightning-Fast AJAX Search
- **FULLTEXT indexed search** - 5-10x faster than default WordPress search
- **Real-time results** with intelligent debouncing
- **SKU instant lookup** - Dedicated index for product codes
- **Multi-language support** - Persian, Arabic, RTL fully supported
- **Admin quick search** (Alt+K) for products, orders, and customers
- **Mobile-optimized** search interface

### Advanced Optimizations
- **Sort Index Module** - Eliminates postmeta JOINs
- **Filter Cache Module** - Instant layered navigation
- **Related Products Cache** - Pre-calculated relationships
- **Review Stats Cache** - Cached rating calculations
- **Order Search Optimizer** - 96% faster admin searches

### Performance Enhancements
- **No breaking changes** - Works with existing themes
- **Smart cache invalidation** - Auto-updates on changes
- **Batch processing** for large stores
- **Memory-efficient** design

### Modern Admin Interface
- **Single-page tabbed interface** - Clean and organized
- **Real-time statistics** dashboard
- **One-click index rebuilding**
- **Mobile-responsive** admin pages

## 📦 Installation

### From WordPress Admin
1. Download the plugin ZIP file
2. Navigate to **Plugins → Add New → Upload Plugin**
3. Choose the ZIP file and click **Install Now**
4. Activate the plugin

### Manual Installation
1. Upload the `miniload` folder to `/wp-content/plugins/`
2. Navigate to **Plugins** in WordPress admin
3. Activate **MiniLoad**

### Via Composer
```bash
composer require minimallteam/miniload
```

## 🔧 Configuration

### Initial Setup
1. After activation, go to **MiniLoad → Dashboard**
2. Click **Rebuild Search Index** to create the initial index
3. Configure search settings in **MiniLoad → Search Settings**
4. Enable desired modules in **MiniLoad → Modules**

### Search Shortcode
Add the AJAX search box anywhere using:
```
[miniload_search]
```

With parameters:
```
[miniload_search show_categories="true" placeholder="Search products..." min_chars="2" max_results="10"]
```

### Available Parameters
- `show_categories` - Display category filter (true/false)
- `placeholder` - Custom placeholder text
- `min_chars` - Minimum characters to trigger search (1-10)
- `max_results` - Maximum results to display (4-20)

## 🎯 Performance Benchmarks

| Feature | Before MiniLoad | After MiniLoad | Improvement |
|---------|----------------|----------------|-------------|
| Product Search | 852ms | 280ms | **67% faster** |
| Order Search | 2000ms | 70ms | **96% faster** |
| Product Sorting | 500ms | 50ms | **90% faster** |
| Filter Counts | 300ms | 10ms | **97% faster** |
| Pagination | Full table scan | Cached counts | **No more scans** |

*Results based on zabanmehrpub.com with 2800+ products*

## 🛠️ Developer Information

### Hooks and Filters

#### Filters
- `miniload_search_results` - Modify search results
- `miniload_index_content` - Control what gets indexed
- `miniload_search_query_args` - Modify search query parameters
- `miniload_enabled_modules` - Control which modules load

#### Actions
- `miniload_after_index_rebuild` - Run after index rebuild
- `miniload_before_search` - Run before search execution
- `miniload_modules_loaded` - Run after all modules are loaded

### REST API
MiniLoad includes REST API endpoints for search:
```
GET /wp-json/miniload/v1/search?term=keyword
```

### Example: Custom Search Result Template
```php
add_filter( 'miniload_search_results', function( $results, $search_term ) {
    // Modify search results
    foreach ( $results as &$result ) {
        $result['custom_field'] = get_post_meta( $result['id'], 'custom_field', true );
    }
    return $results;
}, 10, 2 );
```

### Example: Add Custom Fields to Index
```php
add_filter( 'miniload_index_content', function( $content, $product_id ) {
    $custom_data = get_post_meta( $product_id, 'custom_searchable_field', true );
    return $content . ' ' . $custom_data;
}, 10, 2 );
```

## 📊 System Requirements

### Minimum Requirements
- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.2 or higher
- MySQL 5.6 or higher (for FULLTEXT support)

### Recommended
- WordPress 6.0+
- WooCommerce 8.0+
- PHP 8.0+
- MySQL 8.0+
- Memory limit: 256M or higher

## 🤝 Contributing

We welcome contributions! Here's how you can help:

1. **Fork the repository**
2. **Create a feature branch** (`git checkout -b feature/AmazingFeature`)
3. **Commit your changes** (`git commit -m 'Add some AmazingFeature'`)
4. **Push to the branch** (`git push origin feature/AmazingFeature`)
5. **Open a Pull Request**

### Development Setup
```bash
# Clone the repository
git clone https://github.com/mohsengham/miniload.git

# Install dependencies
composer install
npm install

# Run tests
phpunit

# Build assets
npm run build
```

## 📝 Changelog

### Version 1.0.0 (2024-12-20)
- Initial release
- MySQL Turbo Mode implementation
- Smart SQL_CALC_FOUND_ROWS optimization (caching, not removal)
- FULLTEXT indexed product search with multi-language support
- Sort Index Module for instant sorting
- Filter Cache Module for layered navigation
- Related Products and Review Stats caching
- AJAX search with real-time results
- Admin quick search (Alt+K)
- Order Search Optimizer (96% faster)
- WordPress Plugin Check compliance
- Modern single-page admin interface

## 🐛 Support

- **Documentation**: [GitHub Wiki](https://github.com/mohsengham/miniload/wiki)
- **Issues**: [GitHub Issues](https://github.com/mohsengham/miniload/issues)
- **WordPress.org Forums**: [Support Forum](https://wordpress.org/support/plugin/miniload)

## 📜 License

This plugin is licensed under the GPL v2 or later.

## 👥 Credits

- **Author**: [MiniMall Team](https://github.com/mohsengham)
- **Contributors**: [See all contributors](https://github.com/mohsengham/miniload/graphs/contributors)

## 🌟 Acknowledgments

Special thanks to all contributors and testers who helped make MiniLoad better.

---

**Made with ❤️ for the WooCommerce community**
=== MiniLoad - Performance Optimizer for WooCommerce ===
Contributors: minimallteam
Tags: woocommerce, performance, optimization, ajax search, speed
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
WC requires at least: 3.0
WC tested up to: 10.4.3
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Supercharge your WooCommerce store with blazing-fast AJAX search, optimized queries, and intelligent caching.

== Description ==

**MiniLoad** is a comprehensive WooCommerce performance optimization plugin that dramatically improves your store's speed and user experience. Fully compatible with WooCommerce HPOS (High-Performance Order Storage).

= 🚀 Key Features =

**Lightning-Fast AJAX Search**
* FULLTEXT indexed product search (5-10x faster)
* Real-time search with debouncing
* Search by SKU, title, description, categories, and tags
* Admin quick search (Alt+K) for products, orders, and customers
* Mobile-optimized search interface

**Advanced Search Optimizations**
* Optional full product content indexing
* Media library search acceleration
* Editor link builder optimization using product index
* Search analytics and popular searches tracking

**Performance Enhancements**
* Smart query optimization for archives
* Reduced memory usage on category pages
* Intelligent caching system
* Batch processing for large stores

**Modern Admin Interface**
* Clean, tabbed interface
* Real-time statistics
* One-click index rebuilding
* Mobile-responsive admin

= Why Choose MiniLoad? =

* **Faster than FiboSearch** - Our FULLTEXT indexing outperforms traditional search plugins
* **Better than SearchWP** - Specifically optimized for WooCommerce
* **Lightweight** - Minimal impact on server resources
* **Developer Friendly** - Extensive hooks and filters
* **100% Free** - All features included, no premium version needed

== Installation ==

1. Upload the `miniload` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to MiniLoad → Dashboard to configure settings
4. Click "Rebuild Index" to create the search index

= Minimum Requirements =

* WordPress 5.0 or greater
* WooCommerce 3.0 or greater
* PHP version 7.2 or greater
* MySQL 5.6 or greater (for FULLTEXT support)

== Frequently Asked Questions ==

= How much faster is MiniLoad search? =

MiniLoad's FULLTEXT indexed search is typically 5-10x faster than default WordPress/WooCommerce search. On stores with 1000+ products, search queries complete in 50-100ms instead of 500-2000ms.

= Does it work with my theme? =

Yes! MiniLoad works with any properly coded WordPress theme. It enhances the existing WooCommerce functionality without breaking your design.

= Will it slow down my site? =

No. MiniLoad is designed for performance. The initial indexing happens once, and all optimizations actually make your site faster, not slower.

= Can I exclude product descriptions from search? =

Yes! You can choose to search only titles, SKUs, and short descriptions for even faster performance.

= Does it support variable products? =

Absolutely! MiniLoad indexes all product types including simple, variable, grouped, and external products.

== Screenshots ==

1. Modern admin dashboard with real-time statistics
2. Lightning-fast AJAX search in action
3. Admin quick search modal (Alt+K)
4. Search settings and configuration
5. Mobile-optimized search interface

== Changelog ==

= 1.0.5 =
* Fixed keyboard shortcut text from Ctrl+/ to Alt+K in admin settings and language files
* Updated JavaScript comment to reflect correct keyboard shortcut
* Fixed Alt+K shortcut working without checkbox being enabled - now respects the "Enable modal search" setting
* Added progress bar for index rebuilding operations to show real-time progress
* Implemented batch processing for both Product and Media index rebuilding to prevent timeouts
* Added configurable batch size setting (25-500 items) for index operations
* Fixed timeout issues during index rebuilding - now processes in configurable batches (default: 100)
* Fixed duplicate search icons showing in search box - added proper CSS rules for icon visibility
* Added fallback search when index is empty - now shows products even before index is built

= 1.0.4 =
* Removed hardcoded Persian text from JavaScript ("نمایش" and "نتیجه یافت شد")
* Fixed search results counter to display in English
* Improved internationalization support

= 1.0.3 =
* Fixed critical table name inconsistency bug (miniload_search_index vs miniload_product_search)
* Fixed search functionality not working due to incorrect table references
* Updated all modules to use correct table name
* Fixed index rebuild functionality
* Fixed AJAX search column mismatch (search_text vs content)
* Added missing database columns (content, stock_status, last_indexed)
* Fixed fulltext index for proper search functionality

= 1.0.2 =
* Fixed RTL/LTR admin margin alignment issues using CSS logical properties (fixes GitHub issue #2)
* Added HPOS (High-Performance Order Storage) compatibility declaration
* Updated WooCommerce tested version to 10.4.3
* Updated WordPress tested version to 6.9
* Improved CSS for better bidirectional text support

= 1.0.1 =
* Fixed critical bug where module settings (Related Products Cache, Review Stats Cache) weren't saving properly
* Fixed array notation handling for checkbox names in modules tab
* Standardized module storage format (all modules now use integer values)
* Improved settings save reliability by removing interfering filters
* Fixed data type consistency issues between boolean and integer values
* Fixed keyboard shortcut conflict: Admin search now uses Alt+K exclusively (was conflicting with WordPress 6.7+)
* Fixed WordPress Plugin Check warnings and errors
* Fixed PHP syntax errors in SQL queries
* Improved database query security with proper escaping
* Updated all GitHub repository URLs
* Removed analytics functionality completely
* Fixed Persian translation loading
* Performance improvements and bug fixes

= 1.0.0 =
* Initial release
* FULLTEXT indexed product search
* AJAX search with real-time results
* Admin quick search (Alt+K)
* Media library search optimization
* Editor link builder enhancement
* Search analytics
* Modern tabbed admin interface

== Upgrade Notice ==

= 1.0.5 =
Fixes keyboard shortcut text display to correctly show Alt+K instead of Ctrl+/.

= 1.0.4 =
Fixes hardcoded Persian text in search results. All text now displays in English by default.

= 1.0.3 =
Critical bug fix: Resolves table name inconsistency that prevented search from working. All users should update immediately.

= 1.0.2 =
Improves RTL/LTR support and adds WooCommerce HPOS compatibility. Recommended update for all users, especially those using RTL languages.

= 1.0.1 =
Critical update: Fixes module settings save issues, WordPress Plugin Check compliance, keyboard shortcut conflicts with WordPress 6.7+, and includes important security improvements.

= 1.0.0 =
Initial release of MiniLoad. Install to dramatically improve your WooCommerce store's search performance!

== Developer Information ==

MiniLoad provides extensive hooks and filters for developers:

**Filters:**
* `miniload_search_results` - Modify search results
* `miniload_index_content` - Control what gets indexed
* `miniload_search_query_args` - Modify search query parameters

**Actions:**
* `miniload_after_index_rebuild` - Run after index rebuild
* `miniload_before_search` - Run before search execution

**REST API:**
MiniLoad includes a REST API endpoint for search:
`/wp-json/miniload/v1/search`

== Support ==

For support, feature requests, and bug reports, please visit our [GitHub repository](https://github.com/mohsengham/miniload) or the WordPress.org support forum.

== Privacy Policy ==

MiniLoad does not collect or transmit any personal data. All search analytics are stored locally in your WordPress database and are never shared with third parties.
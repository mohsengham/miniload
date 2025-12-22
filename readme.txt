=== MiniLoad - Performance Optimizer for WooCommerce ===
Contributors: minimallteam
Tags: woocommerce, performance, optimization, ajax search, speed
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Supercharge your WooCommerce store with blazing-fast AJAX search, optimized queries, and intelligent caching.

== Description ==

**MiniLoad** is a comprehensive WooCommerce performance optimization plugin that dramatically improves your store's speed and user experience.

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
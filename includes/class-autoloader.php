<?php
/**
 * MiniLoad Autoloader
 *
 * @package MiniLoad
 * @since 1.0.0
 */

namespace MiniLoad;

// Exit if accessed directly
defined( 'ABSPATH' ) || exit;

/**
 * Autoloader class
 */
class Autoloader {

	/**
	 * Namespace prefix
	 *
	 * @var string
	 */
	private static $namespace = 'MiniLoad';

	/**
	 * Base directory
	 *
	 * @var string
	 */
	private static $base_dir;

	/**
	 * Initialize autoloader
	 */
	public static function init() {
		self::$base_dir = MINILOAD_PLUGIN_DIR;

		spl_autoload_register( array( __CLASS__, 'autoload' ) );
	}

	/**
	 * Autoload classes
	 *
	 * @param string $class Class name
	 */
	public static function autoload( $class ) {
		// Check if class is in our namespace
		$namespace = self::$namespace . '\\';
		$len = strlen( $namespace );

		if ( strncmp( $namespace, $class, $len ) !== 0 ) {
			return;
		}

		// Get the relative class name
		$relative_class = substr( $class, $len );

		// Build the file path
		$file = self::build_file_path( $relative_class );

		// If the file exists, require it
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}

	/**
	 * Build file path from class name
	 *
	 * @param string $class_name Class name
	 * @return string
	 */
	private static function build_file_path( $class_name ) {
		// Replace namespace separators with directory separators
		$class_path = str_replace( '\\', DIRECTORY_SEPARATOR, $class_name );

		// Convert to lowercase and hyphenated
		$file_name = 'class-' . strtolower( str_replace( '_', '-', $class_path ) ) . '.php';

		// Determine the directory based on namespace
		if ( strpos( $class_name, 'Modules\\' ) === 0 ) {
			// Module classes
			$dir = 'modules';
			$file_name = str_replace( 'modules' . DIRECTORY_SEPARATOR . 'class-', 'class-', $file_name );
		} elseif ( strpos( $class_name, 'Admin\\' ) === 0 ) {
			// Admin classes
			$dir = 'admin';
			$file_name = str_replace( 'admin' . DIRECTORY_SEPARATOR . 'class-', 'class-', $file_name );
		} elseif ( strpos( $class_name, 'CLI\\' ) === 0 ) {
			// CLI classes
			$dir = 'includes/cli';
			$file_name = str_replace( 'cli' . DIRECTORY_SEPARATOR . 'class-', 'class-', $file_name );
		} else {
			// Default to includes
			$dir = 'includes';
		}

		return self::$base_dir . $dir . DIRECTORY_SEPARATOR . $file_name;
	}
}
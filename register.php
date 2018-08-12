<?php
/**
 * Include to register this updater library.
 * @note No functions or classes should be declared here!
 */
namespace BurningMoth\Updater;

// minimum requirements ...
if (
	// PHP 5.3+
	version_compare(phpversion(), '5.3', '<')
	// WP 4.2+
	|| version_compare($GLOBALS['wp_version'], '4.2', '<')
) return true;

/* Register this updater library.
 * Since this library is installed at the component level (plugin or theme) multiple versions of it may co-exist in any given setup.
 * This filter determines which one will be loaded.
 * @syntax $updates[__DIR__] = [version number].
 */
add_filter(__NAMESPACE__.'\updaters', function( $updaters ){
	$updaters[__DIR__] = '1.1';
	return $updaters;
});

// initialize library ...
add_action('init', function(){

	// library already loaded ? exit ...
	if ( defined(__NAMESPACE__.'\VERSION') ) return;

	// get all registered updater libraries ...
	$updaters = apply_filters(__NAMESPACE__.'\updaters', array());

	// more than one updater ? sort updaters by version ...
	if ( count($updaters) > 1 ) uasort($updaters, 'version_compare');

	// define version from last updater, this will be the highest version ...
	define(__NAMESPACE__.'\VERSION', end($updaters));

	// define path from key of the last updater ...
	define(__NAMESPACE__.'\PATH', wp_normalize_path(key($updaters)));

	// define url from path ...
	define(__NAMESPACE__.'\URL', site_url(str_replace(wp_normalize_path(\ABSPATH), '', namespace\PATH)));

	// load updater ...
	include trailingslashit(namespace\PATH) . 'updater.php';

});

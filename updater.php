<?php
namespace BurningMoth\Updater;

/**
 * @var bool $is_admin
 */
$is_admin = is_admin();

/**
 * @var bool $is_cron
 */
$is_cron = defined('DOING_CRON');

// neither admin nor cron ? exit ...
if ( !$is_admin && !$is_cron ) return true;

/**
 * Return options array reference.
 * @return array
 */
function &options(){
	static $options;
	if ( ! isset($options) ) {
		$options = get_option(__NAMESPACE__, array());
		if ( ! is_array($options) ) $options = array();
	}
	return $options;
}

/**
 * Return option reference.
 * @param string $key
 * @param mixed $init
 * @return reference mixed
 */
function &option_ref( $key, $init = '' ){
	$options =& namespace\options();
	if ( ! isset($options[ $key ]) ) $options[ $key ] = $init;
	return $options[ $key ];
}

/**
 * Get option.
 * @param string $key
 * @param mixed $alt
 * @return mixed
 */
function option_get( $key, $alt = null ){
	$options =& namespace\options();
	return (
		isset($options[ $key ])
		? $options[ $key ]
		: $alt
	);
}

/**
 * Set or unset option.
 * @param string $key
 * @param mixed $value
 */
function option_set( $key, $value = null ){
	$options =& namespace\options();
	if ( is_null($value) ) unset($options[ $key ]);
	else $options[ $key ] = $value;
}

// update options if they've changed ...
add_action('shutdown', function(){

	// get previously recorded hash ...
	$old_hash = namespace\option_get('#', '');

	// delete old hash ...
	namespace\option_set('#', null);

	// get options ...
	$options =& namespace\options();

	// generate new hash value ...
	$new_hash = md5(json_encode($options));

	// old and new hashes don't match ? ...
	if ( $old_hash != $new_hash ) {

		// record new hash / this will become old hash next round ...
		$options['#'] = $new_hash;

		// update option ...
		update_option(__NAMESPACE__, $options, false);

	}

});


/**
 * Like plugin_basename but returns theme "stylesheet" name that Wordpress uses for theme id.
 * @param string $file
 * @return string
 */
function theme_basename( $file ) {
	return basename( dirname( $file ) );
}


/**
 * Return extensions to update.
 * @return array
 */
function get_extensions() {

	// get filtered extensions info ...
	$exts = apply_filters(__NAMESPACE__.'\extensions', array());

	// normalize path keys ...
	$exts = array_combine(
		array_map('wp_normalize_path', array_keys($exts)),
		array_values($exts)
	);

	// add additional extension data ...
	array_walk($exts, function( &$info, $file ){

		// plugin ? ...
		if (
			strpos($file, 'plugins/')
			&& ( include_once \ABSPATH . 'wp-admin/includes/plugin.php' )
			&& ( $plugin = get_plugin_data($file, false, false) )
			&& !empty($plugin['Version'])
		) {
			$info['type'] = 'plugin';
			$info['version'] = $plugin['Version'];
		}

		// theme ? ...
		elseif (
			strpos($file, 'themes/')
			&& ( $theme = wp_get_theme(namespace\theme_basename($file)) )
			&& $theme->exists()
		) {
			$info['type'] = 'theme';
			$info['version'] = $theme->get('Version');
		}

		// unknown ? ...
		else {
			$info = false;
			return;
		}

		// set dirname from file ...
		$info['dir'] = dirname($file);

		// create info object ...
		$info = (object) $info;

	});

	// return validated extensions ...
	return array_filter($exts);

}


/**
 * Return array of full file paths for given directory.
 * @param string $dir
 * @param bool $onlydir (false)
 * @param string $pattern ("*")
 * @return array
 */
function get_files( $dir, $onlydir = false, $pattern = '*' ) {

	// set flags ...
	$flags = (
		$onlydir
		? \GLOB_BRACE | \GLOB_ONLYDIR
		: \GLOB_BRACE
	);

	// parse out files list (including hidden) or return empty array ...
	if ( ( $files = glob( trailingslashit($dir).'{.,}'.$pattern, $flags ) ) === false ) return array();

	// return filtered files list ...
	return array_filter($files, function( $file ){ return !in_array(basename($file), [ '.', '..' ]); });

}

// admin panel ? load admin functions ...
if ( $is_admin ) include trailingslashit(namespace\PATH) . 'admin.php';

// doing cron ? load cron functions ...
if ( $is_cron ) include trailingslashit(namespace\PATH) . 'cron.php';

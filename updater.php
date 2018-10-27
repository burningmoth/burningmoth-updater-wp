<?php
namespace BurningMoth\Updater;


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

	static $exts;
	if ( isset($exts) ) return $exts;

	// get filtered extensions info ...
	$exts = (array) apply_filters(__NAMESPACE__.'\extensions', array());

	// normalize path keys ...
	$exts = array_combine(
		array_map('wp_normalize_path', array_keys($exts)),
		array_values($exts)
	);

	// add additional extension data ...
	array_walk($exts, function( &$info, $file ){

		// info not an array for some ungodly reason ? ick !
		if (
			!is_array($info)
		) {
			$info = false;
			return;
		}

		// plugin ? ...
		elseif (
			strpos($file, 'plugins/')
			&& ( include_once \ABSPATH . 'wp-admin/includes/plugin.php' )
			&& ( $plugin = get_plugin_data($file, false, false) )
			&& !empty($plugin['Version'])
		) {
			$info['name'] = $plugin['Name'];
			$info['id'] = plugin_basename($file);
			$info['type'] = 'plugin';
			$info['version'] = $plugin['Version'];
			$info['url'] = $plugin['PluginURI'];
		}

		// theme ? ...
		elseif (
			strpos($file, 'themes/')
			&& ( $theme = wp_get_theme(namespace\theme_basename($file)) )
			&& $theme->exists()
		) {
			$info['name'] = $theme->get('Name');
			$info['id'] = $theme->get_stylesheet();
			$info['type'] = 'theme';
			$info['version'] = $theme->get('Version');
			$info['url'] = $theme->get('ThemeURI');
		}

		// unknown ? ...
		else {
			$info = false;
			return;
		}

		// set major version ...
		$info['major_version'] = (integer) $info['version'];

		// repeat the file key ...
		$info['file'] = $file;

		// set dirname from file ...
		$info['dir'] = dirname($file);

		// create info object ...
		$info = (object) $info;

	});

	// return validated extensions ...
	return array_filter($exts);

}


/**
 * Get an extension manifest.
 * @since 1.1
 * @param object $ext
 * @return bool|object
 */
function get_manifest( $ext ) {

	// no manifest url ? ...
	if (
		!isset($ext->manifest_url)
		|| filter_var($ext->manifest_url, FILTER_VALIDATE_URL) === false
	) {
		trigger_error(sprintf('Invalid manifest url "%s"!', $ext->manifest_url), \E_USER_WARNING);
		return false;
	}

	// download manifest ...
	$response = wp_remote_get($ext->manifest_url);

	// failed to download ? ...
	if ( is_wp_error($response) ) {
		trigger_error($response->get_error_message(), \E_USER_WARNING);
		return false;
	}

	// decode manifest ...
	$manifest = json_decode($response['body']);

	// failed to download a valid manifest ? ...
	if (
		!is_object($manifest)
		|| !isset($manifest->versions)
		|| !is_array($manifest->versions)
	) {
		trigger_error(sprintf('Invalid manifest format %s!', $ext->manifest_url), \E_USER_WARNING);
		return false;
	}

	// return manifest ...
	return $manifest;

}


/**
 * Get a version update for an extension.
 * @since 1.1
 * @param object $ext
 * @return bool|object
 */
function get_version( $ext ) {

	global $wp_version;

	// manifest or nothing ...
	if (!( $manifest = namespace\get_manifest($ext) )) return false;

	// viable versions to update to ...
	$versions = array();

	// process manifest versions ...
	foreach ( $manifest->versions as $version ) {

		// verified version requirements ...
		if (
			!is_object($version)
			|| !isset($version->version)
			|| !isset($version->url)
		) continue;

		// greater version required ...
		if ( version_compare($version->version, $ext->version, '<=') ) continue;

		// set major version from version ...
		$version->major_version = (integer) $version->version;

		// restricted major version doesn't match ?
		if (
			!empty($ext->restrict_version)
			&& $ext->major_version !== $version->major_version
		) continue;

		// experimental version detected (non-standar-version chars) but not allowed ?
		if (
			empty($ext->allow_experimental)
			&& preg_match('/[^0-9\.,\-]/', $version->version)
		) continue;

		// minimum wordpress required ?
		if (
			isset($version->min_wordpress)
			&& version_compare($version->min_wordpress, $wp_version, '>')
		) continue;

		// maximum wordpress compatible ?
		if (
			isset($version->max_wordpress)
			&& version_compare($version->max_wordpress, $wp_version, '<=')
		) continue;

		// minimum php required ?
		if (
			isset($version->min_php)
			&& version_compare($version->min_php, phpversion(), '>')
		) continue;

		// maximum php compatible ?
		if (
			isset($version->max_php)
			&& version_compare($version->max_php, phpversion(), '<=')
		) continue;

		// minimum extension version ?
		if (
			isset($version->min_version)
			&& version_compare($version->min_version, $ext->version, '>')
		) continue;

		// maximum extension version ?
		if (
			isset($version->max_version)
			&& version_compare($version->max_version, $ext->version, '<=')
		) continue;

		// additional version validation ?
		if ( ! apply_filters(__NAMESPACE__.'\validate_version', true, $version, $ext, $manifest ) ) continue;

		// passed the tests ? add to potential versions ...
		$versions[] = $version;

	}

	// no versions ? move on ...
	if ( empty($versions) ) return false;

	// multiple versions available ? sort to get the latest ...
	if ( count($versions) > 1 ) usort($versions, function( $a, $b ){ return version_compare($a->version, $b->version); });

	// get the latest and greatest version ...
	return end($versions);

}


/**
 * Patch in our updates when wp_update_[plugins|themes]() stores its transient.
 * @see wp-includes/update.php wp_update_plugins()
 * @see wp-includes/update.php wp_update_themes()
 * @see wp-includes/option.php set_site_transient()
 */
add_filter('pre_set_site_transient_update_plugins', __NAMESPACE__.'\filter_update_extensions', 11, 2);
add_filter('pre_set_site_transient_update_themes', __NAMESPACE__.'\filter_update_extensions', 11, 2);
function filter_update_extensions( $value, $transient = 'update_plugins' ){

	// extension types ...
	$update_plugins = ( $transient == 'update_plugins' );
	$update_themes = ( $transient == 'update_themes' );

	// extensions to check for updates ...
	$exts = namespace\get_extensions();

	// filter appropriate type of extensions ...
	$exts = array_filter($exts, (
		$update_themes
		? function( $ext ){ return $ext->type == 'theme'; }
		: function( $ext ){ return $ext->type == 'plugin'; }
	));

	// process extensions into versions ...
	$versions = array_map(__NAMESPACE__.'\get_version', $exts);

	// filter out failed versions ...
	$versions = array_filter($versions);

	// no updates, return now ...
	if (
		empty($versions)
		|| !is_object($value)
		|| !isset($value->response)
		|| !is_array($value->response)
	) return $value;

	// key extensions appropriately ...
	$exts = array_combine(
		array_map(
			(
				$update_themes
				? __NAMESPACE__.'\theme_basename'
				: '\plugin_basename'
			),
			array_keys($exts)
		),
		array_values($exts)
	);

	// key versions appropriately ...
	$versions = array_combine(
		array_map(
			(
				$update_themes
				? __NAMESPACE__.'\theme_basename'
				: '\plugin_basename'
			),
			array_keys($versions)
		),
		array_values($versions)
	);

	// append updates to the response property ...
	foreach ( $versions as $id => $version ) {

		// extension ...
		$ext = $exts[ $id ];

		// create expected response object ...
		 $response = array_merge([

			// version to upgrade to ...
			'new_version' => $version->version,

			// zip file url ...
			'package' => $version->url,

			// url ...
			'url' => $ext->url,

		], (
			$update_themes
			? [
				'theme' => $id
			]
			: [
				'plugin' => $id,
				'id' => $id,
				'slug' => dirname($id),

				// 1x, 2x keys, urls end in image extension ...
				'banners' => [],
				'banners_rtl' => [],
				'icons' => [],

				// we already do a thorough PHP version check ...
				'requires_php' => false,

				// WP version, we already handle this too ...
				'tested' => $GLOBALS['wp_version'],

				// not sure what this does, but it's an empty object (think it contains descriptions) ...
				'compatibility' => (object) array()
			]
		));

		// version has description ? add it ...
		if ( !empty($version->description) ) $response['description'] = $version->description;

		// version has detail url ? update url ...
		if ( !empty($version->detail_url) ) $response['url'] = $version->detail_url;

		// plugin ? ...
		if ( $update_plugins ) {

			// remove from no updates ...
			unset($value->no_update[ $id ]);

			// has icon ? add icon ...
			if ( !empty($ext->icon) ) $response['icons']['1x'] = $ext->icon;

			// cast response array as object ...
			$response = (object) $response;

		}

		// update value ...
		$value->response[ $id ] = $response;

	}

	return $value;
}


/**
 * This loads a url in the 'View Details' thickbox link that WP automatically assigns to plugin updates.
 * Otherwise it will attempt to load our custom plugin from wp.com and fail.
 * @see wp-admin/plugin-install.php
 */
add_action('install_plugins_pre_plugin-information', function(){

	// get extensions ...
	$exts = namespace\get_extensions();

	// plugin slug doesn't match one of our extensions ? exit ...
	if (!( $ext = array_reduce($exts, function( $value, $ext ){

		return (
			$ext->type == 'plugin'
			&& dirname($ext->id) == $_GET['plugin']
			? $ext
			: $value
		);

	}, false) )) return;

	// extension slated for update ? use description or url ...
	if (
		( $updates = get_site_transient('update_plugins', false) )
		&& array_key_exists($ext->id, $updates->response)
		&& ( $update = $updates->response[ $ext->id ] )
	) {

		// die w/description ...
		if ( !empty($update->description) ) wp_die($update->description);

		// has url ? pass back to extension ...
		elseif( !empty($update->url) ) $ext->url = $update->url;

		// update version from update ...
		$ext->version = $update->new_version;

	}

	// has url ? redirect to it ...
	if (
		!empty($ext->url)
		&& wp_redirect($ext->url)
	) exit;

	// die w/default
	wp_die(sprintf(
		'No details provided for %s %s',
		$ext->name,
		$ext->version
	));

}, 1);


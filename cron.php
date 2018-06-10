<?php
/**
 * Cron functionality.
 */
namespace BurningMoth\Updater;


// ensure daily updates check is scheduled ...
add_action('shutdown', function(){

	if ( ! wp_next_scheduled(__NAMESPACE__.'\cron') ) {
		wp_schedule_event(time(), 'daily', __NAMESPACE__.'\cron');
	}

});


// check for updates ...
add_action(__NAMESPACE__.'\cron', function(){

	global $wp_version;

	// updates to store ...
	$updates = array();

	// extensions to check for updates ...
	$exts = namespace\get_extensions();

	// process extensions ...
	foreach ( $exts as $file => $ext ) {

		// no manifest url ? ...
		if (
			!isset($ext->manifest_url)
			|| filter_var($ext->manifest_url, FILTER_VALIDATE_URL) === false
		) continue;

		// download manifest ...
		$response = wp_remote_get($ext->manifest_url);

		// failed to download ? ...
		if ( is_wp_error($response) ) continue;

		// decode manifest ...
		$manifest = json_decode($response['body']);

		// failed to download a valid manifest ? ...
		if (
			!is_object($manifest)
			|| !isset($manifest->versions)
			|| !is_array($manifest->versions)
		) continue;

		// viable versions to update to ...
		$versions = array();

		// process manifest versions ...
		foreach ( $manifest->versions as $version ) {

			// verified version requirements ...
			if (
				!is_object($version)
				|| !isset($version->version)
				|| !isset($version->url)
				|| !isset($version->hash)
			) continue;

			// greater version required ...
			if ( version_compare($version->version, $ext->version, '<=') ) continue;

			// restricted major version doesn't match ?
			if (
				!empty($ext->restrict_version)
				&& (integer) $ext->version !== (integer) $version->version
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

			// passed the tests ? add to potential versions ...
			$versions[] = $version;

		}

		// no versions ? move on ...
		if ( empty($versions) ) continue;

		// multiple versions available ? sort to get the latest ...
		if ( count($versions) > 1 ) usort($versions, function( $a, $b ){ return version_compare($a->version, $b->version); });

		// get the latest and greatest version ...
		$version = end($versions);

		// add to updates ....
		$updates[ $file ] = $version;

	}

	// update updates ...
	namespace\option_set('updates', $updates);

});



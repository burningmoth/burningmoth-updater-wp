<?php
/**
 * Admin panel functionality.
 */
namespace BurningMoth\Updater;

// add script to extension pages ...
add_filter('admin_enqueue_scripts', function( $hook ){

	if ( in_array($hook, [ 'plugins.php', 'themes.php' ]) ) {

		wp_enqueue_script(
			__NAMESPACE__.'\admin',
			trailingslashit(namespace\URL) . 'admin.js',
			[ 'jquery' ],
			namespace\VERSION
		);

		if (
			$hook == 'themes.php'
			&& current_user_can('install_themes')
		) add_action('admin_footer', function(){

			print '<script type="text/javascript">jQuery(window).on("load", function( event ){ ';

			$updates = namespace\option_get('updates', array());
			$updates = array_combine(
				array_map(__NAMESPACE__.'\theme_basename', array_keys($updates)),
				array_values($updates)
			);

			foreach ( $updates as $theme => $update ) printf('BMCUpdater.addThemeUpdateButton("%s", "%s"); ', $theme, $update->version);

			print '});</script>';


		});

	}

});


// Add update link to a plugin w/update ...
add_filter('plugin_row_meta', function( $links, $file = '' ){

	if ( ! current_user_can('install_plugins') ) return $links;

	static $updates;
	if ( !isset($updates) ) {

		// get cron processed updates ...
		$updates = namespace\option_get('updates', array());
		$updates = array_combine(
			array_map('plugin_basename', array_keys($updates)),
			array_values($updates)
		);

	}

	if ( array_key_exists($file, $updates) ) {

		$update = $updates[ $file ];

		$links['update'] = sprintf(
			'<a href="javascript:BMCUpdater.updateExtension(\'%s\',\'plugin\');" title="%s">%s Update</a>',
			$file,
			esc_attr( empty($update->description) ? '' : $update->description ),
			$update->version
		);

	}

	return $links;

}, 99, 2);


/* Run update on extension.
 * @post string $plugin (plugin basename)
 *	- OR -
 * @post string $theme (stylesheet name)
 */
add_action('wp_ajax_bmcupdater_update_extension', function(){

	// defaults ...
	$theme = $plugin = false;

	// get post vars ...
	extract($_POST);

	// get current updates ...
	$updates = namespace\option_get('updates', array());

	// key on plugin ? ...
	if (

		$plugin

		&& current_user_can('install_plugins')

		// map plugin basename to update file key ...
		&& ( $extension_files = array_combine(
			array_map('plugin_basename', array_keys($updates)),
			array_keys($updates)
		) )

		&& array_key_exists($plugin, $extension_files)

	) $file_extension = $extension_files[ $plugin ];

	// key on theme ? ...
	elseif (

		$theme

		&& current_user_can('install_themes')

		// map theme stylesheet name to update file key ...
		&& ( $extension_files = array_combine(
			array_map(__NAMESPACE__.'\theme_basename', array_keys($updates)),
			array_keys($updates)
		) )

		&& array_key_exists($theme, $extension_files)

	) $file_extension = $extension_files[ $theme ];

	// no key ? error ...
	else wp_send_json_error( new \WP_Error('no_update', 'No update defined for extension.') );

	// determine extension directory ...
	$dir_extension = dirname($file_extension);

	// update object ...
	$update = $updates[ $file_extension ];

	// remove update from updates ...
	unset($updates[ $file_extension ]);
	namespace\option_set('updates', $updates);

	// get file headers ...
	$response = wp_remote_head($update->url);

	// error ? ...
	if ( is_wp_error($response) ) wp_send_json_error($response);

	// has etag and etag doesn't match update hash ? ...
	if (
		$response['headers']->offsetExists('etag')
		&& $update->hash != $response['headers']->offsetGet('etag')
	) wp_send_json_error( new \WP_Error('checksum_failed', 'Remote file could not be verified.') );

	/* Download the file to temp directory.
	 * @see https://developer.wordpress.org/reference/classes/WP_Http/request/
	 */
	$response = wp_remote_get($update->url, array(
		'stream' => true,
	));

	// download failed ? ...
	if ( is_wp_error($response) ) wp_send_json_error($response);

	// get downloaded file path ...
	$file = $response['filename'];

	// check hash again ...
	if ( md5_file($file) != $update->hash ) {
		unlink($file);
		wp_send_json_error( new \WP_Error('checksum_failed', 'Downloaded file could not be verified.') );
	}

	// call up filesystem ...
	if ( !WP_Filesystem() ) {
		unlink($file);
		wp_send_json_error( new \WP_Error('no_filesystem', 'Wordpress filesystem could not be loaded.') );
	}

	// @see https://developer.wordpress.org/reference/classes/wp_filesystem_direct/
	global $wp_filesystem;

	// unzip file to temp directory ...
	$dir_temp = get_temp_dir() . $update->hash;
	$is_unzipped = unzip_file($file, $dir_temp);

	if ( is_wp_error($is_unzipped) ) {
		unlink($file);
		wp_send_json_error($is_unzipped);
	}

	// delete zip file ...
	unlink($file);

	// get directories from temp ...
	$dirs = namespace\get_files($dir_temp, true);

	// get the directory to update from (should be only one) or fail ...
	if ( ( $dir_update = current($dirs) ) === false ) wp_send_json_error( new \WP_Error('no_filesystem', 'Extension update lacks a files directory.') );

	// hook action before update takes place ...
	do_action(__NAMESPACE__.'\before_update', $file_extension, ( $plugin ? $plugin : $theme ), ( $plugin ? 'plugin' : 'theme' ));

	// deactivate extension ...
	if ( $plugin ) {
		$is_network_active = is_plugin_active_for_network($plugin);
		if ( $is_active = is_plugin_active($plugin) ) deactivate_plugins($plugin, true, $is_network_active);
	}

	// clear extension directory ...
	$files = namespace\get_files($dir_extension);
	foreach ( $files as $file ) $wp_filesystem->delete($file, true);

	// move newly unpacked files into extension directory ...
	copy_dir($dir_update, $dir_extension);

	// delete temp directory ...
	$wp_filesystem->rmdir($dir_temp, true);

	// plugin cleanup and reactivation ...
	if ( $plugin ) {

		// look for updated plugin file (it may have changed) ...
		if (
			!is_file($file_extension)
			|| (
				( $data = get_plugin_data($file_extension) )
				&& empty($data['Version'])
			)
		) {
			$files = namespace\get_files($dir_extension, false, '*.php');
			foreach ( $files as $file ) {
				$data = get_plugin_data($file);
				if ( !empty($data['Version']) ) {
					$file_extension = $file;
					$plugin = plugin_basename($file);
					break;
				}
			}
		}

		// clear cache ...
		wp_clean_plugins_cache(true);

		// reactivate plugin ...
		if ( $is_active ) activate_plugins($plugin, '', true, $is_network_active);

	}

	// theme cleanup and reactivation ...
	else {

		// clear cache ...
		wp_clean_themes_cache(true);
	}

	// hook action after update has taken place ...
	do_action(__NAMESPACE__.'\after_update', $file_extension, ( $plugin ? $plugin : $theme ), ( $plugin ? 'plugin' : 'theme' ));

	wp_send_json_success();

});


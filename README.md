# WordPress Extension Updater
Checks for, downloads and installs updates to an individual theme or plugin hosted by third party.

## Installation
After installing this library to a plugin or theme include the `register.php` file in it anywhere so long as it's before the `init` action hook is called.

	<?php
	...
	
	// for plugins ...
	include trailingslashit(plugin_dir_path(__FILE__)) . "burningmoth-updater-wp/register.php";
	
	// for themes ...
	include trailingslashit(get_stylesheet_directory()) . "burningmoth-updater-wp/register.php";
	
	...
	

To avoid conflicts, if more than one active extension happens to register versions of this library then only the most recent version is loaded, presumption being that it remains backwards compatible.

Register the plugin or theme with the following filter.

	<?php
	...
	add_filter('BurningMoth\Updater\extensions', function( $extensions ){
	
		$extensions[__FILE__] = array(
			
			/**
			 * URL pointing to the update manifest.
			 * @var url (required)
			 */
			'manifest_url' => 'https://host.domain/path/to/manifest.json',
			
			/**
			 * Restrict updates to current major version of extension.
			 * For instance, if 1.2 then will update to 1.3 but not 2.0.
			 * Useful for stability if anything else depends on the extension.
			 * @var bool (optional)
			 */
			'restrict_version' => true,
			
			/**
			 * Allow updates to versions that contain letters.
			 * For instance, 1.2b or 2.0-dev
			 * @var bool (optional)
			 */
			'allow_experimental' => true,
			
		);
		
		return $extensions;
	
	});	
	...

## manifest.json

	{
		"versions": [
			{
				"version": "1.0",
				"url": "https://host.domain/path/to/extension.zip",
				"hash": "[md5_file value of extension.zip]",
				"description": "Brief summary of changes in this version.",
				"min_wordpress": "[minimum wordpress version compatibility]",
				"max_wordpress": "[maximum wordpress version compatibility]",
				"min_php": "[minimum php version compatibility]",
				"max_php": "[maximum php version compatibility]"
			},
			...
		]
	}

The above `version`, `url` and `hash` keys are required. Others are optional.

The extension ZIP file should be one suitable for manual installation, containing a single directory containing the extension files.

Cron hook `BurningMoth\Updater\cron` is scheduled to process manifests for updates daily.

## Permissions
User `install_plugins` and `install_themes` permissions are required for update links to appear on admin Plugins and Themes pages respectively.

## Hooks

	<?php
	...
		
	// fires before plugin is temporarily deactivated and files are updated
	add_action(
		'BurningMoth\Updater\before_update',
		/**
		 * Action callback.
		 * @param string $file
		 *	- full path to extension file
		 *	- for plugins, the primary file containing meta data
		 * 	- for themes, probably functions.php or style.css
		 * @param string $id
		 *	- for plugins, the plugin basename, ex. "my_plugin/my_plugin.php"
		 *	- for themes, the theme stylesheet, ex. "my_theme"
		 * @param string $type
		 *	- either 'plugin' or 'theme'
		 */
		function( $file, $id = '', $type = 'plugin' ){
			...
		}, 
		10, 3
	);
	
	// fires after files have been updated and plugin reactivated
	add_action(
		'BurningMoth\Updater\after_update',
		/**
		 * Action callback same as BurningMoth\Updater\before_update callback.
		 * @note if a plugin update has changed which file the meta data is stored in then $file and $id values will reflect that change.
		 */
		function( $file, $id = '', $type = 'plugin' ){ ... }, 
		10, 3 
	);
	
	// validates an extension version from downloaded manifest
	add_filter(
		'BurningMoth\Updater\validate_version',
		/**
		 * Filter callback.
		 * @param bool $valid
		 * @param string $file
		 *	- full path to extension file
		 *	- for plugins, the primary file containing meta data
		 * 	- for themes, probably functions.php or style.css
		 * @param object $version
		 *	- version object defined by manifest.json
		 * @param object $extension_info
		 *	- object processed from BurningMoth\Updater\extensions filter
		 *	- minimally contains properties
		 *		- manifest_url (url manifest was downloaded from)
		 *		- version (extension version)
		 *		- type ("theme" or "plugin")
		 *		- dir (system path to directory where extension is installed)
		 * @param object $manifest
		 *	- downloaded manifest
		 * @return bool
		 */
		function( $info, $valid, $file, $version, $extension_info, $manifest ){ ... },
		10, 4
	);
	
	...		

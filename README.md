# WordPress Extension Updater
Checks for updates to an individual theme or plugin hosted by third party.

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
			
			/**
			 * URL pointing to an icon image.
			 * Displayed for plugins on the WordPress updates page.
			 * @var url (optional)
			 */
			'icon' => 'https://host.domain/path/to/icon.png',
			
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
				"detail_url": "https://host.domain/path/to/release/notes.html",
				"description": "Brief summary of changes in this version.",
				"min_wordpress": "[minimum wordpress version compatibility]",
				"max_wordpress": "[maximum wordpress version compatibility]",
				"min_php": "[minimum php version compatibility]",
				"max_php": "[maximum php version compatibility]",
				"min_version": "[minimum extension version to upgrade from]",
				"max_version": "[maximum extension version to upgrade from]"
			},
			...
		]
	}

The above `version` and `url` keys are required. Others are optional.

The extension ZIP file should be one suitable for manual installation, containing a single directory containing the extension files.

## Hooks

	<?php
	...			
	
	// validates an extension version from downloaded manifest
	add_filter(
		'BurningMoth\Updater\validate_version',
		/**
		 * Filter callback.
		 * @param bool $valid		 
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
		function( $valid, $version, $extension_info, $manifest ){ ... },
		10, 4
	);
	
	...		

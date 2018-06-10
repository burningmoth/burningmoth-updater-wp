/**
 * Admin scripts.
 */
var BMCUpdater = {

	/**
	 * Send AJAX update command.
	 * @param string extension
	 * @param string type
	 */
	updateExtension: function( extension, type ){

		var data = { 'action': 'bmcupdater_update_extension' };
		data[ type ] = extension;

		jQuery.post(
			ajaxurl,
			data,
			function( data ){

				if (
					!data.success
					&& Array.isArray(data.data)
					&& data.data.length
				) {
					var errs = data.data.map( err => err.code + ': ' + err.message );
					alert( errs.join("\n") );
				}

				location.reload();

			},
			'json'
		);

	},

	/**
	 * Add theme update button to active theme.
	 * @param string theme
	 * @param string version
	 */
	addThemeUpdateButton: function( theme, version ){

		jQuery('[data-slug="' + theme + '"].theme.active .theme-actions').prepend('<a class="button updater" href="javascript:BMCUpdater.updateExtension(\'' + theme + '\', \'theme\');">' + version + ' Update</a>');

	},

};

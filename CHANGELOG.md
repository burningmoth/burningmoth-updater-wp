# Change Log

### Version 2.0 / 2018 Oct 05
* Added: integration into WordPress updater system via `pre_site_transient_*` filter hooks.
* Added: intercept "View Details" thickbox link to display updating version `description`, load version `detail_url`, load plugin url or displays "no details" message rather than an error when WordPress can't find details for custom plugins.
* Removed: various admin, cron and hooks functionality associated w/1.x custom updater scheme.
* Deprecated: version `hash` key is no longer utilized.

### Version 1.1 / 2018 Aug 11
* Added: BurningMoth\Updater\validate_version filter hook.

### Version 1.0 / 2018 Jun 10
* Maiden voyage.

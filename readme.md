# CBA-Migration-Plugin

WordPress plugin that provides a WP CLI script which migrates incompatible data from the College of Business's WordPress site into usable metadata under the Colleges-Theme and its supported plugins.


## Requirements
- WP CLI

The migration plugin should be run against a WordPress site that previously had the CBA-Theme as its active theme and has existing post data.  This migration script is not for use against a brand new WordPress site.

The Colleges-Theme should be the active theme, and all dependent plugins (e.g. Person CPT, Degree CPT plugins) should be installed and activated prior to running the script.


## Installation

### Manual Installation
1. Upload the plugin files (unzipped) to the `/wp-content/plugins` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress

### WP CLI Installation
1. `$ wp plugin install --activate https://github.com/UCF/CBA-Migration-Plugin/archive/master.zip`.  See [WP-CLI Docs](http://wp-cli.org/commands/plugin/install/) for more command options.


## Usage

`$ wp cba migrate --url=http://business.ucf.edu` (Update the URL to point to the site relative to the environment being run against)

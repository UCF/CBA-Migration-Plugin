# CBA-Migration-Plugin

WordPress plugin that provides a WP CLI script which migrates incompatible data from the College of Business's WordPress site into usable metadata under the Colleges-Theme and its supported plugins.


## Requirements
- WP CLI
- Advanced Custom Fields Pro
- UCF Degree Custom Post Type
- UCF Departments Taxonomy
- UCF Employee Types Taxonomy
- UCF People Custom Post Type

The migration plugin should be run against a WordPress site that previously had the CBA-Theme as its active theme and has existing post data.  This migration script is not for use against a brand new WordPress site.

The Colleges-Theme should be the active theme, all dependent plugins for the Business site should be installed and activated, and ACF field groups from the Colleges-Theme repo should be imported prior to running the migration script.


## Installation

### Manual Installation
1. Upload the plugin files (unzipped) to the `/wp-content/plugins` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the "Plugins" screen in WordPress

### WP CLI Installation
1. `$ wp plugin install --activate https://github.com/UCF/CBA-Migration-Plugin/archive/master.zip`.  See [WP-CLI Docs](http://wp-cli.org/commands/plugin/install/) for more command options.


## Usage

`$ wp cba migrate --url="http://business.ucf.edu"` (Update the URL to point to the site relative to the environment being run against)


## Changelog

### 1.0.1
* Degree migration now sets the 'degree_import_ignore' meta field to 'on' for all executive education degrees, allowing them to remain intact after running a degree data import
* Renamed undergraduate and graduate program terms on import to match what the new degree data importer will expect

### 1.0.0
* Initial release

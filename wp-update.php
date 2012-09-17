#!/usr/bin/php
<?php
(PHP_SAPI === 'cli') or die("Script can only be run from the command line.\n");

function err($msg)
{
	fwrite(STDERR, "$msg\n");
	exit(1);
}

if ($argc <= 1)
{
	err("usage: wp-update.php PATH_TO_WORDPRESS\n");
}

$wp_dir = realpath($argv[1]);

if ( ! $wp_dir || ! file_exists($wp_dir.'/wp-config.php'))
{
	err($argv[1]." doesn't seem to be a WordPress installation\n");
}


if ( ! in_array('--raw', $argv))
{
	function strip_html_and_trim($html)
	{
		// Remove contents of script tags
		$html = preg_replace('#<script .*?</script>#', '', $html);
		
		// Make sure we have line breaks at end of every paragraph
		$html = str_replace('</p>', "\n</p>", $html);
		
		$html = strip_tags($html);
		$html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
		
		// Remove some WordPress junk
		$html = str_replace(array
		(
			'Actions: Return to Plugins page | Return to WordPress Updates',
			'Show DetailsHide Details.'
		), '', $html);
		
		// Trim all lines and remove blank lines
		$html = join("\n", array_filter(array_map('trim', explode("\n", $html))));
		
		return ($html !== '') ? "$html\n" : '';
	}
	
	$handle = popen(escapeshellarg(__FILE__).' '.escapeshellarg($wp_dir).' --raw 2>&1', 'r');
	
	while(($line = fgets($handle)) !== FALSE)
	{
		echo strip_html_and_trim($line);
	}
	
	$finished = feof($handle);
	
	exit($finished ? 0 : 1);
}
else
{
	chdir($wp_dir.'/wp-admin');


	// Disable admin security checks.
	// These override defaults in `wp-includes/pluggable.php`
	function auth_redirect() { return true; }
	function check_admin_referer() { return true; }

	// We need an object to return from `wp_get_current_user` with sufficient
	// capabilities to upgrade WordPress. This will do the trick ...
	class Dummy_Admin_User
	{
                // This fella' has a "can do" attitude ...
                public function has_cap() { return true; }
                // ... and he exists
                public function exists() { return true; }
                // He has properties ...
                public function has_prop() { return true; }
                // ... and he can be retrieved
                public function get() { return $this; }
	}

	// Overrides default in `wp-includes/pluggable.php`
	function wp_get_current_user()
	{
		static $user;
		return isset($user) ? $user : ($user = new Dummy_Admin_User());
	}

	// Include WordPress
	include './admin.php';

	// This is necessary to actually display notifications from the
	// update processes 
	add_filter('update_feedback', 'show_message');


	// Get all core updates, including ones that have been dismissed
	$updates = get_core_updates(array('dismissed' => true));
	$latest = reset($updates);

	$result = wp_update_core($latest);
	
	// Borrowed verbatim from wp-admin/update-core.php
	if ( is_wp_error($result) ) {
		show_message($result);
		if ('up_to_date' != $result->get_error_code() )
			show_message( __('Installation Failed') );
	} else {
		show_message( __('WordPress upgraded successfully') );
	}

	// Update plugins
	$plugins_to_update = array_keys(get_plugin_updates());
	// Compatibility with older WordPress versions
	$skin = class_exists('Bulk_Plugin_Upgrader_Skin') ? new Bulk_Plugin_Upgrader_Skin() : null;
	$upgrader = new Plugin_Upgrader($skin);
	$upgrader->bulk_upgrade($plugins_to_update);

	exit(0);
}

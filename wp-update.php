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

chdir($wp_dir.'/wp-admin');


// Disable admin security checks.
// These override defaults in `wp-includes/pluggable.php`
function auth_redirect() { return true; }
function check_admin_referer() { return true; }

// We need an object to return from `wp_get_current_user` with sufficient
// capabilities to upgrade WordPress. This will do the trick ...
class Dummy_Admin_User
{
	// This fella' has a "can do" attitude
	public function has_cap() { return true; }
}

// Overrides default in `wp-includes/pluggable.php`
function wp_get_current_user()
{
	static $user;
	return isset($user) ? $user : ($user = new Dummy_Admin_User());
}

// Remove all HTML from output.
// Ideally we would use a regular implicit flush (say, every 16 bytes)
// so we can see what WordPress is up to as it's working. Unfortunately,
// WordPress insists on regularly calling `wp_ob_end_flush_all` which
// clears all existing output buffers. This means that we have to set
// the `erase` param to `false` (preventing the buffer being removed)
// and send all the output at the end. 
ob_start('strip_html_and_trim', 0, false);

function strip_html_and_trim($html)
{
	// Remove contents of script tags
	$html = preg_replace('#<script .*?</script>#', '', $html);
	$html = str_replace('</p>', "\n</p>", $html);
	$html = strip_tags($html);
	$html = html_entity_decode($html, ENT_QUOTES, 'UTF-8');
	// Trim all lines and remove blank lines
	$html = join("\n", array_filter(array_map('trim', explode("\n", $html))));
	return ($html !== '') ? "$html\n" : '';
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

// Update all plugins
$all_plugins = array_keys(get_plugins());
// Compatibility with older WordPress versions
$skin = class_exists('Bulk_Plugin_Upgrader_Skin') ? new Bulk_Plugin_Upgrader_Skin() : null;
$upgrader = new Plugin_Upgrader($skin);
$upgrader->bulk_upgrade($all_plugins);

exit(0);

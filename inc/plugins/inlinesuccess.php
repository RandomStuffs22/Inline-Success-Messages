<?php
/**
 * Inline Success Messages
 * 
 * Adds support for inline success messages instead of redirection pages.
 *
 * @package Inline Success Messages
 * @author  Shade <legend_k@live.it>
 * @license http://www.gnu.org/licenses/ GNU/GPL license
 * @version 1.0
 */

if (!defined('IN_MYBB')) {
	die('Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.');
}

if (!defined("PLUGINLIBRARY")) {
	define("PLUGINLIBRARY", MYBB_ROOT . "inc/plugins/pluginlibrary.php");
}

function inlinesuccess_info()
{
	return array(
		'name' => 'Inline Success Messages',
		'description' => 'Adds support for inline success messages globally instead of an (un)friendly redirection page.',
		'website' => 'https://github.com/Shade-/Inline-Success-Messages',
		'author' => 'Shade',
		'version' => '1.0',
		'compatibility' => '16*',
		'guid' => 'f6f2925d440239e6f3a894703ba088c6'
	);
}

function inlinesuccess_is_installed()
{
	global $cache;
	
	$info      = inlinesuccess_info();
	$installed = $cache->read("shade_plugins");
	if ($installed[$info['name']]) {
		return true;
	}
}

function inlinesuccess_install()
{
	global $db, $mybb, $cache, $PL, $lang;
	
	if (!file_exists(PLUGINLIBRARY)) {
		flash_message("The selected plugin could not be installed because <a href=\"http://mods.mybb.com/view/pluginlibrary\">PluginLibrary</a> is missing.", "error");
		admin_redirect("index.php?module=config-plugins");
	}
	
	if (!$lang->inlinesuccess) {
		$lang->load('inlinesuccess');
	}
	
	// add the plugin to our cache
	$info                        = inlinesuccess_info();
	$shadePlugins                = $cache->read('shade_plugins');
	$shadePlugins[$info['name']] = array(
		'title' => $info['name'],
		'version' => $info['version']
	);
	$cache->update('shade_plugins', $shadePlugins);
	
	$PL or require_once PLUGINLIBRARY;
	
	// add templates	   
	$dir       = new DirectoryIterator(dirname(__FILE__) . '/InlineSuccess/templates');
	$templates = array();
	foreach ($dir as $file) {
		if (!$file->isDot() AND !$file->isDir() AND pathinfo($file->getFilename(), PATHINFO_EXTENSION) == 'html') {
			$templates[$file->getBasename('.html')] = file_get_contents($file->getPathName());
		}
	}
	$PL->templates('inlinesuccess', 'Inline Success Messages', $templates);
	
	// add settings
	$PL->settings('inlinesuccess', $lang->inlinesuccess_settings, $lang->inlinesuccess_settings_desc, array(
		'enabled' => array(
			'title' => $lang->inlinesuccess_settings_enable,
			'description' => $lang->inlinesuccess_settings_enable_desc,
			'value' => '1'
		)
	));
	
	// add $success variable wherever a {$_error_} variable is found
	find_replace_multitemplatesets('(\{\$([^->]*)error(.*)\})', '$0 {$inlinesuccess}');
	
}

function inlinesuccess_uninstall()
{
	global $db, $cache, $PL;
	
	if (!file_exists(PLUGINLIBRARY)) {
		flash_message("The selected plugin could not be uninstalled because <a href=\"http://mods.mybb.com/view/pluginlibrary\">PluginLibrary</a> is missing.", "error");
		admin_redirect("index.php?module=config-plugins");
	}
	
	$PL or require_once PLUGINLIBRARY;
	
	$info         = inlinesuccess_info();
	// delete the plugin from cache
	$shadePlugins = $cache->read('shade_plugins');
	unset($shadePlugins[$info['name']]);
	$cache->update('shade_plugins', $shadePlugins);
	
	$PL->templates_delete('inlinesuccess');
	$PL->settings_delete('inlinesuccess');
	
	// remove $success variable	
	find_replace_multitemplatesets('\{\$inlinesuccess\}', '');
}

if ($mybb->settings['inlinesuccess_enabled']) {
	$plugins->add_hook("redirect", "inlinesuccess_redirect");
	$plugins->add_hook("global_start", "inlinesuccess_global_start");
	$plugins->add_hook("usercp_start", "inlinesuccess_usercp_start");
}

// Populate the session and redirects the user to the page he came from
function inlinesuccess_redirect(&$args)
{
	global $mybb;
	
	// don't do anything if the user doesn't want it
	if ($mybb->user['showredirect']) {
		return;
	}
	
	if (!session_id()) {
		session_start();
	}
	
	if (!$args['message']) {
		$args['message'] = $lang->redirect;
	}
	
	$time    = TIME_NOW;
	$timenow = my_date($mybb->settings['dateformat'], $time) . " " . my_date($mybb->settings['timeformat'], $time);
	
	if (!$args['title']) {
		$args['title'] = $mybb->settings['bbname'];
	}
	
	$url = htmlspecialchars_decode($args['url']);
	$url = str_replace(array(
		"\n",
		"\r",
		";"
	), "", $url);
	
	// after running any shutdown functions...
	run_shutdown();
	
	// ... append the message to the _SESSION and let another function do the rest
	$_SESSION['inlinesuccess'] = array(
		'message' => $args['message']
	);
	
	// the HTTP_REFERER should be trusted as the redirect() function is usually fired on POST requests in usercp (which is the main reason we are installing this plugin)
	if (THIS_SCRIPT == 'usercp.php') {
		header("Location: {$_SERVER['HTTP_REFERER']}");
	} else if (my_substr($url, 0, 7) !== 'http://' && my_substr($url, 0, 8) !== 'https://' && my_substr($url, 0, 1) !== '/') {
		header("Location: {$mybb->settings['bburl']}/{$url}");
	} else {
		header("Location: {$url}");
	}
	
	exit;
}

// Populates the $success variable
function inlinesuccess_global_start()
{
	global $mybb, $inlinesuccess, $templates, $templatelist;
	
	if (!session_id()) {
		session_start();
	}
	
	$templatelist .= ',inlinesuccess_success';
	
	// hell yeah, we've got a message to show!
	if ($_SESSION['inlinesuccess']) {
		$messagelist = $_SESSION['inlinesuccess']['message'];
		eval("\$inlinesuccess = \"" . $templates->get("inlinesuccess_success") . "\";");
		// aaaand we're done here
		unset($_SESSION['inlinesuccess']);
	}
}

// Loads our lang variables into usercp
function inlinesuccess_usercp_start()
{
	global $lang;
	
	$lang->load("inlinesuccess");
}

// Advanced find and replace function for multiple templates at once using regular expressions (POSIX in MySQL(i), PCRE in PHP, automatically handled)
function find_replace_multitemplatesets($find, $replace)
{
	global $db, $mybb;
	
	$return = false;
	
	// Select all templates
	$query = $db->simple_select("templates", "tid, sid, template, title", "template REGEXP '" . preg_quote($find) . "'");
	while ($template = $db->fetch_array($query)) {
		// replace the content
		$new_template = preg_replace("#" . $find . "#i", $replace, $template['template']);
		if ($new_template == $template['template']) {
			continue;
		}
		
		// The template is a custom template. Replace as normal.
		$updated_template = array(
			"template" => $db->escape_string($new_template)
		);
		
		$db->update_query("templates", $updated_template, "tid='{$template['tid']}'");
		
		$return = true;
	}
	
	return $return;
}
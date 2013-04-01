<?php if (!defined('APPLICATION')) exit();

/**
 * Cloudflare Support Plugin
 *
 * This plugin inspect the incoming request headers and applies CF-Connecting-IP
 * to the request object.
 *
 * Changes:
 *	1.0		Initial release
 *	1.2		Fix bad method call
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @author Diego Zanella <diego@pathtoenlightenment.net>
 * @copyright 2003 Vanilla Forums, Inc
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPL
 * @package Addons
 */

require('ip_in_range.php');

// Define the plugin:
$PluginInfo['CloudflareSupport'] = array(
	'Description' => 'This plugin modifies the Request object to work with Cloudflare.',
	'Version' => '1.4',
	'RequiredApplications' => array('Vanilla' => '2.0'),
	'RequiredTheme' => FALSE,
	'RequiredPlugins' => FALSE,
	'HasLocale' => FALSE,
	'SettingsUrl' => FALSE,
	'SettingsPermission' => 'Garden.Settings.Manage',
	'Author' => "Tim Gunter",
	'AuthorEmail' => 'tim@vanillaforums.com',
	'AuthorUrl' => 'http://www.vanillaforums.com'
);

/**
 * Adds CloudFlare Support to Vanilla.
 */
class CloudflareSupportPlugin extends Gdn_Plugin {
	// @var string The URL where the updated list of CloudFlare IPs can be found
	const DEFAULT_CF_IPLIST_URL = 'https://www.cloudflare.com/ips-v4';

	/* @var array CloudFlare IP ranges to use as a default when an updated list
	* cannot be retrieved.
	* @link https://www.cloudflare.com/ips
	*/
	protected $CloudFlareDefaultSourceIPs = array(
		'204.93.240.0/24',
		'204.93.177.0/24',
		'199.27.128.0/21',
		'173.245.48.0/20',
		'103.22.200.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20');

	/**
	 * Retrieves the updated list of CloudFlare IP Addresses.
	 *
	 * @return array An array of IP addresses used by CloudFlare.
	 */
	private function GetCloudFlareIPs() {
		$CloudFlareIPListURL = C('Plugin.CloudFlareSupport.IPListURL', DEFAULT_CF_IPLIST_URL);

		$CloudFlareIPList = file_get_contents($CloudFlareIPListURL);
		if($CloudFlareIPList === false) {
			return $this->CloudFlareDefaultSourceIPs;
		}

		$Result = explode("\n", $CloudFlareIPList);
		// Save the updated IP Address list to configuration
		SaveToConfig('Plugin.CloudFlareSupport.IPListURL', $Result);

		//var_dump($Result);die();
		return $Result;
	}

	/**
	 * Class constructor.
	 *
	 * @return CloudflareSupportPlugin
	 */
	public function __construct() {
		parent::__construct();

		$CloudflareClientIP = GetValue('HTTP_CF_CONNECTING_IP', $_SERVER, NULL);

		// If cloudflare isn't telling us a client IP, no processing is required
		if(!empty($CloudflareClientIP)) {
			$RemoteAddress = Gdn::Request()->RemoteAddress();

			$CloudflareRequest = FALSE;
			foreach ($this->GetCloudFlareIPs() as $CloudflareIPRange) {
				// Not a cloudflare origin server
				if (!ip_in_range($RemoteAddress, $CloudflareIPRange)) {
					continue;
				}

				Gdn::Request()->RequestAddress($CloudflareClientIP);
				$CloudflareRequest = TRUE;
				break;
			}

			// Let people know that the CF plugin is turned on.
			if ($CloudflareRequest && !headers_sent()) {
				header("X-CF-Powered-By: CF-Vanilla v" . $this->GetPluginKey('Version'));
			}
		}
	}

	/**
	* Performs the initial Setup when the plugin is enabled.
	*/
	public function Setup() {
		// Set Plugin's default settings
		SaveToConfig('Plugin.CloudFlareSupport.SourceIPs', $CloudflareDefaultSourceIPs);
		SaveToConfig('Plugin.CloudFlareSupport.IPListURL', DEFAULT_CF_IPLIST_URL);
	}

	/**
	* Performs a cleanup when the plugin is removed.
	*/
	public function Cleanup() {
		// Remove Plugin's settings
		RemoveFromConfig('Plugin.CloudFlareSupport.SourceIPs');
		RemoveFromConfig('Plugin.CloudFlareSupport.IPListURL');
	}
}

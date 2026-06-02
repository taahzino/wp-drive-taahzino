<?php
namespace WPDrive;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin bootstrap. Singleton — call Plugin::get_instance().
 */
class Plugin {

	private static ?Plugin $instance = null;

	public OAuth       $oauth;
	public FileManager $file_manager;
	public DriveAPI    $drive_api;
	public RestAPI     $rest_api;
	public Admin       $admin;

	private function __construct() {
		$this->oauth        = new OAuth();
		$this->file_manager = new FileManager();
		$this->drive_api    = new DriveAPI( $this->oauth );
		$this->rest_api     = new RestAPI( $this->oauth, $this->drive_api, $this->file_manager );
		$this->admin        = new Admin( $this->oauth );
	}

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
}

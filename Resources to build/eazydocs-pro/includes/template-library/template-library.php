<?php
namespace eazyDocsPro\Template_library;


class Template_library {


	public function __construct() {
		$this->core_includes();
	}

	public function core_includes() {

		// promax only
		if (!eaz_fs()->is_plan('promax')) {
			return;
		}

		// templates
		include( __DIR__ . '/templates/import.php');
		include( __DIR__ . '/templates/init.php');
		include( __DIR__ . '/templates/load.php');
		include( __DIR__ . '/templates/api.php');

		\eazyDocsPro\Templates\Import::instance()->load();
		\eazyDocsPro\Templates\Load::instance()->load();
		\eazyDocsPro\Templates\Templates::instance()->init();

		if (!defined('TEMPLATE_LOGO_SRC')){
			define('TEMPLATE_LOGO_SRC', plugin_dir_url( __FILE__ ) . 'templates/assets/img/template_logo.png');
		}

	}

}


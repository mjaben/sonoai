<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Antimanual\Api\AutoPostingController;
use Antimanual\Api\AutoUpdateController;
use Antimanual\Api\ChatbotController;
use Antimanual\Api\ContentController;
use Antimanual\Api\DocsController;
use Antimanual\Api\FAQController;
use Antimanual\Api\KnowledgeBaseController;
use Antimanual\Api\PostGeneratorController;
use Antimanual\Api\PreferencesController;
use Antimanual\Api\RepurposeStudioController;
use Antimanual\Api\UsageController;
use Antimanual\Api\ValidationController;
use Antimanual\Api\SeoAgentController;
use Antimanual\Api\EmailMarketingController;
use Antimanual\Api\SystemInfoController;

/**
 * API Router
 *
 * Delegates REST API endpoints to feature-specific controllers.
 *
 * @package Antimanual
 */
class API {
    private static $instance  = null;
    private static $namespace = 'antimanual/v1';
    private static $url       = '';

    private $chatbot;
    private $preferences;
    private $knowledge_base;
    private $auto_posting;
    private $auto_update;
    private $content;
    private $docs;
    private $usage;
    private $validation;
    private $post_generator;
    private $faq;
    private $repurpose_studio;
    private $seo_agent;
    private $email_marketing;
    private $system_info;

    private function __construct() {
        $this->chatbot        = new ChatbotController();
        $this->preferences    = new PreferencesController();
        $this->knowledge_base = new KnowledgeBaseController();
        $this->auto_posting   = new AutoPostingController();
        
        // Always initialize Auto Update as a fallback.
        // The Pro plugin may override these routes with its own implementation.
        $this->auto_update = new AutoUpdateController();
        
        $this->content        = new ContentController();
        $this->docs           = new DocsController();
        $this->usage          = new UsageController();
        $this->validation     = new ValidationController();
        $this->post_generator = new PostGeneratorController();
        $this->faq            = new FAQController();
        $this->repurpose_studio   = new RepurposeStudioController();
        $this->seo_agent          = new SeoAgentController();
        $this->email_marketing    = new EmailMarketingController();
        $this->system_info        = new SystemInfoController();

        add_action( 'rest_api_init', [ $this, 'rest_api_init' ] );
    }

    /**
     * Get the singleton instance.
     *
     * @return API The singleton instance.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize REST API routes.
     */
    public function rest_api_init() {
        self::url();
        $this->register_routes();
    }

    /**
     * Get the API URL base.
     *
     * @return string The API URL.
     */
    public static function url() {
        if ( empty( self::$url ) ) {
            self::$url = get_rest_url( null, self::$namespace );
        }

        return self::$url;
    }

    /**
     * Register all REST API routes.
     */
    public function register_routes() {
        register_rest_route( self::$namespace, '/cronjob', [
            'methods'             => 'GET',
            'callback'            => 'spawn_cron',
            'permission_callback' => fn() => current_user_can( 'manage_options' ),
            'timeout'             => 120,
        ] );

        $this->chatbot->register_routes( self::$namespace );
        $this->preferences->register_routes( self::$namespace );
        $this->knowledge_base->register_routes( self::$namespace );
        $this->auto_posting->register_routes( self::$namespace );
        
        $this->auto_update->register_routes( self::$namespace );
        
        $this->content->register_routes( self::$namespace );
        $this->docs->register_routes( self::$namespace );
        $this->usage->register_routes( self::$namespace );
        $this->validation->register_routes( self::$namespace );
        $this->post_generator->register_routes( self::$namespace );
        $this->faq->register_routes( self::$namespace );
        $this->repurpose_studio->register_routes( self::$namespace );
        $this->seo_agent->register_routes( self::$namespace );
        $this->email_marketing->register_routes( self::$namespace );
        $this->system_info->register_routes( self::$namespace );
        // Internal Linking routes are registered by Antimanual Pro plugin
        // GitHub routes are registered by Antimanual Pro plugin
    }
}

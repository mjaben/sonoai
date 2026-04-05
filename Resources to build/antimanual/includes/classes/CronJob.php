<?php

namespace Antimanual;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cron Job Manager (Legacy).
 *
 * Handles cleanup of legacy cron jobs.
 *
 * @package Antimanual
 */
class CronJob {
    private static $instance  = null;
    public  static $hook      = 'atml_cron_job';

    public function __construct() {
        // No longer registering custom schedules or adding filters
        add_action( 'init', [ $this, 'init' ] );
    }

    /**
     * Get the singleton instance.
     *
     * @return CronJob The singleton instance.
     */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialize cron cleanup.
     */
    public function init() {
        // CLEANUP: Remove legacy cron job if it exists
        $timestamp = wp_next_scheduled( self::$hook );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::$hook );
        }
    }

    /**
     * @deprecated Use standard add_action instead. This class is deprecated.
     */
    public static function add( callable $callback ) {
        add_action( self::$hook, $callback );
    }
}

<?php

/**  */

namespace PerformanceMonitor;

use DateTimeZone;
use Exception;

/**
 * The admin settings page of the plugin.
 *
 *
 * @link       https://github.com/OllieJones
 * @package    Performance_Monitor
 * @subpackage Performance_Monitor/admin
 * @author     Ollie Jones <oj@plumislandmedia.net>
 */
class Admin
  extends WordPressHooks {

  private $plugin_name;
  private $options_name;
  private $version;
  private $indexer;
  private $pluginPath;
  /** @var bool Sometimes sanitize() gets called twice. Avoid repeating operations. */
  private $didAnyOperations = false;

  /**
   * Initialize the class and set its properties.
   *
   */
  public function __construct() {

    $this->plugin_name  = PERFORMANCE_MONITOR_NAME;
    $this->version      = PERFORMANCE_MONITOR_VERSION;
    $this->pluginPath   = plugin_dir_path( dirname( __FILE__ ) );
    $this->options_name = PERFORMANCE_MONITOR_PREFIX . 'options';

    /* action link for plugins page */
    add_filter( 'plugin_action_links_' . PERFORMANCE_MONITOR_FILENAME, [ $this, 'action_link' ] );

    parent::__construct();
  }

  /** @noinspection PhpUnused */
  public function action__admin_menu() {

	  //$indexer = Indexer::getInstance();
	  //$indexer->hackHackHack();

	  add_submenu_page(
		  'tools.php',
		  esc_html__( 'Performance Monitor', 'performance-monitor' ),
		  esc_html__( 'Performance Monitor', 'performance-monitor' ),
		  'manage_options',
		  $this->plugin_name,
		  [ $this, 'render_admin_page' ],
		  15 );

	  //$this->addTimingSection();
  }

  private function addTimingSection() {

    $sectionId = 'timing';
    $page      = $this->plugin_name;
    add_settings_section( $sectionId,
      esc_html__( 'Rebuilding user indexes', 'performance-monitor' ),
      [ $this, 'render_timing_section' ],
      $page );

    add_settings_field( 'auto_rebuild',
      esc_html__( 'Rebuild indexes', 'performance-monitor' ),
      [ $this, 'render_auto_rebuild_field' ],

      $page,
      $sectionId );

    add_settings_field( 'rebuild_time',
      esc_html__( '...at this time', 'performance-monitor' ),
      [ $this, 'render_rebuild_time_field' ],
      $page,
      $sectionId );

    $option = get_option( $this->options_name );

    /* make sure default option is in place, to avoid double sanitize call */
    if ( $option === false ) {
      add_option( $this->options_name, [
        'auto_rebuild' => 'on',
        'rebuild_time' => '00:25',
      ] );
    }

    register_setting(
      $this->options_name,
      $this->options_name,
      [ 'sanitize_callback' => [ $this, 'sanitize_settings' ] ] );
  }

  public function render_empty() {
    echo '';
  }

  /** @noinspection PhpRedundantOptionalArgumentInspection
   * @noinspection PhpIncludeInspection
   */
  public function sanitize_settings( $input ) {

    require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/indexer.php';
    $this->indexer = Indexer::getInstance();

    $didAnOperation = false;

    try {
      $autoRebuild = isset( $input['auto_rebuild'] ) && ( $input['auto_rebuild'] === 'on' || $input['auto_rebuild'] === 'nowon' );
      $nowRebuild  = isset( $input['auto_rebuild'] ) && ( $input['auto_rebuild'] === 'nowoff' || $input['auto_rebuild'] === 'nowon' );
      $time        = isset( $input['rebuild_time'] ) ? $input['rebuild_time'] : '';
      $timeString  = $this->formatTime( $time );

      if ( $timeString === false ) {
        add_settings_error(
          $this->options_name, 'rebuild',
          esc_html__( 'Incorrect time.', 'performance-monitor' ),
          'error' );

        return $input;
      }

      if ( $nowRebuild ) {
        add_settings_error(
          $this->options_name, 'rebuild',
          esc_html__( 'Index rebuilding process starting', 'performance-monitor' ),
          'info' );
        if ( ! $this->didAnyOperations ) {
          $didAnOperation = true;
          $this->indexer->rebuildNow();
        }
      }

      if ( $autoRebuild ) {
        /* translators: 1: localized time like 1:22 PM or 13:22 */
        $format  = __( 'Automatic index rebuilding scheduled for %1$s each day', 'performance-monitor' );
        $display = esc_html( sprintf( $format, $timeString ) );
        add_settings_error( $this->options_name, 'rebuild', $display, 'success' );
        if ( ! $this->didAnyOperations ) {
          $didAnOperation = true;
          $this->indexer->enableAutoRebuild( $this->timeToSeconds( $time ) );
        }

      } else {
        $display = esc_html__( 'Automatic index rebuilding disabled', 'performance-monitor' );
        add_settings_error( $this->options_name, 'rebuild', $display, 'success' );
        if ( ! $this->didAnyOperations ) {
          $didAnOperation = true;
          $this->indexer->disableAutoRebuild();
        }
      }
    } catch ( Exception $ex ) {
      add_settings_error( $this->options_name, 'rebuild', esc_html( $ex->getMessage() ), 'error' );
    }
    if ( $didAnOperation ) {
      $this->didAnyOperations = true;
    }

    /* persist only on and off */
    if( isset( $input['auto_rebuild'] )) {
      $i = $input['auto_rebuild'];
      $i = $i === 'nowon' ? 'on' : $i;
      $i = $i === 'nowoff' ? 'off' : $i;
      $input['auto_rebuild'] = $i;
    }
    return $input;
  }

  /**
   * @param string $time like '16:42'
   *
   * @return string|false  time string or false if input was bogus.
   */
  private function formatTime( $time ) {
    $ts  = $this->timeToSeconds( $time );
    $utc = new DateTimeZone ( 'UTC' );

    return $ts === false ? $time : wp_date( get_option( 'time_format' ), $ts, $utc );
  }

  /**
   * @param string $time like '16:42'
   *
   * @return false|int
   */
  private function timeToSeconds( $time ) {
    try {
      if ( preg_match( '/^\d\d:\d\d$/', $time ) ) {
        $ts = intval( substr( $time, 0, 2 ) ) * HOUR_IN_SECONDS;
        $ts += intval( substr( $time, 3, 2 ) ) * MINUTE_IN_SECONDS;
        if ( $ts >= 0 && $ts < DAY_IN_SECONDS ) {
          return intval( $ts );
        }
      }
    } catch ( Exception $ex ) {
      return false;
    }

    return false;
  }

  /** @noinspection PhpIncludeInspection */
  public function render_admin_page() {
    include_once $this->pluginPath . 'admin/views/page.php';
  }

  public function render_timing_section() {
    ?>
      <p>
        <?php esc_html_e( 'You may rebuild your user indexes each day, or immediately.', 'performance-monitor' ) ?>
        <?php esc_html_e( '(It is possible for them to become out-of-date.)', 'performance-monitor' ) ?>
      </p>
    <?php
  }

  public function render_auto_rebuild_field() {
    $options     = get_option( $this->options_name );
    $autoRebuild = isset( $options['auto_rebuild'] ) ? $options['auto_rebuild'] : 'on';
    ?>
      <div>
          <span class="radioitem">
              <input type="radio"
                     id="auto_rebuild_yes"
                     name="<?php echo esc_attr( $this->options_name ) ?>[auto_rebuild]"
                     value="on"
                     <?php checked( $autoRebuild, 'on' ) ?> />
                <label for="auto_rebuild_yes"><?php esc_html_e( 'daily', 'performance-monitor' ) ?></label>
          </span>
          <span class="radioitem">
              <input type="radio"
                     id="auto_rebuild_no"
                     name="<?php echo esc_attr( $this->options_name ) ?>[auto_rebuild]"
                     value="off"
                     <?php checked( $autoRebuild, 'off' ) ?> />
                <label for="auto_rebuild_no"><?php esc_html_e( 'never', 'performance-monitor' ) ?></label>
          </span>
          <span class="radioitem">
              <input type="radio"
                     id="auto_rebuild_now_daily"
                     name="<?php echo esc_attr( $this->options_name ) ?>[auto_rebuild]"
                     value="nowon"
                     <?php checked( $autoRebuild, 'nowon' ) ?> />
                <label for="auto_rebuild_now_daily"><?php esc_html_e( 'immediately, then daily', 'performance-monitor' ) ?></label>
          </span>
          <span class="radioitem">
              <input type="radio"
                     id="auto_rebuild_now_only"
                     name="<?php echo esc_attr( $this->options_name ) ?>[auto_rebuild]"
                     value="nowoff"
                     <?php checked( $autoRebuild, 'nowoff' ) ?> />
                <label for="auto_rebuild_now_only"><?php esc_html_e( 'immediately, but not daily', 'performance-monitor' ) ?></label>
          </span>
      </div>
    <?php
  }

  public function render_rebuild_time_field() {
    $options     = get_option( $this->options_name );
    $rebuildTime = isset( $options['rebuild_time'] ) ? $options['rebuild_time'] : '00:25';
    ?>
      <div>
          <!--suppress HtmlFormInputWithoutLabel -->
          <input type="time"
                 id="rebuild_time"
                 name="<?php echo esc_attr( $this->options_name ) ?>[rebuild_time]"
                 value="<?php echo esc_attr( $rebuildTime ) ?>">
      </div>
      <p>
        <?php esc_html_e( 'Avoid rebuilding exactly on the hour to avoid contending with other processing jobs.' ) ?>
      </p>
    <?php
  }

  public function render_now_rebuild_field() {
    ?>
      <div>
          <!--suppress HtmlFormInputWithoutLabel -->
          <input type="checkbox"
                 id="rebuild_now"
                 name="<?php echo esc_attr( $this->options_name ) ?>[now_rebuild]">
      </div>
    <?php
  }

  public function render_now_remove_field() {
    ?>
      <div>
          <!--suppress HtmlFormInputWithoutLabel -->
          <input type="checkbox"
                 id="rebuild_now"
                 name="<?php echo esc_attr( $this->options_name ) ?>[now_remove]">
      </div>
    <?php
  }

  /**
   * Register the stylesheets for the admin area.
   *
   * @noinspection PhpUnused
   * @noinspection PhpRedundantOptionalArgumentInspection
   */
  public function action__admin_enqueue_scripts() {
    wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/admin.css', [], $this->version, 'all' );
  }

  /**
   * Filters the list of action links displayed for a specific plugin in the Plugins list table.
   *
   * The dynamic portion of the hook name, `$plugin_file`, refers to the path
   * to the plugin file, relative to the plugins directory.
   *
   * @param string[] $actions An array of plugin action links. By default, this can include
   *                              'activate', 'deactivate', and 'delete'. With Multisite active
   *                              this can also include 'network_active' and 'network_only' items.
   * @param string $plugin_file Path to the plugin file relative to the plugins directory.
   * @param array $plugin_data An array of plugin data. See `get_plugin_data()`
   *                              and the {@see 'plugin_row_meta'} filter for the list
   *                              of possible values.
   * @param string $context The plugin context. By default this can include 'all',
   *                              'active', 'inactive', 'recently_activated', 'upgrade',
   *                              'mustuse', 'dropins', and 'search'.
   *
   * @since 2.7.0
   * @since 4.9.0 The 'Edit' link was removed from the list of action links.
   *
   * @noinspection PhpDocSignatureInspection
   * @noinspection GrazieInspection
   */
  public function action_link( $actions ) {
    $mylinks = [
      '<a href="' . admin_url( 'users.php?page=' . $this->plugin_name ) . '">' . __( 'Settings' ) . '</a>',
    ];

    return array_merge( $mylinks, $actions );
  }


}

new Admin();
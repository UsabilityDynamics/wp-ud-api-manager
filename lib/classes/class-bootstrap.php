<?php
/**
 * Bootstrap
 *
 * @since 1.0.0
 */
namespace UsabilityDynamics\API_Manager {

  if( !class_exists( 'UsabilityDynamics\API_Manager\Bootstrap' ) ) {

    final class Bootstrap extends \UsabilityDynamics\WP\Bootstrap_Plugin {
      
      /**
       * Singleton Instance Reference.
       *
       * @protected
       * @static
       * @property $instance
       * @type UsabilityDynamics\API_Manager\Bootstrap object
       */
      protected static $instance = null;
      
      /**
       * Instantaite class.
       */
      public function init() {
        if( !function_exists( 'WCAM' ) ) {
          $this->errors->add( __( 'It requires WooCommerce API Manager plugin. Be sure it\'s installed and activated.', $this->domain ), 'error' );
          return false;
        }
        
        /**
         * Redeclare Upgrade API
         */
        if( has_action( 'woocommerce_api_upgrade-api', array( WCAM(), 'handle_upgrade_api_request' ) ) ) {
          remove_action( 'woocommerce_api_upgrade-api', array( WCAM(), 'handle_upgrade_api_request' ) );
        }
        add_action( 'woocommerce_api_upgrade-api', array( $this, 'handle_upgrade_api_request' ) );
        
        /**
         * Redeclare Software API
         */
        if( has_action( 'woocommerce_api_am-software-api', array( WCAM(), 'handle_software_api_request' ) ) ) {
          remove_action( 'woocommerce_api_am-software-api', array( WCAM(), 'handle_software_api_request' ) );
        }
        add_action( 'woocommerce_api_am-software-api', array( $this, 'handle_software_api_request' ) );
        
        //* order_complete function saves user_meta data to be used by the email template and the API Manager */
        add_action( 'woocommerce_order_status_completed', array( $this, 'order_complete' ), 100 );
        
        /** 
         * Rebuilds licenses keys data stored in user meta.
         *
         * Called on: 
         * - WooCommerce API Manager ( WAM ) activation
         * - after WAM updated.
         * - Rebuild API Manager Data ( API Manager Settings page )
         */
        register_activation_hook( WAM_PLUGIN_FILE, array( $this, 'rebuild_licenses_relations' ) );
        add_action( 'woocommerce_api_manager_updated', array( $this, 'rebuild_licenses_relations' ), 100 );
        add_action( 'woocommerce_settings_tabs_api_manager', array( $this, 'api_manager_settings_page' ), 100 );
        
        Schema::init();
        
      }
      
      /**
       * Processes all Software Update Requests
       *
       * @return UD_Update_API
       */
      public function handle_upgrade_api_request() {
        return UD_Update_API::instance( $_REQUEST );
      }
      
      /**
       * Processes all Software Activation/Deactivation Requests
       *
       * @return UD_Software_API
       */
      public function handle_software_api_request() {
        return UD_Software_API::instance( $_REQUEST );
      }
      
      /**
       *
       */
      public function api_manager_settings_page() {
        if ( ! empty( $_GET['action'] ) && ! empty( $_REQUEST['_wpnonce'] ) && wp_verify_nonce( $_REQUEST['_wpnonce'], 'debug_action' ) ) {
          switch ( $_GET['action'] ) {
            case "rebuild_api_data" :
              $this->rebuild_licenses_relations();
            break;
          }
        }
      }
      
      /**
       * Rebuilds licenses keys data stored in user meta.
       */
      public function rebuild_licenses_relations() {
        global $wpdb;
        //** Populates an array containing the ID of every user */
        $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->prefix}users" );
        //** Remove all wc_ud_order data and add it again */
        if ( ! empty( $ids ) ) {
          foreach ( $ids as $user_id ) {
            delete_user_meta( $user_id, $wpdb->get_blog_prefix() . 'wc_ud_order' );
            $user_orders = WCAM()->helpers->get_users_data( $user_id );
            if( !empty( $user_orders ) && is_array( $user_orders ) ) {
              foreach( $user_orders as $license_key => $data ) {
                add_user_meta( $user_id, $wpdb->get_blog_prefix() . 'wc_ud_order', $license_key );
              }
            }
          }
        }
      }
      
      /**
       * Update user meta data
       * Save license keys in user meta separately to be able to find user by license key easily
       *
       */
      public function order_complete( $order_id ) {
        global $wpdb;
        //** WooCommerce 2.2 compatibility */
        //** https://github.com/woothemes/woocommerce/pull/5558 */
        if ( function_exists( 'wc_get_order' ) ) {
          $order = wc_get_order( $order_id );
        } else {
          $order = new WC_Order( $order_id );
        }
        $user_orders = WCAM()->helpers->get_users_data( $order->user_id );
        delete_user_meta( $order->user_id, $wpdb->get_blog_prefix() . 'wc_ud_order' );
        if( !empty( $user_orders ) && is_array( $user_orders ) ) {
          foreach( $user_orders as $license_key => $data ) {
            add_user_meta( $order->user_id, $wpdb->get_blog_prefix() . 'wc_ud_order', $license_key );
          }
        }
      }
      
      /**
       * Plugin Activation
       *
       */
      public function activate() {}
      
      /**
       * Plugin Deactivation
       *
       */
      public function deactivate() {}

    }

  }

}

<?php
/**
 * UsabilityDynamics API Manager Software API Class
 *
 * @since 1.0.0
 */
namespace UsabilityDynamics\API_Manager {

  if( !class_exists( 'UsabilityDynamics\API_Manager\UD_Software_API' ) ) {

    /**
     * WooCommerce API Manager Software API Class
     *
     */

    class UD_Software_API {

      /**
       * @var The single instance of the class
       */
      protected static $_instance = null;

      /**
       *
       * @static
       * @return class instance
       */
      public static function instance( $request ) {

        if ( is_null( self::$_instance ) && ! is_object( self::$_instance ) ) {
          self::$_instance = new self( $request );
        }

        return self::$_instance;
      }

      /**
       * Cloning is forbidden.
       *
       * @since 1.3.3
       */
      private function __clone() {}

      /**
       * Unserializing instances of this class is forbidden.
       *
       * @since 1.3.3
       */
      private function __wakeup() {}

      private 	$request = array();
      private 	$debug;

      public function __construct( $request, $debug = false ) {
      
        //$this->debug = ( WP_DEBUG ) ? true : $debug; // always on if WP_DEBUG is on
        $this->debug = true;
        
        if ( isset( $request['request'] ) ) {

          $this->request = $request;

        } else {

          $this->error( '100', __( 'Invalid API Request', 'woocommerce-api-manager' ) );

        }

        // Let's get started
        if ( $this->request['request'] == 'activation' ) {

          $this->activation_request();

        } else if ( $this->request['request'] == 'deactivation' ) {

          $this->deactivation_request();

        } else if ( $this->request['request'] == 'status' ) {

          $this->status_request();

        } else {

          $this->error( '100', __( 'Invalid API Request', 'woocommerce-api-manager' ) );

        }

      }

      /**
       * activation_request Handles API key activation requests
       * @return array JSON
       */
      private function activation_request() {

        $this->check_required( array( 'licence_key', 'product_id', 'instance' ) );

        $input = $this->check_input( array( 'email', 'licence_key', 'product_id', 'platform', 'instance', 'software_version' ) );

        if ( empty( $input['licence_key'] ) ) {
          $this->error( '105', null, null, array( 'activated' => false ) );
        }

        if ( empty( $input['product_id'] ) ) {
          $this->error( '100', __( 'The Product ID was empty. Activation error', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );
        }

        if ( empty( $input['instance'] ) ) {
          $this->error( '104', null, null, array( 'activated' => false ) );
        }

        // Get the user order info
        $data = Helper::get_order_info_by_order_key( $input['licence_key'], $input['email'] );

        if ( empty( $data ) || $data === false ) {
          $this->error( '101', __( 'No matching API license key exists. Activation error', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );
        }
        
        //echo "<pre>"; print_r( $data ); echo "</pre>"; die();

        // Validate order if set
        if ( $data['order_id'] ) {

          // WC 2.1
          $order_status = wp_get_post_terms( $data['order_id'], 'shop_order_status' );

          if ( is_wp_error( $order_status ) ) {
            // WC 2.2
            $wc_order_status 	= get_post( $data['order_id'] );
            $order_status 		= $wc_order_status->post_status;
            $order_status 		= ( $order_status == 'wc-completed' ) ? true : false;
          } else {
            // WC 2.1
            $order_status = $order_status[0]->slug;
            $order_status = ( $order_status == 'completed' ) ? true : false;
          }

          // wc-completed for 2.2 compatibility
          if ( ! $order_status ) {
            $this->error( '102', __( 'Activation error. The order matching this product has not been completed.', 'woocommerce-api-manager' ), null,  array( 'activated' => false ) );
          }
        }

        // Get the order_key portion of the API License Key
        $order_key_prefix = WCAM()->helpers->get_uniqid_prefix( $input['licence_key'], '_am_' );

        // Check if this is an order_key, or the new longer api_key
        $order_data_key = ( ! empty( $order_key_prefix ) ) ? $order_key_prefix : $input['licence_key'];

        // Confirm this customer has download permission for this product
        $download_count = WCAM()->helpers->get_download_count( $data['order_id'], $order_data_key );

        if ( $download_count === false ) {

          $this->error( '102', __( 'Activation error. There is no download permission for this product purchase.', 'woocommerce-api-manager' ), null,  array( 'activated' => false ) );

        }

        /**
         * Prevent trial subscription orders from activating
         */
        $sub_status = WCAM()->helpers->get_subscription_status_data( $data['order_id'] );

        // Get the post_meta order data
        $post_meta = WCAM()->helpers->get_postmeta_data( $data['order_id'] );

        /**
         * Renewal subscription orders should not have an API License Key.
         * If a subscription API license key exists on a renewal subscription order, don't allow it to activate.
         */
        if ( $sub_status != 'active' && ! empty( $post_meta['_original_order'] ) ) {
          $this->error( '101', null, null, array( 'activated' => false ) );
        }

        // Finds the post ID (integer) for a product even if it is a variable product
        $post_id = ( empty( $data['variable_product_id'] ) && $data['is_variable_product'] == 'no' ) ? absint( $data['parent_product_id'] ) : absint( $data['variable_product_id'] );

        /**
         * Subscription check
         */
        if ( WCAM()->helpers->is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) ) {

          // Finds order ID that matches the license key. Order ID is the post_id in the post meta table
          $order_id 	= $data['order_id'];

          $user_id 	= $data['user_id'];

          // Finds the product ID, which can only be the parent ID for a product
          $product_id = $data['parent_product_id'];

          // Get the subscription status i.e. active
          $status = WCAM()->helpers->get_subscription_status( $user_id, $post_id, $order_id, $product_id );

          if ( ! empty( $status ) && $status != 'active' ) {

            $this->error( '106', null, null, array( 'activated' => false ) );

          }

        } // End Subscription check

        // Check for expired download permission
        $download_id = WCAM()->helpers->get_download_id( $post_id );

        $downloadable_data = WCAM()->helpers->get_downloadable_data( $data['order_key'], $data['license_email'], $post_id, $download_id );

        $access_expires = $downloadable_data->access_expires;

        if ( $access_expires > 0 && strtotime( $access_expires ) < current_time( 'timestamp' ) ) {

          $this->error( '102', __( 'Download access has expired.', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );

        }

        // Check remaining activations
        $activations_remaining = $this->activations_remaining( $data, $input );

        if ( ! $activations_remaining ) {

          $this->error( '103', __( 'Remaining activations is equal to zero', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );

        }

        // Activation
        $result = $this->activate_licence_key( $data, $input );

        if ( $result === false ) {

          $this->error( '104', __( 'Could not activate API license key', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );

        }

        // Check remaining activations
        $activations_remaining = $this->activations_remaining( $data, $input );

        // Activations limit or 999999999 (unlimited)
        if ( $data['is_variable_product'] == 'no' && $data['_api_activations_parent'] != '' ) {

          $activations_limit = $data['_api_activations_parent'];

        } else if ( $data['is_variable_product'] =='no' && $data['_api_activations_parent'] == '' ) {

          $activations_limit = 0;

        } else if ( $data['is_variable_product'] == 'yes' && $data['_api_activations'] != '' ) {

          $activations_limit = $data['_api_activations'];

        } else if ( $data['is_variable_product'] == 'yes' && $data['_api_activations'] == '' ) {

          $activations_limit = 0;

        }

        if ( NULL == $activations_limit || 0 == $activations_limit || empty( $activations_limit ) ) {

          $activations_limit = 999999999;

        }

        // Activation was successful - return json
        $data['activated'] = true;
        $data['instance'] = $input['instance'];
        $data['message'] = sprintf( __( '%s out of %s activations remaining', 'woocommerce-api-manager' ), $activations_remaining, $activations_limit );
        $data['time'] = time();

        /**
         * post_id of the parent or variable product
         * $data contains the user order information
         */
        $data['activation_extra'] = apply_filters( 'api_manager_extra_software_activation_data', $post_id, $data );

        if ( empty( $data['activation_extra'] ) ) {
          $data['activation_extra'] = '';
        }

        $to_output = array( 'activated', 'instance', 'activation_extra' );
        $to_output['message'] = 'message';
        $to_output['timestamp'] = 'time';

        $json = $this->prepare_output( $to_output, $data );

        if ( ! isset( $json ) ) {
          $this->error( '100', __( 'Invalid API Request', 'woocommerce-api-manager' ) );
        }

        wp_send_json( $json );

      }

      /**
       * activate_licence_key Activates an activation for an order_key/license key
       *
       * Activations are contained in numerically indexed arrays that each contain identifying informaiton like
       * order_key, instance, and domain name, so activations for a specific order_key, or domain name
       * can be easily located in the database.
       *
       * @param  array $data  user_meta order info
       * @param  array $input info sumitted in $_REQUEST from client application
       * @return bool
       */
      private function activate_licence_key( $data, $input ) {
        global $wpdb;

        if ( ! is_array( $data ) || ! is_array( $input ) ) return false;

        // Get the old order_key or the new API License Key
        $order_data_key = ( ! empty( $data['api_key'] ) ) ? $data['api_key'] : $data['order_key'];

        if ( $input['licence_key'] != $order_data_key ) {

          $this->error( '105', null, null, array( 'activated' => false ) );

        }

        if ( $data['_api_update_permission'] != 'yes' ) {

          $this->error( '102', __( 'This API Key does not have permission to access the Software API', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );

        }

        // Determine the Software Title from the customer order data
        $software_title = ( empty( $data['_api_software_title_var'] ) ) ? $data['_api_software_title_parent'] : $data['_api_software_title_var'];

        if ( empty( $software_title ) ) {

          $software_title = $data['software_title'];

        }

        if ( $software_title != $input['product_id'] ) {

          $this->error( '105', __( 'This API Key belongs to another product', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );

        }

        // Check for existing activations
        $current_info = WCAM()->helpers->get_users_activation_data( $data['user_id'], $data['order_key'] );

        $software_version = ( ! empty( $input['software_version'] ) ) ? $input['software_version'] : '';

        // Information for this new activation
        $activation_info =
            array(
              array(
              'order_key' 		=> $input['licence_key'],
              'instance'			=> $input['instance'],
              'product_id'		=> $input['product_id'],
              'activation_time' 	=> current_time( 'mysql' ),
              'activation_active' => 1,
              'activation_domain' => WCAM()->helpers->esc_url_raw_no_scheme( $input['platform'] ),
              'software_version' 	=> $software_version
              )
              );

          if ( ! empty( $current_info ) ) {
          
          /**
           * Remove previous product activation for the same domain/platform to prevent duplicate activations
           * @since 1.3.3
           */
          foreach ( $current_info as $key => $active_info ) {
            
            // If true then the software has already been activated with this instance ID
            if ( $active_info[ 'instance' ] == $input['instance'] && $active_info[ 'order_key' ] == $input['licence_key'] ) {
              $this->error( '104', __( 'The instance ID for this product is already activated. Go to your My Account dashboard and delete the activation for this product, then try to activate this product again.', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );
            }
          
            $activation_order_key = ( ! empty( $active_info['api_key'] ) ) ? $active_info['api_key'] : $active_info['order_key'];

            $activation_domain_no_scheme 	= WCAM()->helpers->remove_url_prefix( $active_info['activation_domain'] );
            $platform_no_scheme 			= WCAM()->helpers->remove_url_prefix( $input['platform'] );

            if ( $activation_order_key == $input['licence_key'] && $active_info['activation_active'] == 1 && $active_info['product_id'] == $input['product_id'] && $activation_domain_no_scheme == $platform_no_scheme ) {

              // Delete the activation data array
              unset( $current_info[$key] );

              // Re-index the numerical array keys:
              $current_info = array_values( $current_info );

              break;

            }

          } // end foreach

            /**
             * If other activations already exist
             */
          $new_info = array_merge_recursive( $activation_info, $current_info );
          
          update_user_meta( $data['user_id'], $wpdb->get_blog_prefix() . WCAM()->helpers->user_meta_key_activations . $data['order_key'], $new_info );

          if ( get_option( 'woocommerce_api_manager_activation_order_note' ) == 'yes' ) {

            // WooCommerce 2.2 compatibility
            if ( function_exists( 'wc_get_order' ) ) {
              $order = wc_get_order( $data['order_id'] );
            } else {
              $order = new WC_Order( $data['order_id'] );
            }

            $order->add_order_note( sprintf( __( 'API Key %s was <strong>Activated</strong> by %s on %s.', 'woocommerce-api-manager' ), $input['licence_key'], '<a href="' . esc_url_raw( $input['platform'] ) . '" target="_blank">' . WCAM()->helpers->remove_url_prefix( $input['platform'] ) . '</a>', date_i18n( 'M j\, Y \a\t h:i a', strtotime( current_time( 'mysql' ) ) ) ) );

          }

          return true;

        } else { // if this is the first activation for this order_key
        
          // If this is the first activation
          update_user_meta( $data['user_id'], $wpdb->get_blog_prefix() . WCAM()->helpers->user_meta_key_activations . $data['order_key'], $activation_info );

          if ( get_option( 'woocommerce_api_manager_activation_order_note' ) == 'yes' ) {

            // WooCommerce 2.2 compatibility
            if ( function_exists( 'wc_get_order' ) ) {
              $order = wc_get_order( $data['order_id'] );
            } else {
              $order = new WC_Order( $data['order_id'] );
            }

            $order->add_order_note( sprintf( __( 'API Key %s was <strong>Activated</strong> by %s on %s.', 'woocommerce-api-manager' ), $input['licence_key'], '<a href="' . esc_url_raw( $input['platform'] ) . '" target="_blank">' . WCAM()->helpers->remove_url_prefix( $input['platform'] ) . '</a>', date_i18n( 'M j\, Y \a\t h:i a', strtotime( current_time( 'mysql' ) ) ) ) );

          }

          return true;

        }

        return false;

      }

      /**
       * deactivation_request Handles API key deactivation requests
       *
       * @return array JSON
       */
      private function deactivation_request() {

        $this->check_required( array( 'licence_key', 'product_id', 'instance' ) );

        $input = $this->check_input( array( 'email', 'licence_key', 'product_id', 'platform', 'instance' ) );

        // Get the user order info
        $data = Helper::get_order_info_by_order_key( $input['licence_key'], $input['email'] );

        if ( ! $data || $data === false ) {

          $this->error( '101', __( 'Deactivation error. No matching license key exists', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );

        }

        if ( empty( $input['instance'] ) ) {

          $this->error( '104', __( 'Deactivation error. No matching instance exists', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );

        }

        // reset number of activations
        $is_deactivated = $this->deactivate_licence_key( $data, $input );

        if ( $is_deactivated === false ) {

          $this->error( '100', __( 'The Product could not be deactivated. Deactivation error.', 'woocommerce-api-manager' ), null, array( 'deactivated' => false ) );

        }

        // Check remaining activations
        $activations_remaining = $this->activations_remaining( $data, $input );

        // Activations limit or 999999999 (unlimited)
        if ( $data['is_variable_product'] == 'no' && $data['_api_activations_parent'] != '' ) {

          $activations_limit = $data['_api_activations_parent'];

        } else if ( $data['is_variable_product'] =='no' && $data['_api_activations_parent'] == '' ) {

          $activations_limit = 0;

        } else if ( $data['is_variable_product'] == 'yes' && $data['_api_activations'] != '' ) {

          $activations_limit = $data['_api_activations'];

        } else if ( $data['is_variable_product'] == 'yes' && $data['_api_activations'] == '' ) {

          $activations_limit = 0;

        }

        if ( NULL == $activations_limit || 0 == $activations_limit || empty( $activations_limit ) ) {

          $activations_limit = 999999999;

        }

        $data['deactivated'] = true;
        $data['activations_remaining'] = sprintf( __( '%s out of %s activations remaining', 'woocommerce-api-manager' ), $activations_remaining, $activations_limit );
        $data['timestamp'] = time();
        $to_output = array( 'deactivated' );
        $to_output['activations_remaining'] = 'activations_remaining';
        $to_output['timestamp'] = 'timestamp';

        $json = $this->prepare_output( $to_output, $data );

        wp_send_json( $json );

      }

      /**
       * deactivate_licence_key Deactivates an activation for an order_key/license key
       *
       * A deactivation removes the array containing the data for that activation. The numerically indexed parent
       * arrays are then reindexed.
       *
       * @param  array $data  user_meta order info
       * @param  array $input info sumitted in $_REQUEST from client application
       * @return bool
       */
      private function deactivate_licence_key( $data, $input ) {
        global $wpdb;

        if ( ! is_array( $data ) || ! is_array( $input ) ) return false;

        // Get the old order_key or the new API License Key
        $order_data_key = ( ! empty( $data['api_key'] ) ) ? $data['api_key'] : $data['order_key'];

        if ( $input['licence_key'] != $order_data_key ) return false;

        $current_info = WCAM()->helpers->get_users_activation_data( $data['user_id'], $data['order_key'] );

        if ( ! empty( $current_info ) ) {

          foreach ( $current_info as $key => $activation_info ) {

            $activation_order_key = ( ! empty( $activation_info['api_key'] ) ) ? $activation_info['api_key'] : $activation_info['order_key'];

            $activation_domain_no_scheme 	= WCAM()->helpers->remove_url_prefix( $activation_info['activation_domain'] );
            $platform_no_scheme 			= WCAM()->helpers->remove_url_prefix( $input['platform'] );

            if ( $activation_order_key == $input['licence_key'] && $activation_info['activation_active'] == 1 && $activation_info['product_id'] == $input['product_id'] && $activation_domain_no_scheme == $platform_no_scheme && $activation_info['instance'] == $input['instance'] ) {

              // Delete the activation data array
              unset( $current_info[$key] );

              // Re-index the numerical array keys:
              $new_info = array_values( $current_info );

              update_user_meta( $data['user_id'], $wpdb->get_blog_prefix() . WCAM()->helpers->user_meta_key_activations . $data['order_key'], $new_info );

              if ( get_option( 'woocommerce_api_manager_activation_order_note' ) == 'yes' ) {

                // WooCommerce 2.2 compatibility
                if ( function_exists( 'wc_get_order' ) ) {
                  $order = wc_get_order( $data['order_id'] );
                } else {
                  $order = new WC_Order( $data['order_id'] );
                }

                $order->add_order_note( sprintf( __( 'API Key %s was <strong>Deactivated</strong> by %s on %s.', 'woocommerce-api-manager' ), $input['licence_key'], '<a href="' . esc_url_raw( $input['platform'] ) . '" target="_blank">' . WCAM()->helpers->remove_url_prefix( $input['platform'] ) . '</a>', date_i18n( 'M j\, Y \a\t h:i a', strtotime( current_time( 'mysql' ) ) ) ) );

              }

              $re_check_info = WCAM()->helpers->get_users_activation_data( $data['user_id'], $data['order_key'] );

              if ( empty( $re_check_info ) ) {

                delete_user_meta( $data['user_id'], $wpdb->get_blog_prefix() . WCAM()->helpers->user_meta_key_activations . $data['order_key'] );

              }

              return true;

              break; // just to make sure the foreach loop does not continue, even though return should stop it

            }

          } // end foreach

        }

        return false;

      }

      /**
       * Returns the software activation status
       * @since 1.3.2
       * @return array JSON
       */
      private function status_request() {

        $this->check_required( array( 'licence_key', 'product_id', 'instance' ) );

        $input = $this->check_input( array( 'email', 'licence_key', 'product_id', 'platform', 'instance' ) );

        if ( empty( $input['licence_key'] ) ) {
          $this->error( '105', null, null, array( 'activated' => false ) );
        }

        if ( empty( $input['product_id'] ) ) {
          $this->error( '100', __( 'The Product ID was empty. Activation error', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );
        }

        if ( empty( $input['instance'] ) ) {

          $this->error( '104', null, null, array( 'activated' => false ) );

        }

        // Get the user order info
        $data = Helper::get_order_info_by_order_key( $input['licence_key'], $input['email'] );

        if ( empty( $data ) || $data === false ) {
          $this->error( '101', __( 'No matching API license key exists. Activation error', 'woocommerce-api-manager' ), null, array( 'activated' => false ) );
        }

        // Validate order if set
        if ( $data['order_id'] ) {

          // WC 2.1
          $order_status = wp_get_post_terms( $data['order_id'], 'shop_order_status' );

          if ( is_wp_error( $order_status ) ) {
            // WC 2.2
            $wc_order_status 	= get_post( $data['order_id'] );
            $order_status 		= $wc_order_status->post_status;
            $order_status 		= ( $order_status == 'wc-completed' ) ? true : false;
          } else {
            // WC 2.1
            $order_status = $order_status[0]->slug;
            $order_status = ( $order_status == 'completed' ) ? true : false;
          }

          // wc-completed for 2.2 compatibility
          if ( ! $order_status ) {

            $this->error( '102', __( 'Activation error. The order matching this product has not been completed.', 'woocommerce-api-manager' ), null,  array( 'activated' => false ) );

          }
        }

        // Get the old order_key or the new API License Key
        $order_data_key = ( ! empty( $data['api_key'] ) ) ? $data['api_key'] : $data['order_key'];

        if ( $input['licence_key'] != $order_data_key ) {

          $this->error( '105', null, null, array( 'activated' => false ) );

        }

        $current_info = WCAM()->helpers->get_users_activation_data( $data['user_id'], $data['order_key'] );

        if ( ! empty( $current_info ) ) {

          foreach ( $current_info as $key => $activation_info ) {

            $activation_order_key = ( ! empty( $activation_info['api_key'] ) ) ? $activation_info['api_key'] : $activation_info['order_key'];

            $activation_domain_no_scheme 	= WCAM()->helpers->remove_url_prefix( $activation_info['activation_domain'] );
            $platform_no_scheme 			= WCAM()->helpers->remove_url_prefix( $input['platform'] );

            if ( $activation_order_key == $input['licence_key'] && $activation_info['activation_active'] == 1 && $activation_info['product_id'] == $input['product_id'] && $activation_domain_no_scheme == $platform_no_scheme && $activation_info['instance'] == $input['instance'] ) {

              $activation_info['status_check'] = 'active';

              /**
               * $activation_info contains the API License Key activation information
               */
              $activation_info['status_extra'] = apply_filters( 'api_manager_extra_software_status_data', $activation_info );

              if ( empty( $activation_info['status_extra'] ) ) {
                $activation_info['status_extra'] = '';
              }

              $to_output = array( 'status_check', 'status_extra' );

              $json = $this->prepare_output( $to_output, $activation_info );

              if ( ! isset( $json ) ) {

                $this->error( '100', __( 'Invalid API Request', 'woocommerce-api-manager' ) );

                break;

              }

              wp_send_json( $json );

              break;

            }

          } // end foreach

        }

        $activation_info['status_check'] = 'inactive';

        $to_output = array( 'status_check' );

        $json = $this->prepare_output( $to_output, $activation_info );

        if ( ! isset( $json ) ) {

          $this->error( '100', __( 'Invalid API Request', 'woocommerce-api-manager' ) );

        }

        wp_send_json( $json );

      }

      /**
       * error Handles errors sent to the client
       * @param  integer $code          error code
       * @param  [type]  $debug_message placeholder
       * @param  [type]  $secret        placeholder
       * @param  array   $addtl_data    more info
       * @return array                  JSON
       */
      private function error( $code = 100, $debug_message = null, $secret = null, $addtl_data = array() ) {

        switch ( $code ) {
          case '101' :
            $error = array( 'error' => __( 'Invalid API License Key. Login to your My Account page to find a valid API License Key', 'woocommerce-api-manager' ), 'code' => '101' );
            break;
          case '102' :
            $error = array( 'error' => __( 'Software has been deactivated', 'woocommerce-api-manager' ), 'code' => '102' );
            break;
          case '103' :
            $error = array( 'error' => __( 'Exceeded maximum number of activations', 'woocommerce-api-manager' ), 'code' => '103' );
            break;
          case '104' :
            $error = array( 'error' => __( 'Invalid Instance ID', 'woocommerce-api-manager' ), 'code' => '104' );
            break;
          case '105' :
            $error = array( 'error' => __( 'Invalid API License Key', 'woocommerce-api-manager' ), 'code' => '105' );
            break;
          case '106' :
            $error = array( 'error' => __( 'Subscription Is Not Active', 'woocommerce-api-manager' ), 'code' => '106' );
            break;
          default :
            $error = array( 'error' => __( 'Invalid Request', 'woocommerce-api-manager' ), 'code' => '100' );
            break;
        }

        if ( isset( $this->debug ) && $this->debug ) {

          if ( ! isset( $debug_message ) || ! $debug_message ) $debug_message = __( 'No debug information available', 'woocommerce-api-manager' );

          $error['additional info'] = $debug_message;

        }

        if ( isset( $addtl_data['secret'] ) ) {

          $secret = $addtl_data['secret'];

          unset( $addtl_data['secret'] );

        }

        foreach ( $addtl_data as $k => $v ) {

          $error[ $k ] = $v;

        }

        $secret = ( $secret ) ? $secret : 'null';

        $error['timestamp'] = time();

        foreach ( $error as $k => $v ) {

          if ( $v === false ) $v = 'false';

          if ( $v === true ) $v = 'true';

          $sigjoined[] = "$k=$v";

        }

        $sig = implode( '&', $sigjoined );

        $sig = 'secret=' . $secret . '&' . $sig;

        if ( !$this->debug ) $sig = md5( $sig );

        $error['sig'] = $sig;

        $json = $error;

        wp_send_json( $json );

      }

      /**
       *
       */
      private function check_required( $required ) {
        $i = 0;
        $missing = '';
        foreach ( $required as $req ) {
          if ( ! isset( $this->request[ $req ] ) || $req == '' ) {
            $i++;
            if ( $i > 1 ) $missing .= ', ';
            $missing .= $req;
          }
        }
        if ( $missing != '' ) {
          $this->error( '100', __( 'The following required information is missing', 'woocommerce-api-manager' ) . ': ' . $missing, null, array( 'activated' => false ) );
        }
      }

      /**
       *
       */
      private function check_input( $input ) {
        $return = array();
        foreach ( $input as $key ) {
          $return[ $key ] = ( isset( $this->request[ $key ] ) ) ? $this->request[ $key ] : '';
        }
        return $return;
      }

      /**
       *
       */
      private function prepare_output( $to_output = array(), $data = array() ) {

        $secret = ( isset( $data->secret_key ) ) ? $data->secret_key : 'null';

        $sig_array = array( 'secret' => $secret );

        foreach ( $to_output as $k => $v ) {

          if ( isset( $data[ $v ] ) ) {

            if ( is_string( $k ) ) {

              $output[ $k ] = $data[ $v ];

            } else {

              $output[ $v ] = $data[ $v ];

            }
          }
        }

        $sig_out = $output;

        $sig_array = array_merge( $sig_array, $sig_out );

        foreach ( $sig_array as $k => $v ) {

          if ( $v === false ) $v = 'false';

          if ( $v === true ) $v = 'true';

          $sigjoined[] = "$k=$v";
        }

        $sig = implode( '&', $sigjoined );

        $output['sig'] = $sig;

        return $output;
      }

      /**
       * activations_remaining Calculates the number of remaining activations for an order_key/licence_key
       * @param  array $data  user_meta order info
       * @param  array $input info sumitted in $_REQUEST from client application
       * @return int        Number of remaining activations
       */
      private function activations_remaining( $data, $input ) {

        if ( ! is_array( $data ) || ! is_array( $input ) ) return 0;

        // Get the old order_key or the new API License Key
        $order_data_key = ( ! empty( $data['api_key'] ) ) ? $data['api_key'] : $data['order_key'];

        if ( $input['licence_key'] != $order_data_key ) return 0;

        $order_key = $input['licence_key'];

        $current_info = WCAM()->helpers->get_users_activation_data( $data['user_id'], $data['order_key'] );

        if ( ! empty( $current_info ) ) {

          $active_activations = 0;

            foreach ( $current_info as $key => $activations ) {

              if ( $activations['activation_active'] == 1 && $input['licence_key'] == $activations['order_key'] ) {

              $active_activations++;

              }

          }

        } else {

          $active_activations = 0;

        }

        if ( isset( $data ) ) {

          if ( $data['is_variable_product'] == 'no' && $data['_api_activations_parent'] != '' ) {

            $activations_limit = $data['_api_activations_parent'];

          } else if ( $data['is_variable_product'] =='no' && $data['_api_activations_parent'] == '' ) {

            $activations_limit = 0;

          } else if ( $data['is_variable_product'] == 'yes' && $data['_api_activations'] != '' ) {

            $activations_limit = $data['_api_activations'];

          } else if ( $data['is_variable_product'] == 'yes' && $data['_api_activations'] == '' ) {

            $activations_limit = 0;

          }

        }

        if ( NULL == $activations_limit || 0 == $activations_limit || empty( $activations_limit ) ) {
          return 999999999;
        }

        $remaining =  $activations_limit - $active_activations;

        if ( $remaining < 0 ) $remaining = 0;

        return $remaining;
      }


    } // End class



  }

}

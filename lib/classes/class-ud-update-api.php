<?php
/**
 * UsabilityDynamics API Manager Update API Class
 *
 * @since 1.0.2
 */
namespace UsabilityDynamics\API_Manager {

  if( !class_exists( 'UsabilityDynamics\API_Manager\UD_Update_API' ) ) {

    /**
     * WooCommerce API Manager Update API Class
     * Plugin and Theme Update API responding to update requests
     */

    class UD_Update_API {

      /**
       * @var The single instance of the class
       */
      protected static $_instance = null;

      /**
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
       */
      private function __clone() {}

      /**
       * Unserializing instances of this class is forbidden.
       *
       */
      private function __wakeup() {}

      private $request = array();
      private $plugin_name;
      private $version;
      private $product_id;
      private $api_key;
      private $instance;
      private $domain;
      private $software_version;
      private $extra; // Used to send any extra information.

      public function __construct( $request ) {

        /**
         * For example a $request['plugininformation'] might look like:
         * Array
         *	(
         *	    [wc-api] => upgrade-api
         *	    [request] => plugininformation
         *	    [plugin_name] => simple-comments/simple-comments.php
         *	    [version] => 1.9.4
         *	    [product_id] => Simple Comments
         *	    [api_key] => f66226254772
         *	)
         */

        if ( isset( $request['request'] ) ) {
          $this->request 			= $request['request'];
          $this->plugin_name 		= $request['plugin_name']; // same as plugin slug
          $this->version 			= $request['version'];
          $this->product_id 		= $request['product_id'];
          $this->api_key			= $request['api_key'];
          $this->instance			= ( empty( $request['instance'] ) ) ? '' : $request['instance'];
          $this->domain			= ( empty( $request['domain'] ) ) ? '' : $request['domain'];
          $this->software_version = ( empty( $request['software_version'] ) ) ? '' : $request['software_version'];
          $this->extra 			= ( empty( $request['extra'] ) ) ? '' : apply_filters( 'api_manager_extra_update_data', $request['extra'] );

          // Let's get started
          $this->update_check();

        } else {

          $this->send_error_api_data( $this->request, array( 'no_key' => 'no_key' ) );
        }

      }

      /**
       * Checks account information and for dependencies before getting API information.
       *
       * @since  1.0.0
       * @return void
       */
      private function update_check() {

        if ( ! empty( $this->request ) || ! empty( $this->plugin_name ) || ! empty( $this->version ) || ! empty( $this->product_id ) || ! empty( $this->api_key ) ) {

          //** If the remote plugin or theme has nothing entered into the license key and license email fields */
          if ( $this->api_key == '' ) {
            $this->send_error_api_data( $this->request, array( 'no_key' => 'no_key' ) );
          }
          
          //**  Get the user order info */
          $order_info = Helper::get_order_info_by_order_key( $this->api_key );
          $user = get_user_by( 'id', $order_info[ 'user_id' ] );
          
          //echo "<pre>"; print_r( $order_info ); echo "</pre>"; die();

          if ( empty( $order_info ) ) {
            $this->send_error_api_data( $this->request, array( 'no_key' => 'no_key' ) );
          }

          //** Determine the Software Title from the customer order data */
          $software_title = ( empty( $order_info['_api_software_title_var'] ) ) ? $order_info['_api_software_title_parent'] : $order_info['_api_software_title_var'];

          if ( empty( $software_title ) ) {
            $software_title = $order_info['software_title'];
          }

          /**
           * Verify the client Software Title matches the product Software Title
           */
          if ( $software_title != $this->product_id  ) {
            $this->send_error_api_data( $this->request, array( 'no_key' => 'no_key' ) );
          }

          //** Get activation info */
          $current_info = WCAM()->helpers->get_users_activation_data( $user->ID, $order_info['order_key'] );

          //echo "<pre>"; print_r( $current_info ); echo "</pre>"; die();
          
          //** Check if this software has been activated */
          if ( is_array( $current_info ) && ! empty( $current_info ) ) {
            //** If false is returned then the software has not yet been activated and an error is returned */
            if ( WCAM()->array->array_search_multi( $current_info, 'order_key', $this->api_key ) === false ) {
              $this->send_error_api_data( $this->request, array( 'no_activation' => 'no_activation' ) );
            }
            //** If false is returned then the software has not yet been activated and an error is returned */
            if ( ! empty( $this->instance ) && WCAM()->array->array_search_multi( $current_info, 'instance', $this->instance ) === false ) {
              $this->send_error_api_data( $this->request, array( 'no_activation' => 'no_activation' ) );
            }
          } 
          //** Send an error if this software has not been activated */
          else { 
            $this->send_error_api_data( $this->request, array( 'no_activation' => 'no_activation' ) );
          }

          // Finds the post ID (integer) for a product even if it is a variable product
          if ( $order_info['is_variable_product'] == 'no' ) {
            $post_id = $order_info['parent_product_id'];
          } else {
            $post_id = $order_info['variable_product_id'];
          }

          // Finds order ID that matches the license key. Order ID is the post_id in the post meta table
          $order_id 	= $order_info['order_id'];

          // Finds the product ID, which can only be the parent ID for a product
          $product_id = $order_info['parent_product_id'];

          // Check if this is an order_key. Finds the order_key for the product purchased
          $order_key = $order_info['order_key'];

          /**
           * @since 1.3
           * For WC 2.1 and above
           * api_key array key introduced to replace order_key
           */
          $api_key = ( empty( $order_info['api_key'] ) ) ? '' : $order_info['api_key'];

          // Does this order_key have Permission to get updates from the API?
          if ( $order_info['_api_update_permission'] != 'yes' ) {
            $this->send_error_api_data( $this->request, array( 'download_revoked' => 'download_revoked' ) );
          }

          if ( isset( $user ) && isset( $post_id ) && isset( $order_id ) && isset( $product_id ) && isset( $order_key ) ) {

            // Verifies license key exists. Returns true or false.
            if ( $this->api_key == $order_key || $this->api_key == $api_key ) {
              $key_exists = true;
            } else {
              $key_exists = false;
            }

            // Send a renew license key message to the customer
            if ( isset( $key_exists ) && $key_exists === false ) {

              $this->send_error_api_data( $this->request, array( 'exp_license' => 'exp_license' ) );

            // If Subscriptions is active, and if a subscription is required for this product
            } else if ( WCAM()->helpers->is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) && WCAM()->helpers->get_product_checkbox_status( $post_id, '_api_is_subscription' ) === true ) {

              // Get the subscription status i.e. active
              $status = WCAM()->helpers->get_subscription_status( $user->ID, $post_id, $order_id, $product_id );

              // Send an error if no subscription is found and if the API key doesn't match what was sent by the software
              if ( $status === false ) {
              
                if ( $key_exists ) { // Matched the API Key, send the update data
                  
                  // Update the software version for this user order
                  if ( ! empty( $this->software_version ) ) {
                    //$this->update_order_version( $user->ID, $order_info );
                    $this->update_activation_version( $user->ID, $current_info, $api_key, $order_key );
                  }

                  /**
                   * Allows third party plugins to receive any kind of data from client software through the Update API
                   * @since 1.3
                   */
                  if ( ! empty( $this->extra ) ) {
                    do_action( 'api_manager_extra_data', $this->extra );
                  }

                  $this->send_api_data( $this->request, $this->plugin_name, $this->version, $order_id, $this->api_key, $order_info[ 'license_email' ], $post_id, $order_key, $user );

                } else { // No API Key match, send an error

                  $this->send_error_api_data( $this->request, array( 'no_subscription' => 'no_subscription', 'no_key' => 'no_key' ) );

                }

              }

              // Sends update data if subscription is active, otherwise an error message is sent
              if ( $status == 'active' && $status !== false && $key_exists ) {

                // Update the software version for this user order
                if ( ! empty( $this->software_version ) ) {

                  //$this->update_order_version( $user->ID, $order_info );

                  $this->update_activation_version( $user->ID, $current_info, $api_key, $order_key );

                }

                /**
                 * Allows third party plugins to receive any kind of data from client software through the Update API
                 * @since 1.3
                 */
                if ( ! empty( $this->extra ) ) {

                  do_action( 'api_manager_extra_data', $this->extra );

                }

                $this->send_api_data( $this->request, $this->plugin_name, $this->version, $order_id, $this->api_key, $order_info[ 'license_email' ], $post_id, $order_key, $user );

              } else {

                $this->send_error_api_data( $this->request, WCAM()->helpers->check_subscription_status( $status ) );

              }

            // If the API License Key is valid, and a subscription is not required for this product
            } else if ( $key_exists && WCAM()->helpers->get_product_checkbox_status( $post_id, '_api_is_subscription' ) === false ) {

                // Update the software version for this user order
                if ( ! empty( $this->software_version ) ) {
                  //$this->update_order_version( $user->ID, $order_info );
                  $this->update_activation_version( $user->ID, $current_info, $api_key, $order_key );
                }

                /**
                 * Allows third party plugins to receive any kind of data from client software through the Update API
                 * @since 1.3
                 */
                if ( ! empty( $this->extra ) ) {
                  do_action( 'api_manager_extra_data', $this->extra );
                }

                $this->send_api_data( $this->request, $this->plugin_name, $this->version, $order_id, $this->api_key, $order_info[ 'license_email' ], $post_id, $order_key, $user );

            } // end if subscriptions installed

          } // end if isset data variables

        } else {

          $this->send_error_api_data( $this->request, array( 'no_key' => 'no_key' ) );

        } // end check to see if all required values were sent

      }

      /**
       * Plugin and Theme Update API method.
       *
       * @since  1.0.0
       * @param  varies
       * @return object $response
       */
      private function send_api_data( $request, $plugin_name, $version, $order_id, $api_key, $activation_email, $post_id, $order_key, $user ) {

        $download_count_set = WCAM()->helpers->get_download_count( $order_id, $order_key );

        // The download ID is needed for the order specific download URL
        $download_id = WCAM()->helpers->get_download_id( $post_id );

        $downloadable_data = WCAM()->helpers->get_downloadable_data( $order_key, $activation_email, $post_id, $download_id );

        $downloads_remaining 	= $downloadable_data->downloads_remaining;
        $download_count 		= $downloadable_data->download_count;
        $access_expires 		= $downloadable_data->access_expires;

        if ( $downloads_remaining == '0' ) {

          $this->send_error_api_data( $this->request, array( 'download_revoked' => 'download_revoked' ) );

        }

        if ( $access_expires > 0 && strtotime( $access_expires ) < current_time( 'timestamp' ) ) {

          $this->send_error_api_data( $this->request, array( 'download_revoked' => 'download_revoked' ) );

        }

        if ( $download_count_set !== false ) {

          // Get the API data in an array
          $api_data = get_post_custom( $post_id );

          /**
           * Check for Amazon S3 URL
           * @since 1.3.2
           */
          $url = WCAM()->helpers->get_download_url( $post_id );

          if ( ! empty( $url ) && WCAM()->helpers->find_amazon_s3_in_url( $url ) === true ) {

            $download_link = WCAM()->helpers->format_secure_s3_url( $url );

          } else {

            // Build the order specific download URL
            $download_link = WCAM()->helpers->create_url( $order_key, $activation_email, $post_id, $download_id, $user->ID );

          }

          if ( $download_link === false || empty( $download_link ) ) {

            $this->send_error_api_data( $this->request, array( 'download_revoked' => 'download_revoked' ) );

          }

          /**
           * Prepare pages for display in upgrade "View version details" screen
           */
          $desc_obj 		= get_post( $api_data['_api_description'][0] );
          $install_obj 	= get_post( $api_data['_api_installation'][0] );
          $faq_obj 		= get_post( $api_data['_api_faq'][0] );
          $screen_obj 	= get_post( $api_data['_api_screenshots'][0] );
          $change_obj 	= get_post( $api_data['_api_changelog'][0] );
          $notes_obj 		= get_post( $api_data['_api_other_notes'][0] );

          // Instantiate $response object
          $response = new \stdClass();

          switch( $request ) {

            /**
             * new_version here is compared with the current version in plugin
             * Provides info for plugin row and dashboard -> updates page
             */
            case 'pluginupdatecheck':
              $response->slug 					= $plugin_name;
              $response->new_version 				= $api_data['_api_new_version'][0];
              $response->url 						= $api_data['_api_plugin_url'][0];
              $response->package 					= $download_link;
              break;
            /**
             * Request for detailed information for view details page
             * more plugin info:
             * wp-admin/includes/plugin-install.php
             * Display plugin information in dialog box form.
             * function install_plugin_information()
             *
             */
            case 'plugininformation':
              $response->name 					= $this->product_id;
              $response->version 					= $api_data['_api_new_version'][0];
              $response->slug 					= $plugin_name;
              $response->author 					= $api_data['_api_author'][0];
              $response->homepage 				= $api_data['_api_plugin_url'][0];
              $response->requires 				= $api_data['_api_version_required'][0];
              $response->tested 					= $api_data['_api_tested_up_to'][0];
              $response->downloaded 				= $download_count;
              $response->last_updated 			= $api_data['_api_last_updated'][0];
              $response->download_link 			= $download_link;
              $response->sections = array(
                        'description' 	=> WCAM()->helpers->get_page_content( $desc_obj ),
                        'installation' 	=> WCAM()->helpers->get_page_content( $install_obj ),
                        'faq' 			=> WCAM()->helpers->get_page_content( $faq_obj ),
                        'screenshots' 	=> WCAM()->helpers->get_page_content( $screen_obj ),
                        'changelog' 	=> WCAM()->helpers->get_page_content( $change_obj ),
                        'other_notes' 	=> WCAM()->helpers->get_page_content( $notes_obj )
                        );
              break;
            /**
             * more theme info
             * wp-admin/includes/theme-install.php
             * WordPress Theme Install Administration API
             * $theme_field_defaults
             *
             * wp-admin/includes/theme.php
             * function themes_api()
             */

          }

          nocache_headers();

          die( serialize( apply_filters( 'api_manager_api_data_response', $response, $request, $plugin_name, $version, $order_id, $api_key, $activation_email, $post_id, $order_key ) ) );

        } else {

          $this->send_error_api_data( $this->request, array( 'download_revoked' => 'download_revoked' ) );

        }

      }

      /**
       * Plugin and Theme Update API error method.
       *
       * @since  1.0.0
       * @param  varies
       * @return object $response->errors
       */
      private function send_error_api_data( $request, $errors ) {

        $response = new stdClass();

        switch( $request ) {

          case 'pluginupdatecheck':
            $response->slug 					= '';
            $response->new_version 				= '';
            $response->url 						= '';
            $response->package 					= '';
            $response->errors 					= $errors;
            break;

          case 'plugininformation':
            $response->version 					= '';
            $response->slug 					= '';
            $response->author 					= '';
            $response->homepage 				= '';
            $response->requires 				= '';
            $response->tested 					= '';
            $response->downloaded 				= '';
            $response->last_updated 			= '';
            $response->download_link 			= '';
            $response->sections = array(
                      'description' 	=> '',
                      'installation' 	=> '',
                      'faq' 			=> '',
                      'screenshots' 	=> '',
                      'changelog' 	=> '',
                      'other_notes' 	=> ''
                      );
            $response->errors 					= $errors;
            break;

        }

        nocache_headers();

        die( serialize( $response ) );

      }

      /**
       * Updates the Software version in the user order
       * @since 1.3
       *
       * @param  int $user_id
       * @param  array $order_info  array containing a single order
       * @return void
       */
      private function update_order_version( $user_id, $order_info ) {
        global $wpdb;

        $current_info = WCAM()->helpers->get_users_data( $user_id );

        unset( $current_info[$this->api_key] );

        $update = WCAM()->helpers->get_user_order_array(
              $this->api_key,
              $user_id,
              $order_info['order_id'],
              $order_info['order_key'],
              $order_info['license_email'],
              $order_info['_api_software_title_parent'],
              $order_info['_api_software_title_var'],
              $order_info['software_title'],
              $order_info['parent_product_id'],
              $order_info['variable_product_id'],
              $this->software_version,
              $order_info['_api_activations'],
              $order_info['_api_activations_parent'],
              $order_info['_api_update_permission'],
              $order_info['is_variable_product'],
              $order_info['license_type'],
              $order_info['expires'],
              $order_info['_purchase_time'],
              $order_info['_api_was_activated'],
              $this->api_key
            );

        $new_info = WCAM()->array->array_merge_recursive_associative( $update, $current_info );

        update_user_meta( $user_id, $wpdb->get_blog_prefix() . WCAM()->helpers->user_meta_key_orders, $new_info );

      }

      /**
       * Update software version when an update query is received
       * @since 1.3
       *
       * @param  int $user_id
       * @param  array $current_info
       * @param  string $api_key
       * @param  string $order_key
       * @return void
       */
      private function update_activation_version( $user_id, $current_info, $api_key, $order_key ) {
        global $wpdb;

        // If true is returned then the software has been activated and false is returned
        if ( WCAM()->array->array_search_multi( $current_info, 'instance', $this->instance ) === true ) {

          foreach ( $current_info as $key => $activations ) {

            $activation_order_key = ( ! empty( $activations['api_key'] ) ) ? $activations['api_key'] : $activations['order_key'];

            $activation_domain_no_scheme 	= WCAM()->helpers->remove_url_prefix( $activations['activation_domain'] );
            $platform_no_scheme 			= WCAM()->helpers->remove_url_prefix( $this->domain );

            $new_version = false;

            if ( $this->software_version > $activations['software_version'] && $activation_order_key == $api_key && $activations['activation_active'] == 1 && $activations['instance'] == $this->instance && $activation_domain_no_scheme == $platform_no_scheme ) {

              $activation =
                array(
                  array(
                    'order_key' 		=> $activations['order_key'],
                    'instance'			=> $activations['instance'],
                    'product_id'		=> $activations['product_id'],
                    'activation_time' 	=> $activations['activation_time'],
                    'activation_active' => $activations['activation_active'],
                    'activation_domain' => WCAM()->helpers->esc_url_raw_no_scheme( $activations['activation_domain'] ),
                    'software_version' 	=> $this->software_version
                    )
                    );

              $new_version = true;

              break;

            }

          }

          foreach ( $current_info as $key => $activation_info ) {

            $activation_order_key = ( ! empty( $activation_info['api_key'] ) ) ? $activation_info['api_key'] : $activation_info['order_key'];

            $activation_domain_no_scheme 	= WCAM()->helpers->remove_url_prefix( $activation_info['activation_domain'] );
            $platform_no_scheme 			= WCAM()->helpers->remove_url_prefix( $this->domain );

            if ( $new_version === true && $activation_order_key == $api_key && $activation_info['activation_active'] == 1 && $activation_info['instance'] == $this->instance && $activation_domain_no_scheme == $platform_no_scheme ) {

              // Delete the activation data array
              unset( $current_info[$key] );

              $new_info = array_merge_recursive( $activation, $current_info );

              update_user_meta( $user_id, $wpdb->get_blog_prefix() . WCAM()->helpers->user_meta_key_activations . $order_key, $new_info );

              break;

            }

          } // end foreach

        }

      }

    } // End class



  }

}

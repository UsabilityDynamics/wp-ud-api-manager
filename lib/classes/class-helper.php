<?php
/**
 * UsabilityDynamics API Manager Software API Class
 *
 * @since 1.0.0
 */
namespace UsabilityDynamics\API_Manager {

  if( !class_exists( 'UsabilityDynamics\API_Manager\Helper' ) ) {

    /**
     * The list of useful functions
     *
     */

    class Helper {

      /**
       * Gets the order info 
       *
       * @see WC_Api_Manager_Helpers::get_order_info_by_email_with_order_key 
       * @param  string $order_key        order key
       * @param  string $activation_email license email
       * @return array                    array populated with user purchase info
       */
      static public function get_order_info_by_order_key( $order_key, $activation_email = false ) {
        global $wpdb;
        
        if ( ! empty( $activation_email ) ) {
          $user = get_user_by( 'email', $activation_email );
        } else {
          $user_id = $wpdb->get_var( $wpdb->prepare( "
            SELECT user_id
              FROM {$wpdb->prefix}usermeta
              WHERE meta_key = '" . $wpdb->get_blog_prefix() . "wc_ud_order'
                AND meta_value = %s
          ", $order_key ) );
          if( !empty( $user_id ) ) {
            $user = get_user_by( 'id', $user_id );
          }
        }

        if ( ! is_object( $user ) ) {
          return false;
        }

        //** Check if this is an order_key */
        if ( ! empty( $order_key ) ) {
          $user_orders = WCAM()->helpers->get_users_data( $user->ID );
          if ( is_array( $user_orders ) && ! empty( $user_orders[$order_key] ) ) {
            return $user_orders[$order_key]; // returns a single order info array identified by order_key
          } else {
            return false;
          }
        }
        return false;
      }

    } // End class



  }

}

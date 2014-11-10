<?php
/**
 * UsabilityDynamics API Manager Schema Class
 *
 * @since 1.0.0
 */
namespace UsabilityDynamics\API_Manager {

  if( !class_exists( 'UsabilityDynamics\API_Manager\Schema' ) ) {

    /**
     * Renders json schema of all available products
     *
     */
    class Schema {

      static public function init() {
        if( !has_action( 'template_redirect', array( __CLASS__, 'action_template_redirect' ) ) ) {
          add_action( 'template_redirect', array( __CLASS__, 'action_template_redirect' ) );
        }
      }
      
      /**
       *
       */
      static public function action_template_redirect() {
        if( $_SERVER[ 'REQUEST_URI' ] === '/products.json' ) {
          self::output();
          exit();
        }
      }
      
      /**
       * 
       */
      static public function get_products() {
        $products = array();
        
        $loop = new \WP_Query( array( 
          'post_type' => 'product', 
          'posts_per_page' => -1
        ) );
        
        while ( $loop->have_posts() ) { 
          $loop->the_post(); 
          global $product;
          
          if( $product->is_virtual() ) {
            
            $categories = array();
            foreach( get_the_terms( $product->id, 'product_cat' ) as $category ) {
              $categories[] = $category->name;
            }
            
            $icon = '';
            if ( has_post_thumbnail( $product->id ) ) {
              $icon = wp_get_attachment_image_src( get_post_thumbnail_id( $product->id ), 'thumbnail' );
              $icon = $icon[0];
            }
            
            $meta = get_post_custom( $product->id );
            
            //echo "<pre>"; print_r( $meta ); echo "</pre>"; 
            
            /** Bu sure we have product ID */
            if( !empty( $meta[ '_api_software_title_parent' ][ 0 ] ) ) {
              $products[] = array( 
                'name' => $product->get_title(),
                'description' => $product->get_post_data()->post_excerpt,
                'icon' => $icon,
                'url' => $product->get_permalink(),
                'type' => !empty( $meta[ 'product_type' ][ 0 ] ) ? $meta[ 'product_type' ][ 0 ] : __( 'Plugin' ),
                'product_id' => $meta[ '_api_software_title_parent' ][ 0 ],
                'version' => !empty( $meta[ '_api_new_version' ][ 0 ] ) ? $meta[ '_api_new_version' ][ 0 ] : false,
                'requires' => !empty( $meta[ '_api_version_required' ][ 0 ] ) ? $meta[ '_api_version_required' ][ 0 ] : false,
                'tested' => !empty( $meta[ '_api_tested_up_to' ][ 0 ] ) ? $meta[ '_api_tested_up_to' ][ 0 ] : false,
                'referrer' => implode( ',', $categories ),
                'order' => !empty( $meta[ 'order' ][ 0 ] ) ? $meta[ 'order' ][ 0 ] : 10
              );
            
            }
          }
        }
        
        wp_reset_query(); 
        
        return $products;
      }
      
      /**
       * 
       */
      static protected function output() {
        nocache_headers();
        if( function_exists( 'http_response_code' )) {
          http_response_code( 200 );
        } else {
          header( "HTTP/1.0 200 OK" );
        }
        wp_send_json( array( 
          'ok' => true,
          'en_US' => array(
            'products' => self::get_products(),
          )
        ) );
      }
      
      

    }

  }

}

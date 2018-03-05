<?php
/*
Plugin Name: Lektorify
Description: Creates Lektor parseable files upon post publication. Allowing you to use WordPress as backend for Lektor
Version: 0.1.0
Author: Tarique Sani
Author URI: http://tariquesani.net
License: GPLv3 or Later

Copyright 2017  Tarique Sani  (email : tariquesani@gmail.com)

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License, version 2, as
published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

if (version_compare(PHP_VERSION, '5.3.0', '<')) {
  wp_die("Lektorify requires PHP 5.3 or later");
}

// Include the wp-cli command file
require_once dirname( __FILE__ ) . "/lib/cli.php";



class Lektorify {

  private $dir;

  /**
   * Hook into WP Core
   */
  public function __construct() {
    // Hook into the admin menu
    add_action( 'admin_menu', array( $this, 'create_plugin_settings_page' ) );
    // 
    add_action( 'admin_init', array( $this, 'setup_sections' ) );
    add_action( 'admin_init', array( $this, 'setup_fields' ) );
    // Hook into publishing the post
    add_action( 'publish_post', array($this, 'publish_post'));
    // Get the lektor project path from options
    $this->dir = get_option('path_field');
  }

  /**
   * Setup the settings page UI and saving
   */
  public function create_plugin_settings_page() {
    // Add the menu item and page
    $page_title = 'Lektorify Settings Page';
    $menu_title = 'Lektorify Settings';
    $capability = 'manage_options';
    $slug = 'lektorify_fields';
    $callback = array( $this, 'plugin_settings_page_content' );
    add_submenu_page( 'options-general.php', $page_title, $menu_title, $capability, $slug, $callback );
  }

  public function plugin_settings_page_content() { ?>
    <div class="wrap">
      <h2>Lektorify Settings Page</h2>
      <form method="post" action="options.php">
              <?php
                  settings_fields( 'lektorify_fields' );
                  do_settings_sections( 'lektorify_fields' );
                  submit_button();
              ?>
      </form>
    </div> <?php
  }

  public function setup_sections() {
    add_settings_section( 'path_section', 'Path for Lektor project', array( $this, 'section_callback' ), 'lektorify_fields' );
  }

  public function section_callback( $arguments ) {
    echo 'Add complete absolute path for your lektor content, Also ensure that there is a directory called "blog" in it and the webserver user can write to this path';
  }


  public function setup_fields() {
    add_settings_field( 'path_field', 'Path', array( $this, 'field_callback' ), 'lektorify_fields', 'path_section' );
    register_setting( 'lektorify_fields', 'path_field',[$this, 'path_field_validator'] );
  }

  public function path_field_validator($input) {
    if (!is_writable($input.'/blog')) {
      add_settings_error('path_field','path_field_error','The path should be writable by webserver and have a directory called "blog", check path and permissions');
      return;
    } else {
      return $input;
    }
  }

  public function field_callback( $arguments ) {
    echo '<input name="path_field" id="path_field" type="text" size="50" value="' . get_option( 'path_field' ) . '" />';
  }




  /**
   * The actual useful stuff starts from here 
   * 
   */
  public function publish_post($postID)
  {
    $this->init_dir();
    $this->convert_post($postID);
  }


  /**
   * Get an array of all post and page IDs
   * Note: We don't use core's get_posts as it doesn't scale as well on large sites
   */
  function get_posts() {
    global $wpdb;
    $post_types = apply_filters( 'lektor_export_post_types', array( 'post', 'page' ) );
    $sql = "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_type IN ('" . implode("', '", $post_types) . "')";
    return $wpdb->get_col( $sql );

  }

  /**
   * Convert a posts meta data (both post_meta and the fields in wp_posts) to key value pairs for export
   */
  function convert_meta( $post ) {

    $output = array(
      'id'      => $post->ID,
      'title'   => get_the_title( $post ),
      'date'    => get_the_date( 'Y-m-d', $post ),
      'author'  => get_userdata( $post->post_author )->display_name,
      'excerpt' => get_the_excerpt( $post ),
    );

    //preserve exact permalink, since Lektor doesn't support redirection
    if ( 'page' != $post->post_type ) {
      $output[ 'permalink' ] = str_replace( home_url(), '', get_permalink( $post ) );
    }

    //convert traditional post_meta values, hide hidden values
    foreach ( get_post_custom( $post->ID ) as $key => $value ) {

      if ( substr( $key, 0, 1 ) == '_' )
        continue;

      $output[ $key ] = $value;

    }

    return $output;
  }


  /**
   * Convert post taxonomies for export
   */
  function convert_terms( $post ) {

    $output = array();
    foreach ( get_taxonomies( array( 'object_type' => array( get_post_type( $post ) ) ) ) as $tax ) {

      $terms = wp_get_post_terms( $post, $tax );

      //convert tax name for Lektor
      switch ( $tax ) {
      case 'post_tag':
        $tax = 'tags';
        break;
      case 'category':
        $tax = 'categories';
        break;
      }

      if ( $tax == 'post_format' ) {
        $output['format'] = get_post_format( $post );
      } else {
        $output[ $tax ] = wp_list_pluck( $terms, 'slug' );
      }
    }

    return $output;
  }

  /**
   * Convert the main post content. Strip out image paths
   */
  function convert_content( $post ) {

    $content = apply_filters( 'the_content', $post->post_content );

    $attachments = $this->get_attachments($post);

    foreach($attachments as $att_id => $attachment) {

      /* If not viewing an image attachment page, return. */
      if ( !wp_attachment_is_image($attachment->ID) )
        return;

        $full_image_url = wp_get_attachment_image_src($attachment->ID, 'full');

        $image_path = parse_url($full_image_url[0]);

        $content = str_replace($image_path['scheme'].'://'.$image_path['host'].dirname($image_path['path']).'/', '', $content);
        $content = str_replace(dirname($image_path['path']).'/', '', $content);
    }


    return $content;

  }

  /**
   * Loop through and convert all posts
   */
  function convert_posts() {
    foreach ( $this->get_posts() as $postID ) {
      $this->convert_post($postID);
    }

  }

  /**
   * Convert a single post. Given a postID convert it to .lr files with proper headers
   */
  public function convert_post($postID)
  {
      global $post;
      $post = get_post( $postID );
      setup_postdata( $post );

      $meta = array_merge( $this->convert_meta( $post ), $this->convert_terms( $postID ) );


      // remove falsy values, which just add clutter
      foreach ( $meta as $key => $value ) {
        if ( !is_numeric( $value ) && !$value )
          unset( $meta[ $key ] );
      }
      
      $output  = "title: ".$meta['title']."\n";
      $output .= "---\n";
      $output .= "pub_date: ".$meta['date']."\n";
      $output .= "---\n";
      $output .= "author: ".$meta['author']."\n";
      $output .= "---\n";
      $output .= "excerpt: ".$meta['excerpt']."\n";
      $output .= "---\n";      
      if (isset($meta['categories'])) {
        $output .= "categories: \n\n";
        $output .= implode("\n", $meta['categories']);
        $output .= "\n---\n";
      }
      if (isset($meta['tags'])) {
        $output .= "tags: \n\n";
        $output .= implode("\n", $meta['tags']);
        $output .= "\n---\n";
      }

      $output .= $this->convert_featured_image($post);

      $output .= "body: \n";
      $output .= "#### raw #### \n html: \n";

      $output .= $this->convert_content( $post );

      $this->write( $output, $post );

      $this->copy_images($post);

      if (php_sapi_name() == 'cli'){
          print $meta['title']." converted\n";
      }
  }



  function convert_featured_image($post){
    if ( has_post_thumbnail($post)) {
      $full_image_url = wp_get_attachment_image_src( get_post_thumbnail_id($post->ID),'full');

      $featured_image_filename = basename(parse_url($full_image_url[0],PHP_URL_PATH));

      $output = "featured_image: ".$featured_image_filename."\n";
      $output .= "---\n";
      $this->featured_image_filename = $featured_image_filename;
      return $output;
    }
  }

  public function get_attachments($id){
    return get_children(array('post_parent' => $id,
                        'post_status' => 'inherit',
                        'post_type' => 'attachment',
                        'post_mime_type' => 'image',
                        'order' => 'ASC',
                        'orderby' => 'menu_order ID'));
  }

  public function copy_images($post){
    
    $attachments = $this->get_attachments($post->ID);

    $full_basepath = get_home_path();

    // Get the intermediate image sizes and add the full size to the array.
    $sizes = get_intermediate_image_sizes();
    $sizes[] = 'full';

    foreach($attachments as $att_id => $attachment) {
      // If not image attachment page, return.
      if ( !wp_attachment_is_image($attachment->ID) )
        return;      

      foreach ($sizes as $size) {        
                   
        $full_image_url = wp_get_attachment_image_src($attachment->ID, $size);

        $image_path = parse_url($full_image_url[0], PHP_URL_PATH);
        
        //$full_image_path = $full_basepath . substr($image_path, strpos($image_path, basename($full_basepath)) + strlen(basename($full_basepath)));

        $full_image_path = $full_basepath . substr($image_path, strpos($image_path, basename($full_basepath)) + 1);
        
        $image_filename = basename($image_path);      

        /* Copy only if image finds a mention in the content or is full sized. */ 
        if (strpos($post->post_content, $image_filename)|| $size=='full') {
          copy($full_image_path, $this->dir .'/blog/'.get_page_uri( $post->id ).'/'.$image_filename);
        }

      }

    }
  }

 
  function filesystem_method_filter() {
    return 'direct';
  }

  function init_dir() {
    global $wp_filesystem;

    add_filter( 'filesystem_method', array( &$this, 'filesystem_method_filter' ) );

    WP_Filesystem();
  }


  /**
   * Write file to dir
   */
  function write( $output, $post ) {

    global $wp_filesystem;

    if ( get_post_type( $post ) == 'page' ) {
      $wp_filesystem->mkdir( $this->dir . get_page_uri( $post->id ) );
      $filename = get_page_uri( $post->id ) . '/contents.lr';
    } else if(get_post_type( $post ) == 'post') {
      $wp_filesystem->mkdir( $this->dir .'/blog/'.get_page_uri( $post->id ) );
      $filename = '/blog/'. get_page_uri( $post->id ) . '/contents.lr';
    } else {
      $filename = '_' . get_post_type( $post ) . 's/' . date( 'Y-m-d', strtotime( $post->post_date ) ) . '-' . $post->post_name . '.md';
    }
    $wp_filesystem->put_contents( $this->dir . $filename, $output );

  }

  /**
   * Main function, bootstraps, converts, and cleans up
   */
  function export() {
    $this->init_dir();
    $this->convert_posts();
  }

}

global $lektorify;
$lektorify = new Lektorify();

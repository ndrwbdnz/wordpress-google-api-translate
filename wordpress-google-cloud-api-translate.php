<?php
/**
 * Plugin Name: Google Cloud API translate
 * Plugin URI:
 * Description: Translate post, pages and products using Google Cloud Translate API
 * Version: 1.0
 * Author: Andrzej Bednorz
 * Author URI: https://github.com/ndrwbdnz
 * License: GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_User_Manager' ) ) :

class gat_translate_class{

    protected static $_instance;

    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    private $gat_api_key, $gat_strings_to_exclude,
            $gat_meta_to_exclude, $gat_meta_to_translate,
            $gat_taxonomies_to_exclude, $gat_taxonomies_to_translate;

    private $settings_panel;

    public function __construct(){

        //get settings values from database
        $this->gat_get_options();

        //load library to manage settings
        require __DIR__ . '/vendor/autoload.php';
        $this->settings_panel  = new \TDP\OptionsKit( 'gat' );
        $this->settings_panel->set_page_title( __( 'Polylang Auto-Translate Settings' ) );

        add_filter( 'gat_menu', array( $this, 'gat_setup_menu' ));
        add_filter( 'gat_settings_tabs', array( $this, 'gat_register_settings_tabs' ));
        add_filter( 'gat_registered_settings', array( $this, 'gat_register_settings' ));

        //this has to be fired every time options are updated - the page is not reloaded
        add_action( 'update_option_gat_settings', array($this, 'gat_get_options'), 10);
        
        // hook up plugins functions to various wordpress places and actions
        add_filter('manage_post_posts_columns', array( $this, 'gat_translation_columns'));
        add_filter('manage_page_posts_columns', array( $this, 'gat_translation_columns'));
        add_filter('manage_product_posts_columns', array( $this, 'gat_translation_columns'));
        add_action('manage_posts_custom_column', array( $this,'gat_populate_translation_columns'), 10, 2);
        add_action('manage_pages_custom_column', array( $this, 'gat_populate_translation_columns'), 10, 2);
        add_action('manage_products_custom_column', array( $this, 'gat_populate_translation_columns'), 10, 2);

        add_action('admin_enqueue_scripts', array( $this, 'gat_admin_enqueue_scripts'), 10);

        //add_action('admin_post_gat_auto_translate', array( $this, 'gat_auto_translate_handler' ));
        add_action('admin_notices', array( $this, 'gat_auto_translate_notice'));
        
    }


 // Settings ---------------------------------------------------------------------------------------------------------------
    
    function gat_setup_menu( $menu ) {
        // These defaults can be customized
        // $menu['parent'] = 'options-general.php';
        // $menu['menu_title'] = 'Settings Panel';
        // $menu['capability'] = 'manage_options';
        
        $menu['page_title'] = __( 'Polylang Auto Translate' );
        $menu['menu_title'] = $menu['page_title'];

        return $menu;
    }

    function gat_register_settings_tabs( $tabs ) {
        return array(
            'general' => __( 'General' )
        );
    }

    function gat_register_settings( $settings ) {
        $settings = array(
            'general' => array(

                array(
                    'id'   => 'gat_strings_to_exclude',
                    'name' => __( 'Exclude strings from automatic translation (each string in a new line)' ),
                    'type' => 'textarea'
                ),
                array(
                    'id'   => 'gat_meta_to_exclude',
                    'name' => __( 'Meta values to not copy or translate to translated post' ),
                    'type' => 'multiselect',
                    'multiple' => true,
                    'options' => $this->gat_get_metas(),
                ),
                array(
                    'id'   => 'gat_meta_to_translate',
                    'name' => __( 'Meta values to translate' ),
                    'type' => 'multiselect',
                    'multiple' => true,
                    'options' => $this->gat_get_metas(),
                ),
                array(
                    'id'   => 'gat_taxonomies_to_exclude',
                    'name' => __( 'Taxonomies to not copy or translate to translated post' ),
                    'type' => 'multiselect',
                    'multiple' => true,
                    'options' => $this->gat_get_taxonomies(),
                ),
                array(
                    'id'   => 'gat_taxonomies_to_translate',
                    'name' => __( 'Taxonomies to translate' ),
                    'type' => 'multiselect',
                    'multiple' => true,
                    'options' => $this->gat_get_taxonomies(),
                ),
                array(
                    'id'   => 'gat_api_key',
                    'name' => __( 'Google Translate API key' ),
                    'desc' => __( 'Add your API key to get started' ),
                    'type' => 'text'
                ),
            ),
        );
    
        return $settings;
    }
    
    function gat_get_options(){

        $option_table = get_option('gat_settings');

        $this->gat_strings_to_exclude = explode(PHP_EOL, array_key_exists('gat_strings_to_exclude', $option_table)? $option_table['gat_strings_to_exclude'] : array());
        $this->gat_meta_to_exclude = array_key_exists('gat_meta_to_exclude', $option_table)? $option_table['gat_meta_to_exclude'] : array();
        $this->gat_meta_to_translate = array_key_exists('gat_meta_to_translate', $option_table)? $option_table['gat_meta_to_translate'] : array();
        $this->gat_taxonomies_to_exclude = array_key_exists('gat_taxonomies_to_exclude', $option_table)? $option_table['gat_taxonomies_to_exclude'] : array();
        $this->gat_taxonomies_to_translate = array_key_exists('gat_taxonomies_to_translate', $option_table)? $option_table['gat_taxonomies_to_translate'] : array();
        $this->gat_api_key = array_key_exists('gat_api_key', $option_table)? $option_table['gat_api_key'] : '';

    }

    private function gat_get_metas(){

        global $wpdb;
        $query = $wpdb->prepare( "SELECT DISTINCT pm.meta_key as value, pm.meta_key as label  FROM up6_2_postmeta pm
                                LEFT JOIN up6_2_posts p ON p.ID = pm.post_id 
                                WHERE p.post_type in ('post', 'page', 'product')
                                ORDER BY pm.meta_key");
        $result = $wpdb->get_results($query);

        return $result;
        
        // array(array(
        //     'value' => '1',
        //     'label' => 'test 1',
        // ), array(
        //     'value' => '2',
        //     'label' => 'test 2',
        // ));
    }

    private function gat_get_taxonomies(){
        global $wpdb;
        $query = $wpdb->prepare( "SELECT DISTINCT tt.taxonomy as value, tt.taxonomy as label FROM up6_2_term_taxonomy tt
                                    LEFT JOIN up6_2_term_relationships tr ON tr.term_taxonomy_id = tt.term_taxonomy_id 
                                    LEFT JOIN up6_2_posts p ON p.ID = tr.object_id 
                                    WHERE p.post_type in ('post', 'page', 'product')
                                    ORDER BY tt.taxonomy");
        $result = $wpdb->get_results($query);

        return $result;
    }
    
 // Admin links  ---------------------------------------------------------------------------------------------------------------

    function gat_admin_enqueue_scripts(){
        //if this is post, page or product edit table page
        if (isset( $GLOBALS['pagenow'], $_GET['post_type'] ) && 'edit.php' === $GLOBALS['pagenow'] && in_array($_GET['post_type'], array('post', 'page', 'product'))){
            wp_enqueue_script( 'my_custom_script', plugin_dir_url( __FILE__ ) . 'js/admin.js', array('jquery'), null, true );
        }
    }

    //depreceated}
    function gat_translation_link($link, $language, $post_id){
        $post_type = get_post_type( $post_id );

        if (in_array($post_type, array('page', 'post', 'product'))){
            $args = array(
				'post_type' => $post_type,
				'from_post' => $post_id,
				'new_lang'  => $language->slug,
                'auto_translate'  => 1,
			);

			$gat_link = add_query_arg( $args, admin_url( 'post-new.php' ) );

			if ( 'display' === $context ) {
				$gat_link = wp_nonce_url( $link, 'new-post-translation' );
			} else {
				$gat_link = add_query_arg( '_wpnonce', wp_create_nonce( 'new-post-translation' ), $gat_link );
			}
        }

        return $link;
    }

    //depreceated
    function gat_translation_columns( $column_array ) {


        $pll_language_array = pll_the_languages(array('raw'=>1));
            // [id] => language id
            // [slug] => language code used in urls
            // [name] => language name
            // [url] => url of the translation
            // [flag] => url of the flag
            // [current_lang] => true if this is the current language, false otherwise
            // [no_translation] => true if there is no available translation, false otherwise
        
        foreach ($pll_language_array as $language){
            $column_array[ 'gat_' . $language['slug']] = $language['name'];
        }
    
        return $column_array;
    }
    
    //depreceated
    function gat_populate_translation_columns( $column_name, $id ) {

        //if (!$this->gat_pll_functions_exist()) return;
        
        $current_lang_slug = pll_get_post_language($id);
               
        $pll_language_array = pll_the_languages(array('raw'=>1));
        foreach ($pll_language_array as $language){
            if( $column_name == 'gat_' . $language['slug']){              
                $translated_id = pll_get_post($id, $language['slug']);
                if (!is_null($translated_id) && is_numeric($translated_id)){
                    $edit_link = get_edit_post_link($translated_id);
                    echo "<a href='".$edit_link."' target=_blank>edit</a>";
                } else {

                    echo '<a class="gat_add_post" href="'.get_admin_url().'admin-post.php?action=gat_auto_translate'.
                                                    '&post_id='.$id.
                                                    '&lang_from='.$current_lang_slug.
                                                    '&lang_to='.$language['slug'].
                                                    '">add draft</a>';

                    echo '</br></br><a class="gat_add_post" href="'.get_admin_url().'admin-post.php?action=gat_auto_translate'.
                                                    '&post_id='.$id.
                                                    '&lang_from='.$current_lang_slug.
                                                    '&lang_to='.$language['slug'].
                                                    '&edit_redirect=1'.
                                                    '">add & edit</a>';
                    
                    echo '</br></br><a class="gat_add_post" href="'.get_admin_url().'admin-post.php?action=gat_auto_translate'.
                                                    '&post_id='.$id.
                                                    '&lang_from='.$current_lang_slug.
                                                    '&lang_to='.$language['slug'].
                                                    '&auto_publish=1'.
                                                    '">add & publish</a>';

                }
            }
        }
    }

 // Admin actions ---------------------------------------------------------------------------------------------------------------
    
    //depreceated
    function gat_auto_translate_handler() {

        if( !isset($_REQUEST['post_id']) || !isset($_REQUEST['lang_from']) || !isset($_REQUEST['lang_to'])){
            $error_msg = 'Cannot translate: post or language not provided';
            $translated_post_id = FALSE;
        } else {
            $post_id = $_REQUEST['post_id'];
            $source_lang = $_REQUEST['lapostng_from'];
            $target_lang = $_REQUEST['lang_to'];
            $post_type = get_post_type($post_id);
            $edit_redirect = isset($_REQUEST['edit_redirect']) ? $_REQUEST['edit_redirect'] : 0;
            $publish = isset($_REQUEST['auto_publish']) ? $_REQUEST['auto_publish'] : 0;
            $error_msg = '';

            $translated_post_id = $this->gat_translate_function($post_id, $source_lang, $target_lang, $post_type, $publish, $error_msg);
        }

        if (!is_numeric($translated_post_id) && $error_msg != ''){
            $location = add_query_arg( array(
                'post_type' => $post_type,
                'gat_translation_error' => 1,
                'gat_translation_msg' => urlencode($error_msg),
                'post_status' => 'all'
            ), 'edit.php' );
        } else if($edit_redirect == 1){
            $location = add_query_arg( array(
                'post' => $translated_post_id,
                'action' => 'edit'
            ), 'post.php' );
        } else {
            $location = add_query_arg( array(
                'post_type' => $post_type,
                'gat_translation_msg' => "Translataion succesfull. Please review and publish the post.",
                'post_status' => 'all'
            ), 'edit.php' );    
        }

        wp_redirect( admin_url( $location ) );
        exit();

    }

    function gat_auto_translate_notice() {

        global $pagenow, $typenow;

        if( in_array($typenow, array('post', 'page', 'product')) && $pagenow == 'edit.php' && isset( $_REQUEST['gat_translation_msg'] )){
            if (isset( $_REQUEST['gat_translation_error'] )){
                echo "<div class=\"notice notice-error is-dismissible\"><p>{$_REQUEST['gat_translation_msg']}</p></div>";
            } else {
                echo "<div class=\"notice notice-success is-dismissible\"><p>{$_REQUEST['gat_translation_msg']}</p></div>";
            }
        }

    }

 // Main translation functions - post and product ---------------------------------------------------------------------------------------------------------------

    private function gat_new_post_translation($is_block_editor){
        global $post;
		static $done = array();

		if ( ! empty( $post ) && isset( $GLOBALS['pagenow'], $_GET['from_post'], $_GET['new_lang'] ) && 'post-new.php' === $GLOBALS['pagenow'] && $this->model->is_translated_post_type( $post->post_type ) ) {
			check_admin_referer( 'new-post-translation' );

			// Capability check already done in post-new.php
			$from_post_id = (int) $_GET['from_post'];
			$lang         = $this->model->get_language( sanitize_key( $_GET['new_lang'] ) );

			if ( ! $from_post_id || ! $lang || ! empty( $done[ $from_post_id ] ) ) {
				return $is_block_editor;
			}

			$done[ $from_post_id ] = true; // Avoid a second duplication in the block editor. Using an array only to allow multiple phpunit tests.

			//$this->taxonomies->copy( $from_post_id, $post->ID, $lang->slug );
			//$this->post_metas->copy( $from_post_id, $post->ID, $lang->slug );

			if ( is_sticky( $from_post_id ) ) {
				stick_post( $post->ID );
			}
		}

		return $is_block_editor;
    }

    //deprecated
    private function gat_translate_function($post_id, $source_lang, $target_lang, $post_type = 'post', $publish = 0, &$error_msg = ''){      

        try{
            if (in_array($post_type, array('post', 'page'))){
                $translated_post_id = $this->gat_translate_post($post_id, $source_lang, $target_lang, $publish);
            } elseif ($post_type == 'product') {
                $translated_post_id = $this->gat_translate_product($post_id, $source_lang, $target_lang, $publish);
            }

        } catch (Exception $e) {
            $error_msg = 'Translation error: '.$e->getMessage();
            return FALSE;
        }

        //join the translated post with the original post
        pll_set_post_language($translated_post_id, $target_lang);
        pll_save_post_translations(array(
            $source_lang => $post_id,
            $target_lang => $translated_post_id
        ));

    }
    
    //deprecated
    private function gat_translate_post($post_id, $source_lang, $target_lang, $publish = 0){

        $post_to_translate = get_post($post_id);
        
        $translated_content = $this->gat_translate_text($this->gat_api_key, $source_lang, $target_lang, $post_to_translate->post_content, $this->gat_strings_to_exclude);
        $translated_excerpt = $this->gat_translate_text($this->gat_api_key, $source_lang, $target_lang, $post_to_translate->post_excerpt, $this->gat_strings_to_exclude);
        $translated_title = $this->gat_translate_text($this->gat_api_key, $source_lang, $target_lang, $post_to_translate->post_title, $this->gat_strings_to_exclude);
        $translated_metas = $this->gat_translate_metas($source_lang, $target_lang, get_post_meta($post_id));
        $translated_taxonomies = $this->gat_translate_taxonomies($source_lang, $target_lang, get_post_taxonomies($post_id), $post_id);
        $translated_categories = array_key_exists('category', $translated_taxonomies) ? $translated_taxonomies['category'] : array();
        $translated_tags = array_key_exists('post_tag', $translated_taxonomies) ? $translated_taxonomies['post_tag'] : array();

        //after all translations have been done - instert the post
        $translated_post_id = wp_insert_post(array(
            'post_author' => $post_to_translate->post_author,
            'post_date' => $post_to_translate->post_date,
            'post_date_gmt' => $post_to_translate->post_date_gmt,
            'post_content' => $translated_content,
            'post_title' => html_entity_decode($translated_title, ENT_QUOTES),
            'post_excerpt' => html_entity_decode($translated_excerpt, ENT_QUOTES),
            'post_status' => ($publish == 1)? 'publish' : 'draft',
            'post_type' => $post_to_translate->post_type,
            'comment_status' => $post_to_translate->comment_status,
            'ping_status' => $post_to_translate->ping_status,
            'post_password' => $post_to_translate->post_password,
            'post_modified' => $post_to_translate->post_modified,
            'post_modified_gmt' => $post_to_translate->post_modified_gmt,
            'post_parent' => $post_to_translate->post_parent,
            'menu_order' => $post_to_translate->menu_order,
            'post_category' => $translated_categories,
            'tags_input' => $translated_tags,
            'tax_input' => $translated_taxonomies,
            'meta_input' => $translated_metas
        ));

        return $translated_post_id;
    }

    //depreceated
    //copy of the woocommerce function product_duplicate - with modifications
    //cannot use woocommerce duplicate_product function because polylang hooks into it and copies all product translations
    //this duplication is different - it is supposed to only copy the specific product (not all its translation products) and create a trasnlation product from it
    private function gat_translate_product($post_id, $source_lang, $target_lang, $publish = 0){
        
        $product = wc_get_product( $post_id );

		$meta_to_exclude = array_filter(
			apply_filters(
				'woocommerce_duplicate_product_exclude_meta',
				$this->gat_meta_to_exclude,
				array_map(
					function ( $datum ) {
						return $datum->key;
					},
					$product->get_meta_data()
				)
			)
		);
        
        //for the post we could first translate everything and then create a new post
        //for the product - we first clone the product object to incluide all it's details, and then we translate 
		$duplicate = clone $product;
		$duplicate->set_id( 0 );
        $duplicate->set_name($this->gat_translate_text($this->gat_api_key, $source_lang, $target_lang, $product->get_name()));
        $duplicate->set_description($this->gat_translate_text($this->gat_api_key, $source_lang, $target_lang, $product->get_description()));
        $duplicate->set_short_description($this->gat_translate_text($this->gat_api_key, $source_lang, $target_lang, $product->get_short_description()));
        $duplicate->set_purchase_note($this->gat_translate_text($this->gat_api_key, $source_lang, $target_lang, $product->get_purchase_note()));
		$duplicate->set_status( 'draft' );
		$duplicate->set_slug( '' );
        $duplicate->set_sku($product->get_sku( 'edit' ));

        //$duplicate->set_parent_id($product->get_parent_id());
		//$duplicate->set_total_sales( 0 );
		//if ( '' !== $product->get_sku( 'edit' ) ) {
		//	$duplicate->set_sku( wc_product_generate_unique_sku( 0, $product->get_sku( 'edit' ) ) );
		//}
		//$duplicate->set_date_created( null );
        //$duplicate->set_rating_counts( 0 );
		//$duplicate->set_average_rating( 0 );
		//$duplicate->set_review_count( 0 );

        $duplicate->set_meta_data($product->get_meta_data());
        foreach ( $meta_to_exclude as $meta_key ) {
			$duplicate->delete_meta_data( $meta_key );
		}

        $translated_metas = $this->gat_translate_metas($source_lang, $target_lang, get_post_meta($post_id));
        $translated_taxonomies = $this->gat_translate_taxonomies($source_lang, $target_lang, get_post_taxonomies($post_id), $post_id);
        $duplicate->set_tag_ids($translated_taxonomies['product_tag']);
        $duplicate->set_category_ids($translated_taxonomies['product_cat']);

        //attributes meta field stores all the attributes for the product (size, weight, color etc) with their relevant paramters (for variations, visible in front end, etc)
        //the different values of the attributes (e.g. possible color values) are stored as taxonomies
        //each attribute has the options array inside with the stored ID of taxonomy term, that contains the value (e.g. color name)
        //we have translated all taxonomies already above
        //attributes structure stays the same as in the original product
        //the only thing that changes are taxonomy tag IDs
        $attributes = (array) $product->get_attributes();
        $translated_attributes = array();

        foreach( $attributes as $key => $attribute ){
            //we are looping only on attributes, not taxonomies
            //we are not interested here in taxonomies that are not used for attributes
            //i.e. we will not create new attribtes from them, as might have been the case in another scenario
            //https://stackoverflow.com/questions/53944532/auto-set-specific-attribute-term-value-to-purchased-products-on-woocommerce

            if( ! is_null( $translated_taxonomies[$attribute] )) {
                $translated_attribute = $attribute;
                $translated_attribute->set_options( $translated_taxonomies[$attribute] );       //here we assign translation
                $translated_attributes[$key] = $translated_attribute;
            } else {
                //if the attribute has not been translated (neither copied, nor translarted - i.e. it must have been excluded)
                //then exclude it from attributes - i.e. do not copy it to $translated_attributes array
            }
        }

        $duplicate->set_attributes( $translated_attributes );

        // // Append the new term in the product
        // if( ! has_term( $term_name, $taxonomy, $_product->get_id() ) ){
        //         wp_set_object_terms($_product->get_id(), $term_slug, $taxonomy, true );
        // }

        // check if there are translated products for cross sell and up sell.
        //If so - link them. If not - set these references empty and provide a notice.
        $cross_sell_ids = $product->get_cross_sell_ids();
        $translated_cross_sell_id = array();
        foreach ($cross_sell_ids as $cross_sell_id){
            $translated_cross_sell_id = pll_get_post($cross_sell_id, $target_lang);
            if (!is_null($translated_cross_sell_id)){
                array_push($translated_cross_sell_ids, $translated_cross_sell_id);
            }
        }
        $duplicate->set_cross_sell_ids($translated_cross_sell_ids);

        $up_sell_ids = $product->get_upsell_ids();
        $translated_up_sell_id = array();
        foreach ($up_sell_ids as $up_sell_id){
            $translated_up_sell_id = pll_get_post($up_sell_id, $target_lang);
            if (!is_null($translated_up_sell_id)){
                array_push($translated_up_sell_ids, $translated_up_sell_id);
            }
        }
        $duplicate->set_upsell_ids($translated_up_sell_ids);


		// Save parent product.
		$duplicate->save();

		// Duplicate children of a variable product.
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child           = wc_get_product( $child_id );
				$child_duplicate = clone $child;
				$child_duplicate->set_parent_id( $duplicate->get_id() );
				$child_duplicate->set_id( 0 );
				//$child_duplicate->set_date_created( null );

				// If we wait and let the insertion generate the slug, we will see extreme performance degradation
				// in the case where a product is used as a template. Every time the template is duplicated, each
				// variation will query every consecutive slug until it finds an empty one. To avoid this, we can
				// optimize the generation ourselves, avoiding the issue altogether.
				$this->gat_generate_unique_slug( $child_duplicate );

				// if ( '' !== $child->get_sku( 'edit' ) ) {
				// 	$child_duplicate->set_sku( wc_product_generate_unique_sku( 0, $child->get_sku( 'edit' ) ) );
				// }
                $child_duplicate->set_sku($child->get_sku( 'edit' ));

				foreach ( $meta_to_exclude as $meta_key ) {
					$child_duplicate->delete_meta_data( $meta_key );
				}
				//do_action( 'woocommerce_product_duplicate_before_save', $child_duplicate, $child );
				$child_duplicate->save();
			}

			// Get new object to reflect new children.
			$duplicate = wc_get_product( $duplicate->get_id() );
		}

		return $duplicate;
        
    }

 // Specialized translation sub-functions - metas, taxonomies and terms -------------------------------------------------------------------------------------
    //translate metas
    private function gat_translate_metas($source_lang, $target_lang, $post_metas){
        
        $translated_metas = array();

        foreach ($post_metas as $key => $meta_value) {
            //check if metas are to be assigned to the translated post. Flag 0 means skip.
            $meta_flag = $this->gat_meta_flag($key);
            if ( $meta_flag != "exclude"){
                //check if meta values are to be translated, if not - simply copy the values. Flag 1 means translate
                if ( $meta_flag == "translate"){
                    $translated_meta = $this->gat_translate_text($this->gat_api_key, $source_lang, $target_lang, $meta_value[0], $this->gat_strings_to_exclude);
                } else {
                    $translated_meta = $meta_value[0];
                }
                $translated_metas[$key] = $translated_meta;
            }
        }
        
        return $translated_metas;

    }

    //translate taxonomies
    private function gat_translate_taxonomies($source_lang, $target_lang, $post_taxonomies, $post_id){

        $translated_taxonomies = array();

        foreach ($post_taxonomies as $taxonomy){
            //check if taxonomy is not to be skipped
            $taxonomy_flag = $this->gat_taxonomy_flag($taxonomy);
            if ( $taxonomy_flag != "exclude"){
                //get terms for the given taxonomy assigned to this post
                $terms = wp_get_post_terms($post_id, $taxonomy);
                $counter = 0;
                foreach ($terms as $term) {
                    //this function is called recursively to walk up the tree of terms and recreate the term tree in the target language (parent terms)
                    $translated_term_id = $this->gat_translate_terms($source_lang, $target_lang, $term, $taxonomy_flag);
                    $translated_term = get_term($translated_term_id, $term->taxonomy);
                    $translated_taxonomies[$translated_term->taxonomy][$counter] = $translated_term->term_id;
                    $counter = $counter + 1;
                }
            }
        }
        return $translated_taxonomies;
    }
    
    //recursively translate terms
    private function gat_translate_terms($source_lang, $target_lang, $term, $taxonomy_flag){
        //see if the term alraedy exists in the target language
        $translated_term_id = pll_get_term($term->term_id, $target_lang);
        if (!$translated_term_id) {
            //create the term in the target language. First check if it is to be translated.
            //either all terms from a given taxonomy are translated or none of them - the check is on the whole taxonomy level,  not on the level of individual term
            if($taxonomy_flag == "translate"){
                $term_name_translation = $this->gat_translate_text($this->gat_api_key, $source_lang, $target_lang, $term->name, $this->gat_strings_to_exclude);
            } else {
                $term_name_translation = $term->name;
            }
            
            //regardless if the term name should be translated, the term is another language version, so we have still to create it

            //see if the orignal term had a paretn - this is the recursive part - walking up the taxonomy tree
            $translated_term_parent_id = 0;
            if ($term->parent != 0){
                $parent_term = get_term($term->parent, $term->taxonomy);
                $translated_term_parent_id = $this->gat_translate_terms($source_lang, $target_lang, $parent_term, $taxonomy_flag);
            }
            
            $translated_term = wp_insert_term($term_name_translation, $term->taxonomy,
                                            array('parent'=> $translated_term_parent_id, 'slug' => sanitize_title($term_name_translation).'-'.$target_lang));
            

            pll_set_term_language($translated_term['term_id'], $target_lang);
            pll_save_term_translations(array(
                $source_lang => $term->term_id,
                $target_lang => $translated_term['term_id']
            ));

            $translated_term_id = $translated_term['term_id'];

        }

        return $translated_term_id;

    }


 // Generic translate text function and API ------------------------------------------------------------------------------------------------------------------
    //public function so that it can be used also from other plugins etc
    public function gat_translate_text($api_key, $source_lang, $target_lang, $text_to_translate, $excluded_strings = array()){

        $placeholders = array();
        for( $i = 1 ; $i <= count($excluded_strings); $i++ ){
            $placeholders[] = '1NT' . $i . 'NT1';
        }
        $text_to_translate = str_replace($excluded_strings, $placeholders, $text_to_translate);
        
        //prepare data to be translated
        $translation_method = 'POST';
        $translation_url = 'https://translation.googleapis.com/language/translate/v2';
        $translation_data = array('source' => $source_lang, 'target' => $target_lang, 'q' => $text_to_translate, 'key' => $api_key);

        //perform curl request
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_POST, 1);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $translation_data);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_USERPWD, "username:password");
        curl_setopt($curl, CURLOPT_URL, $translation_url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        //curl_setopt($curl, CURLOPT_REFERER, actual_link());

        //execute request and close curl object
        $result = curl_exec($curl);
        curl_close($curl);

        //handle trasnlated result
        $translation_object = json_decode( (string) $result);

        if ($translation_object->error){
            throw new Exception($translation_object->error->message);
        } else {
            $translated_text = $translation_object->data->translations[0]->translatedText;
            $translated_text = str_ireplace( $placeholders, $excluded_strings, $translated_text );
            return $translated_text;
        }
    }

 // Helper functions --------------------------------------------------------------------------------------------------------------------

    public function gat_meta_flag($meta){

        if (in_array($meta, $this->gat_meta_to_exclude)){
            $meta_flag = "exclude";
        } else if(in_array($meta, $this->gat_meta_to_translate)){
            $meta_flag = "translate";
        } else {
            $meta_flag = "copy";
        }
        return $meta_flag;
    }

    public function gat_taxonomy_flag($taxonomy){

        if (in_array($taxonomy, $this->gat_taxonomies_to_exclude)){
            $taxonomy_flag = "exclude";
        } else if(in_array($taxonomy, $this->gat_taxonomies_to_translate)){
            $taxonomy_flag = "translate";
        } else {
            $taxonomy_flag = "copy";
        }
        return $taxonomy_flag;
    }

    //copied from woocommerce duplicate product WC_Admin_Duplicate_Product class
    private function gat_generate_unique_slug( $product ) {
		global $wpdb;

		// We want to remove the suffix from the slug so that we can find the maximum suffix using this root slug.
		// This will allow us to find the next-highest suffix that is unique. While this does not support gap
		// filling, this shouldn't matter for our use-case.
		$root_slug = preg_replace( '/-[0-9]+$/', '', $product->get_slug() );

		$results = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_name FROM $wpdb->posts WHERE post_name LIKE %s AND post_type IN ( 'product', 'product_variation' )", $root_slug . '%' )
		);

		// The slug is already unique!
		if ( empty( $results ) ) {
			return;
		}

		// Find the maximum suffix so we can ensure uniqueness.
		$max_suffix = 1;
		foreach ( $results as $result ) {
			// Pull a numerical suffix off the slug after the last hyphen.
			$suffix = intval( substr( $result->post_name, strrpos( $result->post_name, '-' ) + 1 ) );
			if ( $suffix > $max_suffix ) {
				$max_suffix = $suffix;
			}
		}

		$product->set_slug( $root_slug . '-' . ( $max_suffix + 1 ) );
	}

    private function gat_pll_functions_exist(){
                //check if polylang functions exists
        if ( !function_exists('pll_get_post_language')
        || !function_exists('pll_the_languages')
        || !function_exists('pll_get_post')
        || !function_exists('pll_set_post_language')
        || !function_exists('pll_save_post_translations')
        || !function_exists('pll_set_term_language')
        || !function_exists('pll_save_term_translations')){
            return FALSE;
        } else {
            return TRUE;
        }
    }

}

endif;

function gat_auto_translate() {
	return gat_translate_class::instance();
}

gat_auto_translate();
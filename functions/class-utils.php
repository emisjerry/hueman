<?php
/**
*
*
* @package      Hueman
* @since        3.0.0
* @author       Nicolas GUILLAUME <nicolas@presscustomizr.com>
* @copyright    Copyright (c) 2016, Nicolas GUILLAUME
* @link         http://presscustomizr.com/hueman
* @license      http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
*/
if ( ! class_exists( 'HU_utils' ) ) :
  class HU_utils {
    //Access any method or var of the class with classname::$instance -> var or method():
    static $inst;
    public $default_options;
    public $db_options;
    public $options;//not used in customizer context only
    public $is_customizing;
    public $hu_options_prefixes;

    function __construct () {
      self::$inst =& $this;

      //init properties
      //when is_admin, the after_setup_theme is fired too late
      if ( is_admin() && ! hu_is_customizing() ) {
        $this -> hu_init_properties();
      } else {
        add_action( 'after_setup_theme'       , array( $this , 'hu_init_properties') );

      }

      //refresh the theme options right after the _preview_filter when previewing
      add_action( 'customize_preview_init'  , array( $this , 'hu_customize_refresh_db_opt' ) );
    }//construct



    /***************************
    * EARLY HOOKS
    ****************************/
    /**
    * Init class properties after_setup_theme
    * Fixes the bbpress bug : Notice: bbp_setup_current_user was called incorrectly. The current user is being initialized without using $wp->init()
    * hu_get_default_options uses is_user_logged_in() => was causing the bug
    * hook : after_setup_theme
    *
    */
    function hu_init_properties() {
      //all theme options start by "hu_" by convention
      //$this -> hu_options_prefixes = apply_filters('hu_options_prefixes', array('hu_') );

      $this -> is_customizing   = hu_is_customizing();
      $this -> db_options       = false === get_option( HU_THEME_OPTIONS ) ? array() : (array)get_option( HU_THEME_OPTIONS );
      $this -> default_options  = $this -> hu_get_default_options();
      $_trans                   = 'started_using_hueman';

      //What was the theme version when the user started to use Hueman?
      //new install = no options yet
      //very high duration transient, this transient could actually be an option but as per the wordpress.org themes guidelines, only one option is allowed for the theme settings
      if ( 1 >= count( $this -> db_options ) || ! esc_attr( get_transient( $_trans ) ) ) {
        set_transient(
          $_trans,
          sprintf('%s|%s' , 1 >= count( $this -> db_options ) ? 'with' : 'before', HUEMAN_VER ),
          60*60*24*3650
        );
      }
    }







    /****************************************************************************
    ****************************** OPTIONS **************************************
    *****************************************************************************/
    /**
    * Returns the default options array
    *
    * @package Hueman
    * @since Hueman 3.0.0
    */
    function hu_get_default_options() {
      $_db_opts     = empty($this -> db_options) ? $this -> hu_cache_db_options() : $this -> db_options;
      $def_options  = isset($_db_opts['defaults']) ? $_db_opts['defaults'] : array();

      //Don't update if default options are not empty + customizing context
      //customizing out ? => we can assume that the user has at least refresh the default once (because logged in, see conditions below) before accessing the customizer
      //customizing => takes into account if user has set a filter or added a new customizer setting
      if ( ! empty($def_options) && $this -> is_customizing )
        return apply_filters( 'hu_default_options', $def_options );

      //Always update/generate the default option when (OR) :
      // 1) user is logged in
      // 2) they are not defined
      // 3) theme version not defined
      // 4) versions are different
      if ( is_user_logged_in() || empty($def_options) || ! isset($def_options['ver']) || 0 != version_compare( $def_options['ver'] , HUEMAN_VER ) ) {
        $def_options          = $this -> hu_generate_default_options( HU_utils_settings_map::$instance -> hu_get_customizer_map( $get_default_option = 'true' ) , HU_THEME_OPTIONS );
        //Adds the version in default
        $def_options['ver']   =  HUEMAN_VER;

        $_db_opts['defaults'] = $def_options;
        //writes the new value in db
        update_option( HU_THEME_OPTIONS , $_db_opts );
      }
      return apply_filters( 'hu_default_options', $def_options );
    }



    /**
    * Generates the default options array from a customizer map + add slider option
    *
    */
    function hu_generate_default_options( $map, $option_group = null ) {
      //do we have to look in a specific group of option (plugin?)
      $option_group   = is_null($option_group) ? HU_THEME_OPTIONS : $option_group;

      //initialize the default array with the sliders options
      $defaults = array();

      foreach ($map['add_setting_control'] as $key => $options) {

        $option_name = $key;
        //write default option in array
        if( isset($options['default']) )
          $defaults[$option_name] = ( 'checkbox' == $options['type'] ) ? (bool) $options['default'] : $options['default'];
        else
          $defaults[$option_name] = null;
      }//end foreach

      return $defaults;
    }



    /**
    * Returns an option from the options array of the theme.
    *
    * @package Hueman
    */
    function hu_opt( $option_name , $option_group = null, $use_default = true ) {
        //do we have to look for a specific group of option (plugin?)
        $option_group = is_null( $option_group ) ? HU_THEME_OPTIONS : $option_group;
        //when customizing, the db_options property is refreshed each time the preview is refreshed in 'customize_preview_init'
        $_db_options  = empty($this -> db_options) ? $this -> hu_cache_db_options() : $this -> db_options;

        //do we have to use the default ?
        $__options    = $_db_options;
        $_default_val = false;
        if ( $use_default ) {
          $_defaults      = $this -> default_options;
          if ( is_array($_defaults) && isset($_defaults[$option_name]) )
            $_default_val = $_defaults[$option_name];
          $__options      = wp_parse_args( $_db_options, $_defaults );
        }

        //assign false value if does not exist, just like WP does
        $_single_opt    = isset($__options[$option_name]) ? $__options[$option_name] : false;

        //ctx retro compat => falls back to default val if ctx like option detected
        //important note : some options like hu_slider are not concerned by ctx
        if ( ! $this -> hu_is_option_excluded_from_ctx( $option_name ) ) {
          if ( is_array($_single_opt) && ! class_exists( 'HU_ctx' ) )
            $_single_opt = $_default_val;
        }

        //allow ctx filtering globally
        $_single_opt = apply_filters( "hu_opt" , $_single_opt , $option_name , $option_group, $_default_val );

        //allow single option filtering
        return apply_filters( "hu_opt_{$option_name}" , $_single_opt , $option_name , $option_group, $_default_val );
    }



    /**
    * Get the saved options in customizer Screen, merge them with the default theme options array and return the updated global options array
    *
    */
    function hu_get_theme_options ( $option_group = null ) {
        //do we have to look in a specific group of option (plugin?)
        $option_group       = is_null($option_group) ? HU_THEME_OPTIONS : $option_group;
        $saved              = empty($this -> db_options) ? $this -> hu_cache_db_options() : $this -> db_options;
        $defaults           = $this -> default_options;
        $__options          = wp_parse_args( $saved, $defaults );
        //$__options        = array_intersect_key( $__options, $defaults );
      return $__options;
    }



    /**
    * Set an option value in the theme option group
    * @param $option_name : string
    * @param $option_value : sanitized option value, can be a string, a boolean or an array
    * @param $option_group : string ( like hu_theme_options )
    * @return  void
    *
    */
    function hu_set_option( $option_name , $option_value, $option_group = null ) {
      $option_group           = is_null($option_group) ? HU_THEME_OPTIONS : $option_group;
      $_options               = $this -> hu_get_theme_options( $option_group );
      $_options[$option_name] = $option_value;

      update_option( $option_group, $_options );
    }


    /**
    * The purpose of this callback is to refresh and store the theme options in a property on each customize preview refresh
    * => preview performance improvement
    * 'customize_preview_init' is fired on wp_loaded, once WordPress is fully loaded ( after 'init', before 'wp') and right after the call to 'customize_register'
    * This method is fired just after the theme option has been filtered for each settings by the WP_Customize_Setting::_preview_filter() callback
    * => if this method is fired before this hook when customizing, the user changes won't be taken into account on preview refresh
    *
    * hook : customize_preview_init
    * @return  void
    *
    */
    function hu_customize_refresh_db_opt(){
      $this -> db_options = false === get_option( HU_THEME_OPTIONS ) ? array() : (array)get_option( HU_THEME_OPTIONS );
    }


    /**
    * In live context (not customizing || admin) cache the theme options
    *
    */
    function hu_cache_db_options($opt_group = null) {
      $opts_group = is_null($opt_group) ? HU_THEME_OPTIONS : $opt_group;
      $this -> db_options = false === get_option( $opt_group ) ? array() : (array)get_option( $opt_group );
      return $this -> db_options;
    }



    /***************************
    * CTX COMPAT
    ****************************/
    /**
    * Boolean helper : tells if this option is excluded from the ctx treatments.
    * @return bool
    */
    function hu_is_option_excluded_from_ctx( $opt_name ) {
      return in_array( $opt_name, $this -> hu_get_skope_excluded_options() );
    }


    /**
    * Helper : define a set of options not impacted by ctx like last_update_notice.
    * @return  array of excluded option names
    */
    function hu_get_skope_excluded_options() {
      return apply_filters(
        'hu_get_skope_excluded_options',
        array(
          'defaults',
          'last_update_notice',
          'last_update_notice_pro',
          'sidebar-areas',
          'social-links',
          'body-background'
        )
      );
    }

  }//end of class
endif;
<?php
/**
 * BuddyPress support for bbP Single Forum Search.
 *
 * @package bbP_Single_Forum_Search
 */

/**
 * BuddyPress support for bbP Single Forum Search.
 */
class bbP_BP_Single_Forum_Search {
	/**
	 * Search slug.
	 *
	 * @var string
	 */
	public static $slug = 'search';

	/**
	 * Are we doing a group forum search?
	 *
	 * @var bool
	 */
	protected static $is_search = false;

	/**
	 * Initializer.
	 */
	public static function init() {
		return new self();
	}

	/**
	 * Constructor.
	 */
	protected function __construct() {
		add_action( 'bp_actions', array( $this, 'search_listener' ) );

		add_filter( 'bbp_get_search_url',              array( $this, 'modify_search_url' ) );
		add_filter( 'bbp_get_search_results_url',      array( $this, 'modify_search_results_url' ) );
		add_filter( 'bbp_search_results_pagination',   array( $this, 'modify_search_pagination' ) );
		add_filter( 'bbp_breadcrumbs',                 array( $this, 'modify_search_breadcrumb' ) );

		do_action( 'bbp_bp_single_forum_search_loaded', $this );
	}

	/**
	 * Listen for group forum searches.
	 */
	public function search_listener() {
		if ( false === bp_is_current_action( 'forum' ) ) {
			return;
		}

		if ( false === bp_is_action_variable( self::$slug ) ) {
			return;
		}

		if ( false === bp_action_variable( 1 ) ) {
			return;
		}

		self::$is_search = true;

		add_action( 'bp_template_content', array( $this, 'display_search_content' ) );
	}

	/**
	 * Displays the search results in the BP template.
	 */
	public function display_search_content() {
		if ( empty( self::$is_search ) ) {
			return $retval;
		}

		// Set search term.
		set_query_var( bbp_get_search_rewrite_id(), urldecode( bp_action_variable( 1 ) ) );

		$forum_id = bbp_get_group_forum_ids();
		$page = bp_action_variable( 3 );

		// Set forum ID.
		set_query_var( 'bbp_forum_id', $forum_id[0] );

		// Set pagination page number if applicable.
		if ( ! empty( $page ) ) {
			set_query_var( 'paged', (int) $page );
		}

		// Run search shortcode.
		echo do_shortcode( "[bbp-search]" );
	}

	/**
	 * Modify the search URL for BP group forum searches.
	 *
	 * @param  string $retval
	 * @return string
	 */
	public function modify_search_url( $retval = '' ) {
		if ( false === bp_is_current_action( 'forum' ) ) {
			return $retval;
		}

		return $this->get_search_url();
	}

	/**
	 * Modify the search results redirect URL for BP group forum searches.
	 *
	 * @param  string $retval
	 * @return string
	 */
	public function modify_search_results_url( $retval = '' ) {
		if ( false === bp_is_current_action( 'forum' ) ) {
			return $retval;
		}

		$term = urlencode( stripslashes( $_GET['bbp_search'] ) );

		return trailingslashit( $this->get_search_url() . $term );
	}

	/**
	 * Modify the search pagination base for BP group forum searches.
	 *
	 * @param  array $retval Current pagination args.
	 * @return array
	 */
	public function modify_search_pagination( $retval ) {
		if ( empty( self::$is_search ) ) {
			return $retval;
		}

		/*
		 * No need to URL encode.
		 *
		 * add_query_arg() already handles this in paginate_links().
		 */
		$retval['base'] = $this->get_search_url() . bp_action_variable( 1 ) . $retval['base'];
		return $retval;
	}

	/**
	 * Modify breadcrumb during BP group searches.
	 *
	 * @param  array $r Current breadcrumb values.
	 * @return array
	 */
	public function modify_search_breadcrumb( $r ) {
		if ( empty( self::$is_search ) ) {
			return $r;
		}

		// Home
		$r[0] = '<a href="' . bp_get_group_permalink( groups_get_current_group() ). '">' . _x( 'Home', 'Group screen navigation title', 'buddypress' ) . '</a>';

		// Group forum
		$r[1] = '<a href="' . bp_get_group_permalink( groups_get_current_group() ). 'forum/">' . __( 'Forum', 'bbpress' ) . '</a>';

		// Search - remove this one
		unset( $r[2] );

		return $r;
	}

	/**
	 * Helper method to grab the current group forum's search permalink.
	 *
	 * @return string
	 */
	public function get_search_url() {
		return trailingslashit( bp_get_group_permalink( groups_get_current_group() ) . 'forum/' . self::$slug );
	}
}
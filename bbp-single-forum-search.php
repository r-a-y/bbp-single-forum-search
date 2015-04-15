<?php
/*
Plugin Name: bbP Single Forum Search
Description: Adds a search form on bbPress single forum index pages.
Author: r-a-y
Author URI: http://profiles.wordpress.org/r-a-y
Version: 0.1
License: GPLv2 or later
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'bbP_Single_Forum_Search' ) ) :

add_action( 'bbp_includes', array( 'bbP_Single_Forum_Search', 'init' ) );

/**
 * Add a search form on bbPress single forum index pages.
 *
 * Search results are displayed on bbPress' sitewide search page, but filtered
 * to the existing forum.
 */
class bbP_Single_Forum_Search {
	/**
	 * Init method.
	 */
	public static function init() {
		return new self();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'bbp_template_before_pagination_loop', array( $this, 'add_search_form' ) );
		add_action( 'wp_head',                             array( $this, 'inline_css' ), 999 );

		add_filter( 'bbp_after_has_search_results_parse_args' , array( $this, 'modify_search_results_query' ) );
		add_filter( 'bbp_get_search_pagination_links',          array( $this, 'modify_search_pagination_links' ) );
		add_filter( 'bbp_get_search_results_url',               array( $this, 'modify_search_results_url' ) );
		add_filter( 'bbp_get_search_title',                     array( $this, 'modify_search_title' ), 10, 2 );
	}

	/**
	 * Add search form to single forum index pages.
	 */
	public function add_search_form(){
		if ( ! did_action( 'bbp_template_before_single_forum' ) ) {
			return;
		}
		remove_action( 'bbp_template_before_pagination_loop', array( $this, 'add_search_form' ) );

		// object buffer the search form template part so we can modify it later
		ob_start();
		bbp_get_template_part( 'form', 'search' );
		$form = ob_get_contents();
		ob_end_clean();

		// inject our custom hidden input field into the search form
		$forum_id = bbp_get_forum_id();
		$input = '';
		if ( ! empty( $forum_id ) ) {
			$input = '<input type="hidden" name="bbp_search_forum_id" value="' . $forum_id . '" />';
			$form = str_replace( '</form>', $input . '</form>', $form );

			$placeholder = esc_attr__( 'Search Forum Posts...', 'bbp-single-forum-search' );
			$form = str_replace( 'id="bbp_search"', 'id="bbp_search" placeholder="' . $placeholder . '"', $form );
		}

		// output the search form
	?>

		<div class="bbp-search-form">
	    		<?php echo $form; ?>
		</div>

	    <?php
	}

	/**
	 * Inline CSS.
	 *
	 * Disable with the 'bbp_single_forum_search_enable_css' filter.
	 */
	public function inline_css() {
		if ( false === (bool) apply_filters( 'bbp_single_forum_search_enable_css', true ) ) {
			return;
		}

		$bail = true;

		if ( function_exists( 'bp_is_current_action' ) && bp_is_current_action( 'forum' ) ) {
			$bail = false;
		}

		if ( $bail && bbp_is_single_forum() ) {
			$bail = false;
		}

		if ( $bail ) {
			return;
		}
	?>

		<style type="text/css">
			#bbp-search-form {float:right; margin-bottom:1.5em;}
			#bbpress-forums #bbp-search-form #bbp_search {width:160px; padding:4px;}
			.bbp-pagination {width:auto; line-height:2.5;}
		</style>

	<?php
	}

	/*
	 * Filter the forum search query to search for a specific forum.
	 *
	 * @param array $r Existing search query args
	 * @return array
	 */
	public function modify_search_results_query( $r ){
		if ( empty( $_GET['bbp_forum_id'] ) ) {
			return $r;
		}

		//Get the submitted forum ID (from the hidden field added in step 2)
		$forum_id = (int) $_GET['bbp_forum_id'];

		//If the forum ID exits, filter the query
		if ( ! empty( $forum_id ) ) {
			$r['meta_query'] = array( array(
				'key'     => '_bbp_forum_id',
				'value'   => $forum_id,
				'compare' => '=',
			) );
		}

		return $r;
	}

	/**
	 * Add our special forum ID to the forum search pagination links.
	 *
	 * This so we can filter search results by a specific forum.
	 *
	 * @param string $retval Existing search pagination links
	 * @return string
	 */
	public function modify_search_pagination_links( $retval = '' ) {
		if ( empty( $_GET['bbp_forum_id'] ) ) {
			return $retval;
		}

		$retval = str_replace( "/'>", "/?bbp_forum_id=" . (int) $_GET['bbp_forum_id'] . "'>", $retval );
		$retval = str_replace( '/">', '/?bbp_forum_id=' . (int) $_GET['bbp_forum_id'] . '">', $retval );
		return $retval;
	}

	/**
	 * Add our special forum ID to the forum search URL.
	 *
	 * This so we can filter search results by a specific forum.
	 *
	 * @param string $retval Existing search results URL
	 * @return string
	 */
	public function modify_search_results_url( $retval = '' ) {
		if ( empty( $_GET['bbp_search_forum_id'] ) ) {
			return $retval;
		}

		return add_query_arg( 'bbp_forum_id', (int) $_GET['bbp_search_forum_id'], $retval );
	}

	/**
	 * Alter page title when on the search page and filtering a specific forum.
	 *
	 * @param string $retval Existing search title
	 * @param string $search_terms Existing search terms
	 * @return string
	 */
	public function modify_search_title( $retval = '', $search_terms = '' ) {
		if ( empty( $_GET['bbp_forum_id'] ) ) {
			return $retval;
		}

		$forum_id = (int) $_GET['bbp_forum_id'];

		// don't show this in the breadcrumb
		remove_filter( 'bbp_get_search_title', array( $this, 'modify_search_title' ), 10, 2 );

		return sprintf( esc_html__( "Search results for '%s' from the forum %s", 'bbp-single-forum-search' ), esc_attr( $search_terms ), '<a href="' . bbp_get_forum_permalink( $forum_id ) .'">' . bbp_get_forum_title( $forum_id ) . '</a>' );
	}
}
endif;

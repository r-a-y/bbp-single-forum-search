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
	 * Marker to determine if a user has access to a forum.
	 *
	 * @var null|bool
	 */
	public static $has_access = null;

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
		add_action( 'get_template_part_content',           array( $this, 'modify_search_results' ), 10, 2 );

		add_filter( 'bbp_after_has_search_results_parse_args' , array( $this, 'modify_search_results_query' ) );
		add_filter( 'bbp_get_search_pagination_links',          array( $this, 'modify_search_pagination_links' ) );
		add_filter( 'bbp_get_search_results_url',               array( $this, 'modify_search_results_url' ) );
		add_filter( 'bbp_get_search_title',                     array( $this, 'modify_search_title' ), 10, 2 );

		// Hook for plugin devs!
		do_action( 'bbp_single_forum_search_loaded', $this );

		// BuddyPress integration
		if ( function_exists( 'buddypress' ) ) {
			require dirname( __FILE__ ) . '/buddypress.php';
			bbP_BP_Single_Forum_Search::init();
		}
	}

	/**
	 * Add search form to single forum index pages.
	 */
	public function add_search_form() {
		if ( ! did_action( 'bbp_template_before_single_forum' ) ) {
			return;
		}
		remove_action( 'bbp_template_before_pagination_loop', array( $this, 'add_search_form' ) );

		self::output_search_form();
	}

	/**
	 * Output search form.
	 */
	public static function output_search_form( $forum_id = 0 ){
		if ( empty( $forum_id ) ) {
			$forum_id = bbp_get_forum_id();
		}

		// object buffer the search form template part so we can modify it later
		ob_start();
		bbp_get_template_part( 'form', 'search' );
		$form = ob_get_contents();
		ob_end_clean();

		// inject our custom hidden input field into the search form
		$input = '';
		if ( ! empty( $forum_id ) ) {
			$input = '<input type="hidden" name="bbp_search_forum_id" value="' . (int) $forum_id . '" />';
			$form = str_replace( '</form>', $input . '</form>', $form );

			$placeholder = esc_attr__( 'Search Forum Posts...', 'bbp-single-forum-search' );
			$form = str_replace( 'id="bbp_search"', 'id="bbp_search" placeholder="' . $placeholder . '"', $form );
		}

		// output the search form
		printf( '<div class="bbp-search-form">%s</div>', $form );
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

			<?php if ( function_exists( 'buddypress' ) && bp_is_action_variable( bbP_BP_Single_Forum_Search::$slug ) ) : ?>
				body.groups.forum .bbp-pagination {clear:both; width:100%;}
			<?php endif; ?>
		</style>

	<?php
	}

	/**
	 * Static method to see if the current user has access to a specific forum ID.
	 *
	 * @param  int $forum_id The forum ID
	 * @return bool
	 */
	public static function has_access( $forum_id = 0 ) {
		if ( false === is_null( self::$has_access ) ) {
			return self::$has_access;
		}

		$access = true;
		$group  = false;

		// BuddyPress group forum
		if ( function_exists( 'bbp_get_forum_group_ids' ) ) {
			$group_id = bbp_get_forum_group_ids( $forum_id );
			if ( ! empty( $group_id ) ) {
				$group = groups_get_group( array(
					'group_id' => $group_id[0]
				) );

				if ( false === bp_group_is_visible( $group ) ) {
					$access = false;
				}

				if ( 'public' !== $group->status && true === $access && is_user_logged_in() ) {
					add_filter( 'map_meta_cap', array( __CLASS__, 'allow_read_forums' ), 10, 4 );
					add_action( 'loop_start', array( __CLASS__, 'remove_read_forums_cap' ) );
				}
			}
		}

		if ( empty( $group ) ) {
			// Forum is private and user cannot access
			if ( $access && bbp_is_forum_private( $forum_id ) && ! current_user_can( 'read_private_forums' ) ) {
				$access = false;
			}

			// Forum is hidden and user cannot access
			if ( $access && bbp_is_forum_hidden( $forum_id ) && ! current_user_can( 'read_hidden_forums' ) ) {
				$access = false;
			}
		}

		self::$has_access = $access;

		return $access;
	}

	/*
	 * Filter the forum search query to search for a specific forum.
	 *
	 * @param array $r Existing search query args
	 * @return array
	 */
	public function modify_search_results_query( $r ){
		//Get the submitted forum ID (from the hidden field added in step 2)
		if ( empty( $_GET['bbp_forum_id'] ) ) {
			$forum_id = (int) get_query_var( 'bbp_forum_id' );
		} else {
			$forum_id = (int) $_GET['bbp_forum_id'];
		}

		if ( empty( $forum_id ) ) {
			return $r;
		}

		// Current user does not have access, so force no results query
		if ( false === self::has_access( $forum_id ) ) {
			$r['post__in'] = array( 0 );
			return $r;
		}

		// If the forum ID exists, filter the query
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
	 * Allow users to read private and hidden forums.
	 *
	 * @param array  $caps    Returns the user's actual capabilities.
	 * @param string $cap     Capability name.
	 * @param int    $user_id The user ID.
	 * @param array  $args    Adds the context to the cap. Typically the object ID.
	 * @return array
	 */
	public static function allow_read_forums( $caps, $cap, $user_id, $args ) {
		switch ( $cap ) {
			case 'read_hidden_forums' :
			case 'read_private_forums' :
			//case 'read_private_multiple_post_types' :
				break;

			default :
				return $caps;
				break;
		}

		return array( 'exist' );
	}

	/**
	 * Remove our additional read caps on the 'loop_start' hook.
	 */
	public static function remove_read_forums_cap() {
		remove_filter( 'map_meta_cap', array( __CLASS__, 'allow_read_forums' ), 10, 4 );
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

		// Do not filter title if user does not have access to the forum
		if ( false === self::has_access( $forum_id ) ) {
			return $retval;
		}

		// don't show this in the breadcrumb
		remove_filter( 'bbp_get_search_title', array( $this, 'modify_search_title' ), 10, 2 );

		return sprintf( esc_html__( "Search results for '%s' from the forum %s", 'bbp-single-forum-search' ), esc_attr( $search_terms ), '<a href="' . bbp_get_forum_permalink( $forum_id ) .'">' . bbp_get_forum_title( $forum_id ) . '</a>' );
	}

	/**
	 * Search results modifications.
	 *
	 * Specifically:
	 *   - Adds a search form above the search results
	 *   - Adds a search results header for BuddyPress forum search results
	 *   - Modifies search breadcrumb to remove redundant information
	 *
	 * @param string $slug Template part slug
	 * @param string $name Template part name
	 */
	public function modify_search_results( $slug, $name ) {
		if ( 'search' !== $name ) {
			return;
		}

		// Add search results header on BuddyPress forum page.
		if ( function_exists( 'bp_is_group' ) && bp_is_group() ) {
			$forum_id = get_query_var( 'bbp_forum_id' );

			$header = sprintf( esc_html__( 'Search results for "%s"', 'bbp-single-forum-search' ), esc_attr( bbp_get_search_terms() ) );

			printf( '<h3 class="search-results-header">%s</h3>', $header );

		} elseif ( ! empty( $_GET['bbp_forum_id'] ) ) {
			$forum_id = $_GET['bbp_forum_id'];
		}

		// Add search form on search results page.
		if ( ! empty( $forum_id ) ) {
			self::output_search_form( $forum_id );
		}

		// Modify bbPress breadcrumb.
		$breadcrumb = function( $r ) {
			// Remove "Search results for X" as it's redundant.
			unset( $r[3] );

			return $r;
		};

		add_filter( 'bbp_breadcrumbs', $breadcrumb );
	}
}
endif;

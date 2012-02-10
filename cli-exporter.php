<?php
/**
 * CLI Version of the WordPress Exporter
 * Author: Thorsten Ott
 * Author URI: http://hitchhackerguide.com
 * Version: 0.1
 */
define( 'WP_DEBUG', true );
define( 'SAVEQUERIES', false );

set_time_limit( 0 );
ini_set( 'memory_limit', '512m' );

if ( empty( $_SERVER['HTTP_HOST'] ) )
	$_SERVER['HTTP_HOST'] = 'wp_trunk'; // set this to your main blog_address
if ( empty( $_SERVER['HTTP_HOST'] ) )
	die( 'You need to the default HTTP_HOST in line ' . ( __LINE__ - 2 ) . "\n" );
	
$wordpress_root_dir = dirname( __FILE__ ); // set this to the root directory of your WordPress install that holds wp-load.php
if ( !file_exists( $wordpress_root_dir . '/wp-load.php' ) )
	die( 'You need to the $wordpress_root_dir in line ' . ( __LINE__ - 2 ) . "\n" );

ob_start();
require_once $wordpress_root_dir . '/wp-load.php'; // you need to adjust this to your path
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once ABSPATH . 'wp-admin/includes/export.php';
ob_end_clean();


class WordPress_CLI_Export {
	public $args;
	private $validate_args = array();
	private $required_args = array();
	public $debug_mode = true;

	// Import Vars
	public $wxr_path = '';
	public $blog_id = 0;
	public $user_id = 0;
	public $blog_address = 0;

	public $export_args = array();
	
	public function __construct() {
		$this->args = $this->get_cli_arguments();
	}

	public function init() {
		if ( !$this->validate_args() ) {
			$this->debug_msg( "Problems with arguments" );
			exit;
		}
		$this->dispatch();
	}

	public function dispatch() {
		$this->debug_msg( "Starting Export" );

		$this->wxr_path = $this->args->path;
		$this->export_wp( $this->export_args );
	}

	public function stop_the_insanity() {
		global $wpdb, $wp_object_cache;
		$wpdb->queries = array(); // or define( 'WP_IMPORTING', true );
		if ( !is_object( $wp_object_cache ) )
			return;
		$wp_object_cache->group_ops = array();
		$wp_object_cache->stats = array();
		$wp_object_cache->memcache_debug = array();
		$wp_object_cache->cache = array();
		$wp_object_cache->__remoteset(); // important
	}

	public function debug_msg( $msg ) {
		$msg = date( "Y-m-d H:i:s : " ) . $msg;
		if ( $this->debug_mode )
			echo $msg . "\n";
		else
			error_log( $msg );
	}

	public function set_required_arg( $name, $description='' ) {
		$this->required_args[$name] = $description;
	}

	public function set_argument_validation( $name_match, $value_match, $description='argument validation error' ) {
		$this->validate_args[] = array( 'name_match' => $name_match, 'value_match' => $value_match, 'description' => $description );
	}


	private function validate_args() {
		$result = true;
		$this->debug_msg( "Validating arguments" );
		if ( empty( $_SERVER['argv'][1] ) && !empty( $this->required_args ) ) {
			$this->show_help();
			$result = false;
		} else {
			foreach ( $this->required_args as $name => $description ) {
				if ( !isset( $this->args->$name ) ) {
					$this->raise_required_argument_error( $name, $description );
					$result = false;
				}
			}
		}
		foreach ( $this->validate_args as $validator ) {
			foreach ( $this->args as $name => $value ) {
				$name_match_result = preg_match( $validator['name_match'], $name );
				if ( ! $name_match_result ) {
					continue;
				} else {
					$value_match_result = $this->dispatch_argument_validator( $validator['value_match'], $value );
					if ( ! $value_match_result ) {
						$this->raise_argument_error( $name, $value, $validator );
						$result = false;
						continue;
					}
				}
			}
		}

		return $result;
	}

	private function dispatch_argument_validator( $match, $value ) {
		$match_result = false;
		if ( is_callable( array( &$this, $match ) ) ) {
			$_match_result = call_user_func( array( &$this, $match ), $value );
		} else if ( is_callable( $match ) ) {
				$_match_result = call_user_func( $match, $value );
			} else {
			$_match_result = preg_match( $match, $value );
		}
		return $_match_result;
	}

	private function raise_argument_error( $name, $value, $validator ) {
		printf( "Validation of %s with value %s failed: %s\n", $name, $value, $validator['description'] );
	}

	private function raise_required_argument_error( $name, $description ) {
		printf( "Argument --%s is required: %s\n", $name, $description );
	}

	private function show_help() {
		$example = "php " . __FILE__ . " --blog=blogname --path=/tmp/ --user=admin [--start_date=2011-01-01] --end_date=2011-12-31] [--post_type=post] [--author=admin] [--category=Uncategorized] [--post_status=publish] [--skip_comments=1] [--file_item_count=1000]";
		printf( "Please call the script with the following arguments: \n%s\n", $example );
		foreach ( $this->required_args as $name => $description )
			$msg .= $this->raise_required_argument_error( $name, $description );

	}

	private function cli_init_blog( $blog ) {
		if ( is_multisite() ) {
			if ( is_numeric( $blog ) ) {
				$blog_address = get_blogaddress_by_id( (int) $blog );
			} else {
				$blog_address = get_blogaddress_by_name( $blog );
			}
			if ( $blog_address == 'http://' || strstr( $blog_address, 'wordpress.com.wordpress.com' ) ) {
				$this->debug_msg( sprintf( "the blog_address received from %s looks weird: %s", $blog, $blog_address ) );
				return false;
			}
			$blog_address = str_replace( 'http://', '', $blog_address );
			$blog_address = preg_replace( '#/$#', '', $blog_address );
			$blog_id = get_blog_id_from_url( $blog_address );
		} else {
			$blog_id = 1;
		}

		$home_url = str_replace( 'http://', '', get_home_url( $blog_id ) );
		$home_url = preg_replace( '#/$#', '', $home_url );
		$this->blog_address = $home_url;

		if ( $blog_id > 0 ) {
			$this->debug_msg( sprintf( "the blog_address we found is %s (%d)", $this->blog_address, $blog_id ) );
			$this->args->blog = $blog_id;
			if ( function_exists( 'is_multisite' ) && is_multisite() )
				switch_to_blog( (int) $blog_id );
			$this->blog_id = (int) $blog_id;
			return true;
		} else {
			$this->debug_msg( sprintf( "could not get a blog_id for this address: %s", var_export( $blog_id, true ) ) );
			die();
		}
	}

	private function cli_set_user( $user_id ) {
		if ( is_numeric( $user_id ) ) {
			$user_id = (int) $user_id;
		} else {
			$user_id = (int) username_exists( $user_id );
		}
		if ( !$user_id || !wp_set_current_user( $user_id ) ) {
			$this->debug_msg( sprintf( "could not get a user_id for this user: %s", var_export( $user_id, true ) ) );
			die();
		}

		$current_user = wp_get_current_user();
		$this->user_id = (int) $user_id;
		return $user_id;
	}

	private function get_cli_arguments() {
		$_ARG = new StdClass;
		$argv = $_SERVER['argv'];
		array_shift( $argv );
		foreach ( $argv as $arg ) {
			if ( preg_match( '#--([^=]+)=(.*)#', $arg, $reg ) )
				$_ARG->$reg[1] = $reg[2];
			elseif ( preg_match( '#-([a-zA-Z0-9])#', $arg, $reg ) )
				$_ARG->$reg[1] = 'true';
		}
		return $_ARG;
	}

	private function export_wp( $args = array() ) {
		global $wpdb, $post;
		// call export_wp as we need the functions defined in it.
		$dummy_args = array( 'content' => 'i-do-not-exist' );
		ob_start();
		export_wp( $dummy_args );
		ob_end_clean();
		// now we can use the functions we need.
		$this->debug_msg( "Initialized all functions we need" );
		
		
		/**
		 * This is mostly the original code of export_wp defined in wp-admin/includes/export.php
		 */
		$defaults = array( 'content' => 'all', 'author' => false, 'category' => false,
			'start_date' => false, 'end_date' => false, 'status' => false, 'skip_comments' => false, 'file_item_count' => 1000,
		);
		$args = wp_parse_args( $args, $defaults );

		$this->debug_msg( "Exporting with export_wp with arguments: " . var_export( $args, true ) );
		
		do_action( 'export_wp' );

		if ( 'all' != $args['content'] && post_type_exists( $args['content'] ) ) {
			$ptype = get_post_type_object( $args['content'] );
			if ( ! $ptype->can_export )
				$args['content'] = 'post';

			$where = $wpdb->prepare( "{$wpdb->posts}.post_type = %s", $args['content'] );
		} else {
			$post_types = get_post_types( array( 'can_export' => true ) );
			$esses = array_fill( 0, count( $post_types ), '%s' );
			$where = $wpdb->prepare( "{$wpdb->posts}.post_type IN (" . implode( ',', $esses ) . ')', $post_types );
		}

		if ( $args['status'] && ( 'post' == $args['content'] || 'page' == $args['content'] ) )
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_status = %s", $args['status'] );
		else
			$where .= " AND {$wpdb->posts}.post_status != 'auto-draft'";

		$join = '';
		if ( $args['category'] && 'post' == $args['content'] ) {
			if ( $term = term_exists( $args['category'], 'category' ) ) {
				$join = "INNER JOIN {$wpdb->term_relationships} ON ({$wpdb->posts}.ID = {$wpdb->term_relationships}.object_id)";
				$where .= $wpdb->prepare( " AND {$wpdb->term_relationships}.term_taxonomy_id = %d", $term['term_taxonomy_id'] );
			}
		}

		if ( 'post' == $args['content'] || 'page' == $args['content'] ) {
			if ( $args['author'] )
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_author = %d", $args['author'] );

		}

		if ( $args['start_date'] )
				$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_date >= %s", date( 'Y-m-d 00:00:00', strtotime( $args['start_date'] ) ) );

		if ( $args['end_date'] )
			$where .= $wpdb->prepare( " AND {$wpdb->posts}.post_date <= %s", date( 'Y-m-d 23:59:59', strtotime( $args['end_date'] ) ) );

		// grab a snapshot of post IDs, just in case it changes during the export
		$all_the_post_ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} $join WHERE $where" );

		// get the requested terms ready, empty unless posts filtered by category or all content
		$cats = $tags = $terms = array();
		if ( isset( $term ) && $term ) {
			$cat = get_term( $term['term_id'], 'category' );
			$cats = array( $cat->term_id => $cat );
			unset( $term, $cat );
		} else if ( 'all' == $args['content'] ) {
				$categories = (array) get_categories( array( 'get' => 'all' ) );
				$tags = (array) get_tags( array( 'get' => 'all' ) );

				$custom_taxonomies = get_taxonomies( array( '_builtin' => false ) );
				$custom_terms = (array) get_terms( $custom_taxonomies, array( 'get' => 'all' ) );

				// put categories in order with no child going before its parent
				while ( $cat = array_shift( $categories ) ) {
					if ( $cat->parent == 0 || isset( $cats[$cat->parent] ) )
						$cats[$cat->term_id] = $cat;
					else
						$categories[] = $cat;
				}

				// put terms in order with no child going before its parent
				while ( $t = array_shift( $custom_terms ) ) {
					if ( $t->parent == 0 || isset( $terms[$t->parent] ) )
						$terms[$t->term_id] = $t;
					else
						$custom_terms[] = $t;
				}

				unset( $categories, $custom_taxonomies, $custom_terms );
			}

		$this->debug_msg( 'Exporting ' . count( $all_the_post_ids ) . ' items to be broken into ' . ceil( count( $all_the_post_ids ) / $args['file_item_count'] ) . ' files' );
		$this->debug_msg( 'Exporting ' . count( $cats ) . ' categories' );
		$this->debug_msg( 'Exporting ' . count( $tags ) . ' tags' );
		$this->debug_msg( 'Exporting ' . count( $terms ) . ' terms' );

		$sitename = sanitize_key( get_bloginfo( 'name' ) );
		if ( ! empty( $sitename ) )
			$sitename .= '.';

		$append = array( date( 'Y-m-d' ) );
		foreach( array_keys( $args ) as $arg_key ) {
			if ( $defaults[$arg_key] <> $args[$arg_key] )
				$append[]= "$arg_key-" . (string) $args[$arg_key];
		}
		$file_name_base = $sitename . 'wordpress.' . implode( ".", $append );
		$file_count = 1;
		while ( $post_ids = array_splice( $all_the_post_ids, 0, $args['file_item_count'] ) ) {

			$full_path = trailingslashit( $this->wxr_path ) . $file_name_base . '.' . $file_count . '.wxr';
			
			// Create the file if it doesn't exist
			if ( ! file_exists( $full_path ) ) {
				touch( $full_path );
				$this->debug_msg( 'Created file ' . $full_path );
			}
			
			if ( ! file_exists( $full_path ) ) {
				$this->debug_msg( "Failed to create file " . $full_path );
				exit;
			}

			$this->debug_msg( 'Writing to file ' . $full_path );

			$this->start_export();
			echo '<?xml version="1.0" encoding="' . get_bloginfo( 'charset' ) . "\" ?>\n";

?>
<!-- This is a WordPress eXtended RSS file generated by WordPress as an export of your site. -->
<!-- It contains information about your site's posts, pages, comments, categories, and other content. -->
<!-- You may use this file to transfer that content from one site to another. -->
<!-- This file is not intended to serve as a complete backup of your site. -->

<!-- To import this information into a WordPress site follow these steps: -->
<!-- 1. Log in to that site as an administrator. -->
<!-- 2. Go to Tools: Import in the WordPress admin panel. -->
<!-- 3. Install the "WordPress" importer from the list. -->
<!-- 4. Activate & Run Importer. -->
<!-- 5. Upload this file using the form provided on that page. -->
<!-- 6. You will first be asked to map the authors in this export file to users -->
<!--    on the site. For each author, you may choose to map to an -->
<!--    existing user on the site or to create a new user. -->
<!-- 7. WordPress will then import each of the posts, pages, comments, categories, etc. -->
<!--    contained in this file into your site. -->

<?php the_generator( 'export' ); ?>
<rss version="2.0"
	xmlns:excerpt="http://wordpress.org/export/<?php echo WXR_VERSION; ?>/excerpt/"
	xmlns:content="http://purl.org/rss/1.0/modules/content/"
	xmlns:wfw="http://wellformedweb.org/CommentAPI/"
	xmlns:dc="http://purl.org/dc/elements/1.1/"
	xmlns:wp="http://wordpress.org/export/<?php echo WXR_VERSION; ?>/"
>

<channel>
	<title><?php bloginfo_rss( 'name' ); ?></title>
	<link><?php bloginfo_rss( 'url' ); ?></link>
	<description><?php bloginfo_rss( 'description' ); ?></description>
	<pubDate><?php echo date( 'D, d M Y H:i:s +0000' ); ?></pubDate>
	<language><?php echo get_option( 'rss_language' ); ?></language>
	<wp:wxr_version><?php echo WXR_VERSION; ?></wp:wxr_version>
	<wp:base_site_url><?php echo wxr_site_url(); ?></wp:base_site_url>
	<wp:base_blog_url><?php bloginfo_rss( 'url' ); ?></wp:base_blog_url>

<?php wxr_authors_list(); ?>

<?php foreach ( $cats as $c ) : ?>
	<wp:category><wp:term_id><?php echo $c->term_id ?></wp:term_id><wp:category_nicename><?php echo $c->slug; ?></wp:category_nicename><wp:category_parent><?php echo $c->parent ? $cats[$c->parent]->slug : ''; ?></wp:category_parent><?php wxr_cat_name( $c ); ?><?php wxr_category_description( $c ); ?></wp:category>
<?php endforeach; ?>
<?php foreach ( $tags as $t ) : ?>
	<wp:tag><wp:term_id><?php echo $t->term_id ?></wp:term_id><wp:tag_slug><?php echo $t->slug; ?></wp:tag_slug><?php wxr_tag_name( $t ); ?><?php wxr_tag_description( $t ); ?></wp:tag>
<?php endforeach; ?>
<?php foreach ( $terms as $t ) : ?>
	<wp:term><wp:term_id><?php echo $t->term_id ?></wp:term_id><wp:term_taxonomy><?php echo $t->taxonomy; ?></wp:term_taxonomy><wp:term_slug><?php echo $t->slug; ?></wp:term_slug><wp:term_parent><?php echo $t->parent ? $terms[$t->parent]->slug : ''; ?></wp:term_parent><?php wxr_term_name( $t ); ?><?php wxr_term_description( $t ); ?></wp:term>
<?php endforeach; ?>
<?php if ( 'all' == $args['content'] ) wxr_nav_menu_terms(); ?>

	<?php do_action( 'rss2_head' ); ?>
	<?php
	$this->flush_export( $full_path, false );
	?>
<?php if ( $post_ids ) {
			global $wp_query;
			$wp_query->in_the_loop = true; // Fake being in the loop.

			// fetch 20 posts at a time rather than loading the entire table into memory
			while ( $next_posts = array_splice( $post_ids, 0, 20 ) ) {
				
				$where = 'WHERE ID IN (' . join( ',', $next_posts ) . ')';
				$posts = $wpdb->get_results( "SELECT * FROM {$wpdb->posts} $where" );

				// Begin Loop
				foreach ( $posts as $post ) {
					setup_postdata( $post );
					$is_sticky = is_sticky( $post->ID ) ? 1 : 0;
?>
	<item>
		<title><?php echo apply_filters( 'the_title_rss', $post->post_title ); ?></title>
		<link><?php the_permalink_rss() ?></link>
		<pubDate><?php echo mysql2date( 'D, d M Y H:i:s +0000', get_post_time( 'Y-m-d H:i:s', true ), false ); ?></pubDate>
		<dc:creator><?php echo get_the_author_meta( 'login' ); ?></dc:creator>
		<guid isPermaLink="false"><?php esc_url( the_guid() ); ?></guid>
		<description></description>
		<content:encoded><?php echo wxr_cdata( apply_filters( 'the_content_export', $post->post_content ) ); ?></content:encoded>
		<excerpt:encoded><?php echo wxr_cdata( apply_filters( 'the_excerpt_export', $post->post_excerpt ) ); ?></excerpt:encoded>
		<wp:post_id><?php echo $post->ID; ?></wp:post_id>
		<wp:post_date><?php echo $post->post_date; ?></wp:post_date>
		<wp:post_date_gmt><?php echo $post->post_date_gmt; ?></wp:post_date_gmt>
		<wp:comment_status><?php echo $post->comment_status; ?></wp:comment_status>
		<wp:ping_status><?php echo $post->ping_status; ?></wp:ping_status>
		<wp:post_name><?php echo $post->post_name; ?></wp:post_name>
		<wp:status><?php echo $post->post_status; ?></wp:status>
		<wp:post_parent><?php echo $post->post_parent; ?></wp:post_parent>
		<wp:menu_order><?php echo $post->menu_order; ?></wp:menu_order>
		<wp:post_type><?php echo $post->post_type; ?></wp:post_type>
		<wp:post_password><?php echo $post->post_password; ?></wp:post_password>
		<wp:is_sticky><?php echo $is_sticky; ?></wp:is_sticky>
<?php if ( $post->post_type == 'attachment' ) : ?>
		<wp:attachment_url><?php echo wp_get_attachment_url( $post->ID ); ?></wp:attachment_url>
<?php  endif; ?>
<?php  wxr_post_taxonomy(); ?>
<?php $postmeta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->postmeta WHERE post_id = %d", $post->ID ) );
					foreach ( $postmeta as $meta ) : if ( $meta->meta_key != '_edit_lock' ) : ?>
		<wp:postmeta>
			<wp:meta_key><?php echo $meta->meta_key; ?></wp:meta_key>
			<wp:meta_value><?php echo wxr_cdata( $meta->meta_value ); ?></wp:meta_value>
		</wp:postmeta>
<?php endif; endforeach; ?>
<?php if ( false === $args['skip_comments'] ): ?>
<?php $comments = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->comments WHERE comment_post_ID = %d AND comment_approved <> 'spam'", $post->ID ) );
					foreach ( $comments as $c ) : ?>
		<wp:comment>
			<wp:comment_id><?php echo $c->comment_ID; ?></wp:comment_id>
			<wp:comment_author><?php echo wxr_cdata( $c->comment_author ); ?></wp:comment_author>
			<wp:comment_author_email><?php echo $c->comment_author_email; ?></wp:comment_author_email>
			<wp:comment_author_url><?php echo esc_url_raw( $c->comment_author_url ); ?></wp:comment_author_url>
			<wp:comment_author_IP><?php echo $c->comment_author_IP; ?></wp:comment_author_IP>
			<wp:comment_date><?php echo $c->comment_date; ?></wp:comment_date>
			<wp:comment_date_gmt><?php echo $c->comment_date_gmt; ?></wp:comment_date_gmt>
			<wp:comment_content><?php echo wxr_cdata( $c->comment_content ) ?></wp:comment_content>
			<wp:comment_approved><?php echo $c->comment_approved; ?></wp:comment_approved>
			<wp:comment_type><?php echo $c->comment_type; ?></wp:comment_type>
			<wp:comment_parent><?php echo $c->comment_parent; ?></wp:comment_parent>
			<wp:comment_user_id><?php echo $c->user_id; ?></wp:comment_user_id>
<?php  $c_meta = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $wpdb->commentmeta WHERE comment_id = %d", $c->comment_ID ) );
					foreach ( $c_meta as $meta ) : ?>
			<wp:commentmeta>
				<wp:meta_key><?php echo $meta->meta_key; ?></wp:meta_key>
				<wp:meta_value><?php echo wxr_cdata( $meta->meta_value ); ?></wp:meta_value>
			</wp:commentmeta>
<?php  endforeach; ?>
		</wp:comment>
<?php endforeach; ?>
<?php endif; ?>
	</item>
<?php
				$this->flush_export( $full_path );
				}
			}
		} ?>
</channel>
</rss>
<?php
			$this->flush_export( $full_path );
			$this->end_export();
			$this->stop_the_insanity();
			$file_count++;
		}
		$this->debug_msg( 'All done!' );
	}

	private function start_export() {
		ob_start();
	}

	private function end_export() {
		ob_end_clean();
	}

	private function flush_export( $file_path, $append = true ) {
		$result = ob_get_clean();
		if ( $append )
			$append = FILE_APPEND;
		file_put_contents( $file_path, $result, $append );
		$this->start_export();
	}

	private function check_start_date( $date ) {
		$time = strtotime( $date );
		if ( !empty( $date ) && !$time ) {
			return false;
		}
		$this->export_args['start_date'] = date( 'Y-m-d', $time );
		return true;
	}
	
	private function check_end_date( $date ) {
		$time = strtotime( $date );
		if ( !empty( $date ) && !$time ) {
			return false;
		}
		$this->export_args['end_date'] = date( 'Y-m-d', $time );
		return true;
	}
	
	private function check_post_type( $post_type ) {
		$post_types = get_post_types();
		if ( !in_array( $post_type, $post_types ) ) {
			$this->debug_msg( 'The post type ' . $post_type . ' does not exists. Choose "all" or any of these instead: ' . var_export( $post_types, true ) );
			return false;
		}
		$this->export_args['content'] = $post_type;
		return true;
	}
	
	private function check_author( $author ) {
		$authors = get_users_of_blog();
		if ( empty( $authors ) || is_wp_error( $authors ) ) {
			$this->debug_msg( 'Could not find any authors in this blog' );
			return false;
		}
		$hit = false;
		foreach( $authors as $user ) {
			if ( $hit )
				break;
			if ( (int) $author == $user->ID || $author == $user->user_login )
				$hit = $user->ID;
		}
		if ( false === $hit ) {
			$this->debug_msg( 'Could not find a matching author for ' . $author . '. The following authors exist: ' . var_export( $authors, true ) );
			return false;
		}
		
		$this->export_args['author'] = $hit;
		return true;
	}
	
	private function check_category( $category ) {
		$term = category_exists( $category );
		if ( empty( $term ) || is_wp_error( $term ) ) {
			$this->debug_msg( 'Could not find a category matching ' . $category );
			return false;
		}
		$this->export_args['category'] = $category;
		return true;
	}
	
	private function check_status( $status ) {
		$stati = get_post_statuses();
		if ( empty( $stati ) || is_wp_error( $stati ) ) {
			$this->debug_msg( 'Could not find any post stati' );
			return false;
		}
		
		if ( !isset( $stati[$status] ) ) {
			$this->debug_msg( 'There is no such post_status: ' . $status . '. Here is a list of available stati: ' . var_export( $stati, true ) );
			return false;
		}
		$this->export_args['status'] = $status;
		return true;
	}
	
	private function check_skip_comments( $skip ) {
		if ( (int) $skip <> 0 && (int) $skip <> 1 ) {
			$this->debug_msg( "skip_comments needs to be 0 (no) or 1 (yes)" );
			return false;
		}
		$this->export_args['skip_comments'] = $skip;
		return true;
	}

	private function check_file_item_count( $file_item_count ) {
		$file_item_count = intval( $file_item_count );
		if ( $file_item_count <= 1 ) {
			$this->debug_msg( "file_item_count needs to be a valid integer larger than 1" );
			return false;
		}
		$this->export_args['file_item_count'] = $file_item_count;
		return true;
	}
}

$exporter = new WordPress_CLI_Export;
$exporter->set_required_arg( 'blog', 'Blog ID or name of the blog you like to export' );
$exporter->set_required_arg( 'path', 'Full Path to directory where WXR export files should be stored' );
$exporter->set_required_arg( 'user', 'Username/ID the import should run as' );
$exporter->set_argument_validation( '#^blog$#', 'cli_init_blog', 'blog invalid' );
$exporter->set_argument_validation( '#^user$#', 'cli_set_user', 'user invalid' );
$exporter->set_argument_validation( '#^path$#', 'is_dir', 'path does not exist' );

// optional filters
$exporter->set_argument_validation( '#^start_date$#', 'check_start_date', 'invalid start_date use format YYYY-MM-DD' );
$exporter->set_argument_validation( '#^end_date$#', 'check_end_date', 'invalid end_date use format YYYY-MM-DD' );
$exporter->set_argument_validation( '#^post_type$#', 'check_post_type', 'invalid post_type' );
$exporter->set_argument_validation( '#^author$#', 'check_author', 'invalid author' );
$exporter->set_argument_validation( '#^category$#', 'check_category', 'invalid category' );
$exporter->set_argument_validation( '#^post_status$#', 'check_status', 'invalid status' );
$exporter->set_argument_validation( '#^skip_comments#', 'check_skip_comments', 'please set this value to 0 or 1' );
$exporter->set_argument_validation( '#^file_item_count#', 'check_file_item_count', 'please set this to a valid integer' );

$exporter->init();

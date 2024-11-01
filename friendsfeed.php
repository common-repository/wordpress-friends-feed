<?php
/*
 * @package FriendsFeed

 * Plugin Name: WordPress Friends Feed
 * Plugin URI: http://developex.com/custom-software/wordpress-friends-feed/
 * Description: Read your friends posts in single page of your blog like in LiveJournal.
 * Version: 0.13
 * Author: IT Consulting Company
 * Author URI: http://developex.com/
 * License: GPLv2 or later

***************************************._.***************************************

 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

define('FRIENDSFEED_VERSION', '0.13');
define('FRIENDSFEED_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FRIENDSFEED_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FRIENDSFEED_FULL_UNINSTALL', true);
define('FRIENDSFEED_CATEGORY', 'Contributors');

require_once ABSPATH . WPINC . '/class-feed.php';
require_once ABSPATH . WPINC . '/class-http.php';
require_once ABSPATH . WPINC . '/http.php';


/*******************************
 * INSTALL AND UNINSTALL
 *******************************/

// Register install and uninstall hooks
register_activation_hook(__FILE__, 'friendsfeed_install');
register_deactivation_hook(__FILE__, 'friendsfeed_uninstall');

/*
 * Install plugin callback
 *
 * @global object $wpdb
 */
function friendsfeed_install() {
    global $wp_version;

    $messages = array();

    if (version_compare($wp_version, '3.0', '<')) {
        $messages[] = friendsfeed_version_warning(false);
    }

    if (!friendsfeed_transports_support()) {
        $messages[] = friendsfeed_http_warning(false);
    }

    if (count($messages)) {
        exit(implode($messages));
    }

    // Add options
    $options_prefix = friendsfeed_get_options_prefix();
    $options        = friendsfeed_get_options();
    foreach ($options as $option => $value) {
        add_option($options_prefix . $option, $value);
    }

    // Add friendsfeed page if it does not exists
    $page_id = get_option(friendsfeed_get_options_prefix() . 'page_id');
    if (!$page_id || !get_page($page_id)) {
        global $user_ID;
        $page_id = wp_insert_post(array(
            'post_title'     => 'Friend\'s posts',
            'post_content'   => '',
            'post_status'    => 'publish',
            'post_date'      => date('Y-m-d H:i:s'),
            'post_author'    => $user_ID,
            'post_type'      => 'page',
            'post_category'  => array(0),
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ));

        if ($page_id) {
            // Save page id
            update_option(friendsfeed_get_options_prefix() . 'page_id', $page_id);
        }
    }

    // Enable cron job
    wp_schedule_event(time() + 600, 'friendsfeed', 'friendsfeed_cron');
}

/**
 * Uninstall plugin callback
 *
 * @global object $wpdb
 */
function friendsfeed_uninstall() {
    if (get_option(friendsfeed_get_options_prefix() . 'full_uninstall')) {
        global $wpdb;

        // Deleting posts
        $posts = $wpdb->get_results("SELECT ID FROM ($wpdb->posts) WHERE post_type = 'friendsfeed'", ARRAY_A);
        foreach ($posts as $post) {
            wp_delete_post($post['ID'], true);
        }

        // Deleting page
        $page_id = get_option(friendsfeed_get_options_prefix() . 'page_id');
        if ($page_id) {
            wp_delete_post($page_id, true);
        }

        // TODO: Deleting users/links on full uninstall

        // Deleting options
        $options_prefix = friendsfeed_get_options_prefix();
        $options        = friendsfeed_get_options();
        foreach ($options as $option => $value) {
            delete_option($options_prefix . $option);
        }
    }

    // Disable cron job
    wp_clear_scheduled_hook('friendsfeed_cron');
}

function friendsfeed_version_warning($print = true) {
    $message = '<div class="error"><p><strong>' . sprintf(__('FriendsFeed %s requires WordPress 3.0 or higher.'), FRIENDSFEED_VERSION) . "</strong> " . sprintf(__('Please <a href="%s">upgrade WordPress</a> to a current version.'), 'http://codex.wordpress.org/Upgrading_WordPress') . "</p></div>";

    if (!$print) {
        return $message;
    }

    echo $message;
}

function friendsfeed_http_warning($print = true) {
    $message = '<div class="error"><p><strong>FriendsFeed detected that your site does not support HTTP requests at your current hosting plan. So WordPress Friends Feed will not work.</p></div>';

    if (!$print) {
        return $message;
    }

    echo $message;
}

function friendsfeed_transports_support() {
    $args = array();

    if (class_exists('WP_Http_ExtHttp', false) && true === WP_Http_ExtHttp::test($args)) {
        return true;
    }
    else if (class_exists('WP_Http_Curl', false) && true === WP_Http_Curl::test($args)) {
        return true;
    }
    else if (class_exists('WP_Http_Streams', false) && true === WP_Http_Streams::test($args)) {
        return true;
    }
    else if (class_exists('WP_Http_Fopen', false) && true === WP_Http_Fopen::test($args)) {
        return true;
    }
    else if (class_exists('WP_Http_Fsockopen', false) && true === WP_Http_Fsockopen::test($args)) {
        return true;
    }

    return false;
}


/*******************************
 * OPTIONS
 *******************************/

/**
 * Get list of options with default values
 *
 * @return array
 */
function friendsfeed_get_options() {
    return array(
        'show_in_menu'         => 0,
        'show_adv'             => 1,
        'full_uninstall'       => 0,
        'page_id'              => 0,
        'adv_post_id'          => 0,
        'posts_per_page'       => 10,
        'cron_update_interval' => 60,
        'cleanup_limit'        => 0,
    );
}

/**
 * Get options prefix
 *
 * @return string
 */
function friendsfeed_get_options_prefix() {
    return 'friendsfeed_';
}

/**
 * Validator for option "friendsfeed_posts_per_page"
 *
 * @param mixed $value
 * @return boolean
 */
function friendsfeed_posts_per_page_validate($value) {
    return (is_numeric($value) && $value >= 1);
}

/**
 * Validator for option "friendsfeed_cron_update_interval"
 *
 * @param mixed $value
 * @return boolean
 */
function friendsfeed_cron_update_interval_validate($value) {
    return (is_numeric($value) && $value >= 1);
}

/**
 * Validator for option "friendsfeed_cleanup_limit"
 *
 * @param mixed $value
 * @return boolean
 */
function friendsfeed_cleanup_limit_validate($value) {
    return (is_numeric($value) && $value >= 0);
}


/*******************************
 * PLUGIN INIT
 *******************************/

// Add Init
add_action('init', 'friendsfeed_init');
function friendsfeed_init() {
    // Add friendsfeed post type
    register_post_type(
        'friendsfeed',
        array(
            'labels'              => array(
                'name'               => _x('Friends Posts', 'post type general name'),
                'singular_name'      => _x('Friend Post', 'post type singular name'),
                'add_new'            => _x('Add New', 'friendsfeed'),
                'add_new_item'       => __('Add New Friend Post'),
                'edit_item'          => __('Edit Friend Post'),
                'new_item'           => __('New Friend Post'),
                'view_item'          => __('View Friend Post'),
                'search_items'       => __('Search Friends Posts'),
                'not_found'          => __('No friends posts found'),
                'not_found_in_trash' => __('No friends posts found in Trash'),
                'parent_item_colon'  => '',
                'menu_name'          => 'Friends Posts'
            ),
            'public'              => true,
            'publicly_queryable'  => true,
            'exclude_from_search' => false,
            'show_ui'             => true,
            'show_in_menu'        => 'friendsfeed-manage-page',
            'show_in_nav_menus'   => false,
            'query_var'           => true,
            'rewrite'             => true,
            'capability_type'     => 'post',
            'has_archive'         => true,
            'hierarchical'        => false,
            'menu_position'       => null,
            'supports'            => array(
                'title',
                'editor',
                'author',
                'excerpt',
                'custom-fields',
                'comments',
                'page-attributes',
            ),
        )
    );
}

// Add filter to query
add_filter('parse_query', 'friendsfeed_pre_get_posts');
function friendsfeed_pre_get_posts($query) {
    if (is_friendsfeed_page($query)) {
        $query->is_page                      = false;
        $query->is_archive                   = false;
        $query->is_singular                  = false;
        $query->is_posts_page                = true;
        $query->is_post_type_archive         = true;
        $query->suppress_filters             = false;
        $query->query_vars['post_type']      = 'friendsfeed';
        $query->query_vars['post_status']    = 'publish';
        $query->query_vars['posts_per_page'] = get_option('friendsfeed_posts_per_page', 10);
        $query->query_vars['post__not_in']   = array(get_option(friendsfeed_get_options_prefix() . 'adv_post_id'));
        unset($query->query_vars['page_id'], $query->query_vars['pagename']);
    }
}

// Add filter to posts result
add_filter('the_posts', 'friendsfeed_the_posts', 10, 2);
function friendsfeed_the_posts($posts, $query) {
    if (count($posts) && is_friendsfeed_page($query)) {
        $adv_post = friendsfeed_get_adv(false);
        if ($adv_post) {
            array_unshift($posts, $adv_post);
        }
    }

    return $posts;
}

// Add filter to fix css class of custom menu item
add_filter('wp_get_nav_menu_items', 'friendsfeed_wp_get_nav_menu_items', 10, 3);
function friendsfeed_wp_get_nav_menu_items($items, $menu, $args) {
    if (is_friendsfeed_page()) {
        foreach ($items as &$item) {
            if (is_friendsfeed_menu_item($item)) {
                $item->classes[] = 'current_page_item';
            }
        }
    }

    return $items;
}

// Add filter to fix css class of standart menu item
add_filter('page_css_class', 'friendsfeed_page_css_class', 10, 2);
function friendsfeed_page_css_class($css_class, $page) {
    if (is_friendsfeed_menu_item($page) && is_friendsfeed_page() && !preg_match('/(current_page_ancestor|current_page_parent|current_page_item)/', implode($css_class))) {
        $css_class[] = 'current_page_item';
    }

    return $css_class;
}

// Add filter to hide menu item
add_filter('wp_list_pages_excludes', 'friendsfeed_wp_list_pages_excludes');
function friendsfeed_wp_list_pages_excludes($exclude_array) {
    $page_id = get_option('friendsfeed_page_id');

    if ($page_id && !get_option('friendsfeed_show_in_menu')) {
        $exclude_array[] = $page_id;
    }

    return $exclude_array;
}

// Add filter for post permalink
add_filter('post_type_link', 'friendsfeed_post_type_link', 1, 4);
function friendsfeed_post_type_link($post_link, $post, $leavename, $sample) {
    if ($post->post_type == 'friendsfeed') {
        $link = get_post_meta($post->ID, 'friendsfeed_post_link', true);
        if ($link) {
            $post_link = esc_html($link);
        }
    }

    return $post_link;
}

// Add filter for post author link
add_filter('author_link', 'friendsfeed_author_link', 1, 3);
function friendsfeed_author_link($link, $author_id, $author_nicename) {
    $capabilities = get_user_meta($author_id, 'wp_capabilities', true);
    if (!empty($capabilities['contributor']) && is_friendsfeed_page()) {
        $user_link = get_user_option('user_url', $author_id);
        if ($user_link) {
            $link = esc_html($user_link);
        }
    }

    return $link;
}


/*******************************
 * CRON
 *******************************/

// Add custom cron interval
add_filter('cron_schedules', 'friendsfeed_cron_schedules');
function friendsfeed_cron_schedules() {
    return array(
        'friendsfeed' => array('interval' => get_option(friendsfeed_get_options_prefix() . 'cron_update_interval', 60) * 60, 'display' => __('Friends Feed Interval')),
    );
}

// Add cron task
add_action('friendsfeed_cron', 'friendsfeed_cron_run');
function friendsfeed_cron_run() {
    friendsfeed_get_adv();
    friendsfeed_cron_run_update();
    friendsfeed_cron_run_cleanup();
}

function friendsfeed_cron_run_update() {
    $links = friendsfeed_get_links(array(
        'hide_invisible' => true,
    ));

    foreach ($links as $link) {
        $link->update();
    }

    return count($links);
}

function friendsfeed_cron_run_cleanup() {
    $cleanup_limit = get_option(friendsfeed_get_options_prefix() . 'cleanup_limit', 0);
    if ($cleanup_limit) {
        global $wpdb;

        $adv_post_id = get_option(friendsfeed_get_options_prefix() . 'adv_post_id', 0);
        $posts       = $wpdb->get_results("SELECT ID FROM ($wpdb->posts) WHERE post_type = 'friendsfeed' AND ID != $adv_post_id ORDER BY post_date DESC LIMIT $cleanup_limit, 100000", ARRAY_A);
        foreach ($posts as $post) {
            wp_delete_post($post['ID'], true);
        }
    }
}

// Reshedule cron job on option updating
add_action('update_option_' . friendsfeed_get_options_prefix() . 'cron_update_interval', 'friendsfeed_reschedule_cron', 10, 2);
function friendsfeed_reschedule_cron($oldvalue, $newvalue) {
    if ($oldvalue != $newvalue) {
        if (($next = wp_next_scheduled('friendsfeed_cron'))) {
            wp_unschedule_event($next, 'friendsfeed_cron');
        }

        wp_schedule_event(time(), 'friendsfeed', 'friendsfeed_cron');
    }
}


/*******************************
 * PLUGIN ADMIN PAGES
 *******************************/

if (is_admin()) {
    require_once dirname(__FILE__) . '/admin.php';
}


/*******************************
 * MISC
 *******************************/

function is_friendsfeed_page($query = null) {
    $page_id = get_option('friendsfeed_page_id');
    if (!$page_id) {
        return false;
    }

    static $post_name;
    if (!isset($post_name)) {
        $post_name = $page_id ? get_post_field('post_name', $page_id, 'raw') : false;
    }

    if (!isset($query)) {
        global $wp_query;
        $query = $wp_query;
    }

    return (
        (!empty($query->query['page_id']) && $query->query['page_id'] == $page_id)
        ||
        ($post_name && !empty($query->query['pagename']) && $query->query['pagename'] == $post_name)
    );
}

function is_friendsfeed_menu_item($page) {
    $page_id = get_option('friendsfeed_page_id');

    return ((!empty($page->ID) && $page->ID == $page_id) || (!empty($page->object_id) && $page->object_id == $page_id));
}

/**
 * Check feed link if it already exists in friendsfeed
 *
 * @param string $link
 * @param string $feed_link
 * @return boolean
 */
function friendsfeed_link_exists($feed_link) {
    global $wpdb;

    $unslashed = untrailingslashit($feed_link);
    $slashed   = trailingslashit($feed_link);
    $link_id   = $wpdb->get_var($wpdb->prepare("SELECT link_id FROM {$wpdb->links} WHERE link_rss IN ('%s', '%s') LIMIT 1", $unslashed, $slashed));

    if ($link_id) {
        $links = friendsfeed_get_links(array(
            'include'        => $link_id,
            'hide_invisible' => false,
        ));

        return current($links);
    }

    return false;
}

/**
 * Retrive friendsfeed links
 *
 * @param array $args
 * @return array
 */
function friendsfeed_get_links($args = array()) {
    $links        = array();
    $contributors = friendsfeed_get_links_category();

    if ($contributors) {
        $links = get_bookmarks(array_merge(
            array('category' => $contributors),
            $args
        ));

        foreach ($links as &$link) {
            $link = new FriendsFeedLink($link);
        }

        reset($links);
    }

    return $links;
}

/**
 * Retrive friendsfeed links category id
 *
 * @return integer
 */
function friendsfeed_get_links_category() {
    static $cat_id;

    if (!isset($cat_id)) {
        $term = term_exists(FRIENDSFEED_CATEGORY, 'link_category');

        if (is_array($term)) {
            $cat_id = $term['term_id'];
        }
        else {
            $term = wp_insert_term(FRIENDSFEED_CATEGORY, 'link_category');

            if (is_array($term)) {
                $cat_id = $term['term_id'];
            }
        }
    }

    return $cat_id;
}

/**
 * Retrive user id
 *
 * @return integer
 */
function friendsfeed_insert_new_user($user_name, array $user_data = array()) {
    $user_id = username_exists($user_name);

    // if User does not exists - create new
    if (!$user_id && strlen($user_name) > 0) {
        $user_data['ID']            = NULL;
        $user_data['user_login']    = apply_filters('pre_user_login', sanitize_user($user_name));
        $user_data['user_nicename'] = apply_filters('pre_user_nicename', sanitize_title($user_name));
        $user_data['display_name']  = $user_name;
        $user_data['user_pass']     = substr(md5(uniqid(microtime())), 0, 8);

        if (empty($user_data['user_email'])) {
            $blog_url                = !empty($user_data['user_url']) ? $user_data['user_url'] : get_bloginfo('url');
            $blog_url_parsed         = parse_url($blog_url);
            $user_data['user_email'] = $user_data['user_login'] . '+' . substr(md5(uniqid(microtime())), 0, 8) . '@' . $blog_url_parsed['host'];
        }

        $meta = array();
        if (isset($user_data['meta'])) {
            unset($user_data['meta']);
            $meta = $user_data['meta'];
        }

        $user_id = wp_insert_user($user_data);

        if (is_wp_error($user_id)) {
            return false;
        }

        foreach ($meta as $key => $value) {
            add_user_meta($user_id, $key, $value);
        }
    }

    return $user_id;
}

/**
 * Retrive feeds from page
 *
 * @return array
 */
function friendsfeed_get_feeds_from_page($url) {
    $body  = wp_remote_retrieve_body(wp_remote_get($url));
    $feeds = array();

    if (
        $body
        &&
        preg_match('/<meta name="generator" content="WordPress[^"]*"[^>]*>/i', $body)
        &&
        preg_match_all('/<link[^>]* type="application\/rss\+xml"[^>]*>/', $body, $feeds)
    ) {
        $feeds = $feeds[0];

        foreach ($feeds as $id => &$feed) {
            $info = array('site' => $url);

            if (preg_match('/href="([^"]+)"/', $feed, $matches)) {
                $info['url'] = $matches[1];

                if (preg_match('/title="([^"]+)"/', $feed, $matches)) {
                    $info['title'] = $matches[1];
                }

                $feed = $info;
            }
            else {
                unset($feeds[$id]);
            }
        }
    }

    return $feeds;
}

/**
 * Retrive adv post
 *
 * @return object
 */
function friendsfeed_get_adv($update = true) {
    if (get_option(friendsfeed_get_options_prefix() . 'show_adv')) {
        $adv_post_id = get_option(friendsfeed_get_options_prefix() . 'adv_post_id');

        if (!$update) {
            if (!$adv_post_id) {
                return false;
            }

            $adv_post = get_post($adv_post_id);

            if ($adv_post && !is_wp_error($adv_post)) {
                return $adv_post;
            }

            return false;
        }

        $response = wp_remote_get('http://developex.com/custom-software/wordpress-friends-feed/ads.xml');
        if (preg_match('/^[23]\d\d$/', wp_remote_retrieve_response_code($response))) {
            $response_body = wp_remote_retrieve_body($response);
            if ($response_body && strlen($response_body) > 0) {
                $xml = @simplexml_load_string($response_body);
                if ($xml && $xml->adv) {
                    $title      = (string) $xml->adv->title;
                    $content    = (string) $xml->adv->content;
                    $pubDate    = strtotime((string) $xml->adv->pubDate);
                    $pubDate    = $pubDate > 0 ? date('Y-m-d H:i:s', $pubDate) : date('Y-m-d H:i:s');
                    $author     = (string) $xml->adv->author;
                    $authorLink = (string) $xml->adv->authorLink;
                    $link       = (string) $xml->adv->link;

                    if ($adv_post_id) {
                        global $wpdb;
                        $adv_post_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $adv_post_id));
                    }

                    $user_id = friendsfeed_insert_new_user($author, array(
                        'user_url' => $authorLink,
                        'role'     => 'contributor',
                        'meta'     => array(
                            'friendsfeed_link_id' => 0,
                        ),
                    ));

                    if ($user_id) {
                        $adv_post = array(
                            'post_title'     => $title,
                            'post_content'   => $content,
                            'post_status'    => 'publish',
                            'post_date'      => $pubDate,
                            'post_author'    => $user_id,
                            'post_type'      => 'friendsfeed',
                            'post_category'  => array(0),
                            'comment_status' => 'closed',
                            'ping_status'    => 'closed',
                            'guid'           => $link,
                            'comment_count'  => 0
                        );

                        if (!$adv_post_id) {
                            $adv_post_id = wp_insert_post($adv_post);
                            if ($adv_post_id) {
                                update_option(friendsfeed_get_options_prefix() . 'adv_post_id', $adv_post_id);
                                add_post_meta($adv_post_id, 'friendsfeed_post_link', $link);

                                $adv_post = get_post($adv_post_id);
                                if ($adv_post && !is_wp_error($adv_post)) {
                                    return $adv_post;
                                }
                            }
                        }
                        else {
                            wp_update_post(array_merge($adv_post, array('ID' => $adv_post_id)));
                            update_post_meta($adv_post_id, 'friendsfeed_post_link', $link);

                            $adv_post = get_post($adv_post_id);
                            if ($adv_post && !is_wp_error($adv_post)) {
                                return $adv_post;
                            }
                        }
                    }
                }
            }
        }
    }

    // Fallback. If update is crashes, try to load old adv post.
    return $update ? friendsfeed_get_adv(false) : false;
}

class FriendsFeedLink {
    protected $link;

    protected $settings = array();

    static protected $simplepie = null;

    public function  __construct($link) {
        $this->link = is_object($link)? $link : get_bookmark($link);

        if ($this->valid() && strlen($this->link->link_notes) > 0) {
			$notes = explode("\n", $this->link->link_notes);
			foreach ($notes as $note) {
				$pair  = explode(": ", $note, 2);
				$key   = (isset($pair[0]) ? $pair[0] : null);
				$value = (isset($pair[1]) ? $pair[1] : null);

				if (!is_null($key) && !is_null($value)) {
					$this->settings[$key] = stripcslashes(trim($value));
                }
            }
        }
    }

    protected function getSimplepie() {
        if (!is_null(self::$simplepie)) {
            return self::$simplepie;
        }

        if (class_exists('SimplePie')) {
            self::$simplepie = new SimplePie();
            self::$simplepie->set_cache_class('WP_Feed_Cache');
            self::$simplepie->set_file_class('WP_SimplePie_File');
            self::$simplepie->set_cache_duration(3600);

            return self::$simplepie;
        }

        return new WP_Error(null, 'SimplePie class not founded');
    }

    public function valid() {
        return (is_object($this->link) && !is_wp_error($this->link));
    }

    public function __get($name) {
        if ($this->valid()) {
            if (isset($this->link->$name)) {
                return $this->link->$name;
            }
        }
    }

    public function __set($name, $value) {
        // do nothing
    }

    public function settings($key, $value = null) {
        if (!$this->valid()) {
            return;
        }

        $isset = isset($this->settings[$key]);

        if (!isset($value)) {
            return $isset ? $this->settings[$key] : null;
        }

        if ($value === false) {
            unset($this->settings[$key]);
            return $isset;
        }

        $this->settings[$key] = $value;
    }

    public function save() {
        if (!$this->valid()) {
            return false;
        }

        $this->link->link_notes = '';

        foreach ($this->settings as $key => $value) {
            $this->link->link_notes .= $key . ': ' . $value;
        }

        return !is_wp_error(wp_update_link((array) $this->link));
    }

    public function activate() {
        global $wpdb;

		$wpdb->query($wpdb->prepare("UPDATE {$wpdb->links} SET link_visible = 'Y' WHERE link_id = %d", (int) $this->link->link_id));
    }

    public function deactivate() {
        global $wpdb;

		$wpdb->query($wpdb->prepare("UPDATE {$wpdb->links} SET link_visible = 'N' WHERE link_id = %d", (int) $this->link->link_id));
    }

    public function status() {
        if ($this->link->link_visible == 'N') {
            return 'Inactive';
        }
        else {
            return 'Active';
        }
    }

    public function control() {
        $links  = array();
        $status = $this->status();

        switch ($status) {
            case 'Active':
                $links[] = '<a href="?action=status&link=' . $this->link->link_id . '&page=friendsfeed-manage-page">Disactivate</a>';
                $links[] = '<a href="?action=update&link=' . $this->link->link_id . '&page=friendsfeed-manage-page">Update</a>';
                break;

            case 'Inactive':
                $links[] = '<a href="?action=status&link=' . $this->link->link_id . '&page=friendsfeed-manage-page">Activate</a>';
                break;
        }


        $links[] = '<a href="link.php?action=edit&link_id=' . $this->link->link_id . '">Edit</a>';
        $links[] = '<a href="?action=delete&link=' . $this->link->link_id . '&page=friendsfeed-manage-page" style="color:red;">Delete</a>';

        return implode('&nbsp;|&nbsp;', $links);
    }

    public function update() {
        global $wpdb;

        if (!$this->valid()) {
            return new WP_Error('friendsfeed-error', 'Link is not valid');
        }

        $simplepie = $this->getSimplepie();

        if (is_wp_error($simplepie)) {
            return $simplepie->get_error_message();
        }

        $simplepie->set_feed_url($this->link->link_rss);
        $simplepie->init();
        $simplepie->handle_content_type();

        if ($simplepie->error()) {
            return new WP_Error('simplepie-error', $feed->error());
        }

        if ($simplepie->get_item_quantity() && isset($simplepie->data['child']['']['rss'][0]['child']['']['channel'][0]['child']['']['item'])) {
            $items = $simplepie->data['child']['']['rss'][0]['child']['']['channel'][0]['child']['']['item'];
            foreach ($items as $id => &$item) {
                $item         = $item['child'];
                $guid         = $item['']['guid'][0]['data'];
                $title        = $item['']['title'][0]['data'];
                $excerpt      = $item['']['description'][0]['data'];
                $content      = !empty($item['http://purl.org/rss/1.0/modules/content/']['encoded'][0]['data']) ? ($excerpt ? $excerpt . '<!--more-->' . $item['http://purl.org/rss/1.0/modules/content/']['encoded'][0]['data'] : $item['http://purl.org/rss/1.0/modules/content/']['encoded'][0]['data']) : $excerpt;
                $site         = $this->link->link_url;
                $site_parsed  = parse_url($site);
                $host         = $site_parsed['host'];
                $author       = !empty($item['http://purl.org/dc/elements/1.1/']['creator'][0]['data']) ? $item['http://purl.org/dc/elements/1.1/']['creator'][0]['data'] : $host;
                $post_link    = $item['']['link'][0]['data'];
                $coments      = (int) $item['http://purl.org/rss/1.0/modules/slash/']['comments'][0]['data'];
                $coments_link = $item['']['comments'][0]['data'];
                $publish_date = strtotime($item['']['pubDate'][0]['data']);
                $publish_date = !$publish_date ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', $publish_date);

                if ($guid && $title && $content && $post_link) {
                    $post_id = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE guid = '%s' LIMIT 1", $guid));

                    if (!$post_id) {
                        $user_id = friendsfeed_insert_new_user($author, array(
                            'user_url' => $site,
                            'role'     => 'contributor',
                            'meta'     => array(
                                'friendsfeed_link_id' => $this->link->link_id,
                            ),
                        ));

                        if ($user_id) {
                            $page_id = wp_insert_post(array(
                                'post_title'     => $title,
                                'post_content'   => $content,
                                //'post_excerpt'   => $excerpt,
                                'post_status'    => 'publish',
                                'post_date'      => $publish_date,
                                'post_author'    => $user_id,
                                'post_type'      => 'friendsfeed',
                                'post_category'  => array(0),
                                'comment_status' => $coments_link ? 'open' : 'closed',
                                'ping_status'    => 'closed',
                                'guid'           => $guid,
                                'comment_count'  => $coments
                            ));

                            if ($page_id) {
                                add_post_meta($page_id, 'friendsfeed_link_id', $this->link->link_id);
                                add_post_meta($page_id, 'friendsfeed_post_link', $post_link);
                                add_post_meta($page_id, 'friendsfeed_comments_link', $coments_link);
                            }
                        }
                    }
                }
            }
        }

        return true;
    }
}

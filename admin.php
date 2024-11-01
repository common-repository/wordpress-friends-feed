<?php

/*******************************
 * MENU
 *******************************/

// Add admin menus
add_action('admin_menu', 'friendsfeed_add_pages');
function friendsfeed_add_pages() {
    add_menu_page(__('Friends Feed'), __('Friends Feed'), 'manage_options', 'friendsfeed-manage-page', 'friendsfeed_manage_page');
    add_submenu_page('friendsfeed-manage-page', __('Friends Feeds'), __('Friends Feeds'), 'manage_options', 'friendsfeed-manage-page', 'friendsfeed_manage_page');
    add_submenu_page('friendsfeed-manage-page', __('Settings'), __('Settings'), 'manage_options', 'friendsfeed-settings-page', 'friendsfeed_settings_page');
    add_submenu_page('friendsfeed-manage-page', __('Add Friend Feed'), '', 'manage_options', 'friendsfeed-bookmarklet-page', 'friendsfeed_bookmarklet_page');
}

/*******************************
 * ADMIN INIT
 *******************************/

// Add Init
add_action('admin_init', 'friendsfeed_admin_init');
function friendsfeed_admin_init() {
    global $wp_version;

    if (version_compare($wp_version, '3.0', '<')) {
        add_action('admin_notices', 'friendsfeed_version_warning', 10, 0);
    }

    if (!friendsfeed_transports_support()) {
        add_action('admin_notices', 'friendsfeed_http_warning', 10, 0);
    }
}

/*******************************
 * PAGES
 *******************************/

/**
 * Page callback
 *
 * Edit plugin's settings
 */
function friendsfeed_settings_page() {
    // Variables for the field and option names
    $options_prefix = friendsfeed_get_options_prefix();
    $options_inputs      = array(
        'posts_per_page'       => 'friendsfeed_posts_per_page_validate',
        'cron_update_interval' => 'friendsfeed_cron_update_interval_validate',
        'cleanup_limit'        => 'friendsfeed_cleanup_limit_validate',
    );
    $options_checkboxes = array(
        'show_in_menu',
        'show_adv',
        'full_uninstall',
    );

    foreach ($options_inputs as $option => $validator) {
        // Get current value
        $$option = get_option($options_prefix . $option);
    }

    foreach ($options_checkboxes as $option) {
        // Get current value
        $$option  = (integer) get_option($options_prefix . $option);
    }

    // See if the user has posted us some information
    if (isset($_POST['submit'])) {
        foreach ($options_inputs as $option => $validator) {
            if (
                isset($_POST[$option])
                &&
                (true === $validator || call_user_func($validator, $_POST[$option]))
                &&
                $$option != $_POST[$option]
            ) {
                $$option = $_POST[$option];
                update_option($options_prefix . $option, $$option);
            }
        }

        foreach ($options_checkboxes as $option) {
            $newvalue = (integer) isset($_POST[$option]);

            if (
                $$option != $newvalue
            ) {
                $$option = $newvalue;

                update_option($options_prefix . $option, $$option);
            }
        }

        // Put an settings updated message on the screen
        ?>
        <div class="updated"><p><strong><?php _e('Settings saved.'); ?></strong></p></div>
        <?php
    }
    // Settings form
    ?>
    <div class="wrap">
        <h2><?php _e('Settings') ?></h2>
        <form name="form" method="post" action="">
            <table style="width:100%;">
                <tr>
                    <td><?php _e("Show menu link in default primary navigation:"); ?></td>
                    <td><input type="checkbox" name="show_in_menu"<?php echo $show_in_menu ? ' checked' : ''; ?>></td>
                </tr>
                <tr>
                    <td><?php _e("Show ad post:"); ?></td>
                    <td><input type="checkbox" name="show_adv"<?php echo $show_adv ? ' checked' : ''; ?>></td>
                </tr>
                <tr>
                    <td><?php _e("Posts per page:"); ?></td>
                    <td><input type="text" name="posts_per_page" value="<?php echo $posts_per_page; ?>" size="2" maxlength="2"></td>
                </tr>
                <tr>
                    <td><?php _e("Cron update interval, minutes:"); ?></td>
                    <td><input type="text" name="cron_update_interval" value="<?php echo $cron_update_interval; ?>" size="4" maxlength="4"></td>
                </tr>
                <tr>
                    <td><?php _e("Posts limit:"); ?></td>
                    <td><input type="text" name="cleanup_limit" value="<?php echo $cleanup_limit; ?>" size="6" maxlength="6"> <?php _e("set to 0 if no limit"); ?></td>
                </tr>
                <tr>
                    <td><?php _e("Full uninstall on plugin deactivation:"); ?></td>
                    <td><input type="checkbox" name="full_uninstall"<?php echo $full_uninstall ? ' checked' : ''; ?>></td>
                </tr>
            </table>
            <hr />
            <p class="submit">
                <input type="submit" name="submit" class="button-primary" value="<?php esc_attr_e('Save Changes') ?>" />
            </p>
        </form>
    </div>
    <?php
}

/**
 * Page callback
 *
 * Manage plugin for add/edit/delete friends feed
 */
function friendsfeed_manage_page() {
    $errors = $messages = $feeds = array();

    wp_reset_vars(array('action', 'link', 'feed_url', 'feed_title'));
    global $action, $link, $feed_url, $feed_title;

    // Add new feed
    if ($action == 'feedsfinder') {
        if (!$link) {
            $errors[] = __('Error: please enter a valid url');
        }
        else {
            $feeds = friendsfeed_get_feeds_from_page($link);

            if (!count($feeds)) {
                $errors[] = __('Error: feeds not found at this page');
            }
            else {
                foreach ($feeds as &$feed) {
                    $existed = friendsfeed_link_exists($feed['url']);

                    if ($existed) {
                        $feed['feed'] = $existed;
                    }
                }
                unset($feed);
            }
        }
    }
    else if ($action == 'add') {
        if (!$feed_url) {
            $errors[] = __('Error: please enter a valid url');
        }
        else {
            $linkdata = array(
                'link_name'     => $feed_title,
                'link_url'      => $link,
                'link_rss'      => $feed_url,
                'link_category' => friendsfeed_get_links_category(),
            );

            $link_id = wp_insert_link($linkdata, true);

            if (!is_wp_error($link_id)) {
                $messages[] = __('Link was added');
                $feed = friendsfeed_get_links(array(
                    'include' => $link_id,
                ));
                if ($feed) {
                    $feed[0]->update();
                }
            }
            else {
                $errors[] = __('Error: Link not added');
            }
        }
    }
    // Update selected feed
    else if ($action == 'update') {
        friendsfeed_get_adv();
        $links = friendsfeed_get_links(array(
            'include'        => $link,
            'hide_invisible' => false,
        ));

        if ($links && count($links)) {
            $update = $links[0]->update();
            if (!is_wp_error($update)) {
                $messages[] = __('Link was updated');
            }
            else {
                $errors[] = __('Error: ' . $update->get_error_message());
            }
        }
        else {
            $errors[] = __('Error: Link not found');
        }
    }
    // Update all feed
    else if ($action == 'update_all') {
        friendsfeed_get_adv();
        $updated_links = friendsfeed_cron_run_update();

        if ($updated_links) {
            $messages[] = __('All links was updated');
        }
        else {
            $messages[] = __('No links');
        }
    }
    // Delete selected feed
    else if ($action == 'delete') {
        $links = friendsfeed_get_links(array(
            'include'        => $link,
            'hide_invisible' => false,
        ));

        if ($links && count($links)) {
            wp_delete_link($link);
            $messages[] = __('Link was deleted');
        }
        else {
            $errors[] = __('Error: Link not found');
        }
    }
    // Change status for selected feed
    else if ($action == 'status') {
        $links = friendsfeed_get_links(array(
            'include'        => $link,
            'hide_invisible' => false,
        ));

        if ($links && count($links)) {
            $status = $links[0]->status();
            switch ($status) {
                case 'Active';
                    $links[0]->deactivate();
                    $messages[] = __('Link was deactivated');
                    break;

                case 'Inactive';
                    $links[0]->activate();
                    $messages[] = __('Link was activated');
                    break;
            }
        }
        else {
            $errors[] = __('Error: Link not found');
        }
    }

    $links = friendsfeed_get_links(array(
        'hide_invisible' => false,
    ));
    ?>
    <div class="wrap">
        <h2><?php _e('Your Friends Feeds') ?></h2>
        <?php if (count($errors)) : ?>
            <div class="error"><ul><li><?php echo implode('</li><li>', $errors) ?></li></ul></div>
        <?php endif; ?>
        <?php if (count($messages)) : ?>
            <div class="updated"><ul><li><?php echo implode('</li><li>', $messages) ?></li></ul></div>
        <?php endif; ?>
        <?php if (!count($feeds)) : ?>
        <form name="form" method="post" action="">
            <table width="100%">
                <tr>
                    <td><?php _e("Link"); ?></td>
                    <td width="550">
                        <input type="hidden" name="action" value="feedsfinder">
                        <input type="text" name="link" size="60" maxlength="255">
                        <span class="submit"><input type="submit" name="submit-feedsfinder" class="button-primary" value="<?php esc_attr_e('Add new feed') ?>" /></span>
                    </td>
                    <td align="right">
                        <strong><?php _e('Bookmarklet') ?>:</strong> <a href="<?php echo friendsfeed_get_bookmarklet_link(); ?>" title="<?php echo esc_attr(__('Add to FriendsFeed')) ?>"><?php _e('Add to FriendsFeed') ?></a>
                    </td>
                </tr>
            </table>
        </form>
        <?php else : ?>
        <table width="100%">
            <thead>
                <th align="left"><?php _e('Title'); ?></th>
                <th align="left"><?php _e('Feed link'); ?></th>
                <th align="center" width="100">&nbsp;</th>
            </thead>
            <tbody>
                <?php foreach ($feeds as $feed) : ?>
                <tr>
                    <td><?php echo esc_html($feed['title']); ?></td>
                    <td><?php echo esc_html($feed['url']); ?></td>
                    <td align="center">
                        <?php if (!isset($feed['feed'])) : ?>
                            <form name="form" method="post" action="">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="feed_title" value="<?php echo esc_html($feed['title']); ?>">
                                <input type="hidden" name="feed_url" value="<?php echo esc_html($feed['url']); ?>">
                                <input type="hidden" name="link" value="<?php echo esc_html($feed['site']); ?>">
                                <span class="submit"><input type="submit" name="submit-add" class="button-primary" value="<?php esc_attr_e('Add this feed') ?>" /></span>
                            </form>
                        <?php else : ?>
                            <?php _e('Already added'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        <hr />
        <?php if (count($links)) : ?>
            <form name="form" method="post" action="">
                <input type="hidden" name="action" value="update_all">
                <span class="submit"><input type="submit" name="submit-update-all" class="button-primary" value="<?php esc_attr_e('Update All') ?>" /></span>
            </form>
        <?php endif; ?>
        <table width="100%">
            <thead>
                <th align="left"><?php _e('Site'); ?></th>
                <th align="left"><?php _e('Feed link'); ?></th>
                <th align="left"><?php _e('Status'); ?></th>
                <th align="left"><?php _e('Actions'); ?></th>
            </thead>
            <tbody>
                <?php if (count($links)) : ?>
                    <?php foreach ($links as $link) : ?>
                        <tr>
                            <td align="left"><strong><a href="<?php echo esc_html($link->link_url); ?>"><?php echo esc_html($link->link_name); ?></a></strong></td>
                            <td align="left"><a href="<?php echo esc_html($link->link_rss); ?>"><?php echo esc_html($link->link_rss); ?></a></td>
                            <td align="left"><?php _e($link->status()); ?></td>
                            <td align="left"><?php echo $link->control(); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td align="center" colspan="4"><?php _e('No links'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

/**
 * Page callback
 *
 * Bookmarklet page
 */
function friendsfeed_bookmarklet_page() {
    $errors = $messages = $feeds = array();

    wp_reset_vars(array('action', 'link', 'feed_url', 'feed_title'));
    global $action, $link, $feed_url, $feed_title;

    // Add new feed
    if ($action == 'feedsfinder') {
        if (!$link) {
            $errors[] = __('Error: please enter a valid url');
        }
        else {
            $feeds = friendsfeed_get_feeds_from_page($link);

            if (!count($feeds)) {
                $errors[] = __('Error: feeds not found at this page');
            }
            else {
                foreach ($feeds as &$feed) {
                    $existed = friendsfeed_link_exists($feed['url']);

                    if ($existed) {
                        $feed['feed'] = $existed;
                    }
                }
                unset($feed);
            }
        }
    }
    else if ($action == 'add') {
        if (!$feed_url) {
            $errors[] = __('Error: please enter a valid url');
        }
        else {
            $linkdata = array(
                'link_name'     => $feed_title,
                'link_url'      => $link,
                'link_rss'      => $feed_url,
                'link_category' => friendsfeed_get_links_category(),
            );

            $link_id = wp_insert_link($linkdata, true);

            if (!is_wp_error($link_id)) {
                $messages[] = __('Link was added');
                $feed = friendsfeed_get_links(array(
                    'include' => $link_id,
                ));
                if ($feed) {
                    $feed[0]->update();
                }
            }
            else {
                $errors[] = __('Error: Link not added');
            }
        }
    }
    ?>
    <div class="wrap">
        <h2><?php _e('Add Friend Feed') ?></h2>
        <?php if (count($errors)) : ?>
            <div class="error"><ul><li><?php echo implode('</li><li>', $errors) ?></li></ul></div>
        <?php endif; ?>
        <?php if (count($messages)) : ?>
            <div class="updated"><ul><li><?php echo implode('</li><li>', $messages) ?></li></ul></div>
        <?php endif; ?>
        <?php if (!count($feeds)) : ?>
        <form name="form" method="post" action="">
            <table width="100%">
                <tr>
                    <td><?php _e("Link"); ?></td>
                    <td>
                        <input type="hidden" name="action" value="feedsfinder">
                        <input type="text" name="link" size="60" maxlength="255">
                        <span class="submit"><input type="submit" name="submit-feedsfinder" class="button-primary" value="<?php esc_attr_e('Add new feed') ?>" /></span>
                    </td>
                </tr>
            </table>
        </form>
        <?php else : ?>
        <table width="100%">
            <thead>
                <th align="left"><?php _e('Title'); ?></th>
                <th align="left"><?php _e('Feed link'); ?></th>
                <th align="center" width="100">&nbsp;</th>
            </thead>
            <tbody>
                <?php foreach ($feeds as $feed) : ?>
                <tr>
                    <td><?php echo esc_html($feed['title']); ?></td>
                    <td><?php echo esc_html($feed['url']); ?></td>
                    <td align="center">
                        <?php if (!isset($feed['feed'])) : ?>
                            <form name="form" method="post" action="">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="feed_title" value="<?php echo esc_html($feed['title']); ?>">
                                <input type="hidden" name="feed_url" value="<?php echo esc_html($feed['url']); ?>">
                                <input type="hidden" name="link" value="<?php echo esc_html($feed['site']); ?>">
                                <span class="submit"><input type="submit" name="submit-add" class="button-primary" value="<?php esc_attr_e('Add this feed') ?>" /></span>
                            </form>
                        <?php else : ?>
                            <?php _e('Already added'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
    <?php
}


/*******************************
 * MISC
 *******************************/

function friendsfeed_get_bookmarklet_link() {
	$link = "javascript:
			var d=document,
			w=window,
			e=w.getSelection,
			k=d.getSelection,
			x=d.selection,
			s=(e?e():(k)?k():(x?x.createRange().text:0)),
			f='" . admin_url('admin.php') . "',
			l=d.location,
			e=encodeURIComponent,
			u=f+'?page=friendsfeed-bookmarklet-page&action=feedsfinder&link='+e(l.href);
			a=function(){if(!w.open(u,'t','toolbar=0,resizable=1,scrollbars=1,status=1,width=800,height=600'))l.href=u;};
			if (/Firefox/.test(navigator.userAgent)) setTimeout(a, 0); else a();
			void(0)";

	return str_replace(array("\r", "\n", "\t"),  '', $link);
}

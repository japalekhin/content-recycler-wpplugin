<?php
/* * ************************************************************************ *\

  Plugin Name:    Content Recycler
  Description:    Recycle old posts to the top of your blog automatically. Useful for occassional bloggers and affiliate marketers.
  Plugin URI:     https://jap.alekhin.io/content-recycler-wordpress-plugin-affiliate-marketers
  Version:        1.0.0
  Author:         Japa Alekhin Llemos
  Author URI:     https://jap.alekhin.io
  Text Domain:    content-recycler
  License:        GPLv3
  License URI:    https://www.gnu.org/licenses/gpl.html

 * ************************************************************************** *

  Content Recycler is free software: you can redistribute it and/or modify it
  under the terms of the GNU General Public License as published by the Free
  Software Foundation, either version 3 of the License, or any later version.

  Content Recycler is distributed in the hope that it will be useful, but
  WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
  FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more
  details.

  You should have received a copy of the GNU General Public License along with
  Content Recycler. If not, see https://www.gnu.org/licenses/gpl.html.

\* ************************************************************************** */

namespace Alekhin\ContentRecycler;

use DateTime;
use DateTimeZone;
use function add_action;
use function add_submenu_page;
use function admin_url;
use function get_current_screen;
use function get_option;
use function get_post_types;
use function get_posts;
use function get_the_time;
use function register_activation_hook;
use function register_deactivation_hook;
use function update_option;
use function wp_next_scheduled;
use function wp_redirect;
use function wp_schedule_event;
use function wp_unschedule_event;
use function wp_update_post;

class Main {

    const optionKeyEnabled = '_alekhin_recycler_enabled';
    const optionKeyInterval = '_alekhin_recycler_interval';
    const optionKeyPostTypes = '_alekhin_recycler_post_types';
    const optionKeySkip = '_alekhin_recycler_skip';
    const actionKey = '_alekhin_recycler_recycle';

    static function getPostTypes() {
        return array_filter(get_post_types([
            'public' => true,
            'hierarchical' => false,
                ]), function($postType) {
            return !in_array($postType, ['attachment',]);
        });
    }

    static function settingsEnabled($value = null) {
        is_null($value) || update_option(self::optionKeyEnabled, $value === true);
        return get_option(self::optionKeyEnabled, false);
    }

    static function settingsInterval($value = null) {
        is_null($value) || update_option(self::optionKeyInterval, max(1, intval(trim($value))));
        return intval(trim(get_option(self::optionKeyInterval, 24)));
    }

    static function settingsPostTypes($value = null) {
        (is_null($value) || !is_array($value)) || update_option(self::optionKeyPostTypes, $value);
        if (!is_array($postTypes = get_option(self::optionKeyPostTypes, ['post',]))) {
            return [];
        }
        return $postTypes;
    }

    static function settingsSkip($value = null) {
        (is_null($value) || !is_array($value)) || update_option(self::optionKeySkip, $value);
        if (!is_array($skip = get_option(self::optionKeySkip, []))) {
            return [];
        }
        return $skip;
    }

    static function postUpdateSettings() {
        if (is_null(filter_input(INPUT_POST, 'saveSettings'))) {
            return;
        }

        Main::settingsEnabled(filter_input(INPUT_POST, 'enabled') == 1);
        Main::settingsInterval(intval(trim(filter_input(INPUT_POST, 'interval'))));
        Main::settingsPostTypes(is_null(filter_input(INPUT_POST, 'postTypes')) ? [] : array_filter(filter_input(INPUT_POST, 'postTypes', FILTER_DEFAULT, FILTER_REQUIRE_ARRAY), function($postType) {
                            return in_array($postType, Main::getPostTypes());
                        }));
        Main::settingsSkip(array_filter(explode("\n", trim(filter_input(INPUT_POST, 'skip'))), function($skipItem) {
                    return !empty(trim($skipItem));
                }));

        wp_redirect($_SERVER['REQUEST_URI']);
        exit;
    }

    static function postRecycleNow() {
        if (is_null($action = filter_input(INPUT_GET, 'action'))) {
            return;
        }
        if ($action !== 'recycle-now') {
            return;
        }

        self::doRecycle();

        wp_redirect(admin_url('tools.php?page=content-recycler'));
        exit;
    }

    static function getOldestPublished() {
        $skipItems = array_filter(array_map(function($skipItem) {
                    if ((intval(trim($skipItem)) . '') === ($skipItem . '')) {
                        return intval(trim($skipItem));
                    }
                    if (empty($skipPosts = get_posts(['post_name__in' => [$skipItem,],]))) {
                        return 0;
                    }
                    return $skipPosts[0]->ID;
                }, Main::settingsSkip()), function($skipItem) {
            return $skipItem > 0;
        });

        if (empty($posts = get_posts([
                    'posts_per_page' => 1,
                    'post_type' => self::settingsPostTypes(),
                    'exclude' => $skipItems,
                    'orderby' => 'date',
                    'order' => 'ASC',
                ]))) {
            return null;
        }
        return $posts[0];
    }

    static function getNewestPublished() {
        if (empty($posts = get_posts([
                    'posts_per_page' => 1,
                    'post_type' => self::settingsPostTypes(),
                    'orderby' => 'date',
                    'order' => 'DESC',
                ]))) {
            return null;
        }
        return $posts[0];
    }

    static function doRecycle() {
        if (is_null($oldestPost = Main::getOldestPublished())) {
            return;
        }

        wp_update_post([
            'ID' => $oldestPost->ID,
            'post_date' => (new DateTime('now'))->format('Y-m-d H:i:s'),
            'post_date_gmt' => (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
        ]);
    }

}

add_action('admin_menu', function() {
    add_submenu_page('tools.php', 'Content Recycler', 'Content Recycler', 'edit_others_posts', 'content-recycler', function() {
        ?>
        <div class="wrap recyclerSettings">
            <h1 class="wp-heading-inline">Content Recycler</h1>
            <a href="<?php echo admin_url('tools.php?page=content-recycler&action=recycle-now'); ?>" class="page-title-action">Recycle Now</a>
            <hr class="wp-header-end" />

            <form action="<?php echo $_SERVER['REQUEST_URI']; ?>" method="POST">
                <section>
                    <h2>Settings</h2>
                    <table class="form-table">
                        <tbody>
                            <tr>
                                <th scope="row">
                                    <label for="chkEnabled">Recycle posts</label>
                                </th>
                                <td>
                                    <label for="chkEnabled">
                                        <input type="checkbox" id="chkEnabled" name="enabled"<?php echo Main::settingsEnabled() ? ' checked="checked"' : ''; ?> value="1" />
                                        Enable
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="txtInterval">Interval</label>
                                </th>
                                <td>
                                    <input type="number" id="txtInterval" name="interval" step="1" min="1" value="<?php echo Main::settingsInterval(); ?>" />
                                    <p class="description">
                                        Recycle oldest post if there are no new posts published in <code>interval</code> hours.<br />
                                        <strong>Recommended</strong> 24 &ndash; 72 hours.
                                    </p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label>Post types</label>
                                </th>
                                <td>
                                    <?php if (!empty($postTypes = Main::getPostTypes())): ?>
                                        <?php foreach ($postTypes as $postType): ?>
                                            <div>
                                                <label for="chkPostType_<?php echo $postType; ?>">
                                                    <input type="checkbox" id="chkPostType_<?php echo $postType; ?>" name="postTypes[]" value="<?php echo $postType; ?>"<?php echo in_array($postType, Main::settingsPostTypes()) ? ' checked="checked"' : ''; ?> />
                                                    <?php echo $postType; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        No recyclable posts types.
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="txtSkip">Skip items</label>
                                </th>
                                <td>
                                    <textarea id="txtSkip" name="skip" row="7"><?php echo implode("\n", Main::settingsSkip()); ?></textarea>
                                    <p class="description">Slug or ID of posts that should be skipped. <strong>One per line</strong>.</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="submit">
                        <button type="submit" name="saveSettings" class="button-primary">Save Settings</button>
                    </p>
                </section>
            </form>
        </div>
        <style>
            .recyclerSettings #txtSkip {
                resize: none;
            }
        </style>
        <?php
    });
});

add_action('current_screen', function () {
    if (get_current_screen()->id !== 'tools_page_conten-recycler') {
        return;
    }
    Main::postUpdateSettings();
    Main::postRecycleNow();
});

add_action(Main::actionKey, function() {
    if (!Main::settingsEnabled()) {
        return;
    }
    if (empty(Main::settingsPostTypes())) {
        return;
    }
    if (is_null($newestPost = Main::getNewestPublished())) {
        return;
    }
    if (intval(trim(get_the_time('U', $newestPost))) >= time() - (Main::settingsInterval() * 3600)) {
        return;
    }
    Main::doRecycle();
});

register_activation_hook(__FILE__, function() {
    if (wp_next_scheduled(Main::actionKey) === false) {
        wp_schedule_event(time(), 'hourly', Main::actionKey);
    }
});

register_deactivation_hook(__FILE__, function() {
    if (false !== ($time = wp_next_scheduled(Main::actionKey))) {
        wp_unschedule_event($time, Main::actionKey);
    }
});


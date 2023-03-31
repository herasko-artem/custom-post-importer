<?php
/**
 * Plugin Name: Сustom post importer 
 * Plugin URI: https://github.com/herasko-artem/custom-post-importer
 * Description: This plugin fetches articles from an API and creates new posts in WordPress. Also displays posts via shortcode
 * Version: 1.0.0
 * Author: Artem Herasko
 */

//-------------------------------------------------------
//-- First part: getting, adding, new posts to the database
//-------------------------------------------------------

// Add new cron event after activation plugin
register_activation_hook(__FILE__, 'cpi_activation');

if (!function_exists('cpi_activation')) {
    function cpi_activation() {
        wp_clear_scheduled_hook('cpi_daily_event');
        wp_schedule_event(time(), 'daily', 'cpi_daily_event');
    }
}
add_action('cpi_daily_event', 'cpi_get_and_publish_posts');

// Remove cron event after deactivation plugin
register_deactivation_hook(__FILE__, 'cpi_deactivation');

if (!function_exists('cpi_deactivation')) {
    function cpi_deactivation() {
        wp_clear_scheduled_hook('cpi_daily_event');
    }
}

// Add custom fields to the post editor 
if (!function_exists('cpi_custom_fields')) {
    function cpi_custom_fields() {
        add_meta_box('cpi_custom_link', 'Custom Link', 'cpi_custom_link_callback', 'post');
        add_meta_box('cpi_custom_rating', 'Rating', 'cpi_custom_rating_callback', 'post');
    }
}
add_action('add_meta_boxes', 'cpi_custom_fields');

// Custom Link metabox 
if (!function_exists('cpi_custom_link_callback')) {
    function cpi_custom_link_callback($post) {
        $value = get_post_meta($post->ID, 'cpi_custom_link', true);
        echo '<label for="cpi_custom_link">Enter a custom link:</label><br>';
        echo '<input type="text" name="cpi_custom_link" value="' . esc_attr($value) . '" />';
    }
}

// Custom Rating metabox
if (!function_exists('cpi_custom_rating_callback')) {
    function cpi_custom_rating_callback($post) {
        $value = get_post_meta($post->ID, 'cpi_custom_rating', true);
        echo '<label for="cpi_custom_rating">Enter a rating:</label><br>';
        echo '<input type="text" name="cpi_custom_rating" value="' . esc_attr($value) . '" />';
    }
}

// Save custom fields data
if (!function_exists('cpi_custom_fields_save')) {
    function cpi_custom_fields_save($post_id) {
        if (isset($_POST['cpi_custom_link'])) {
            $link = sanitize_text_field($_POST['cpi_custom_link']);
            update_post_meta($post_id, 'cpi_custom_link', $link);
        }

        if (isset($_POST['cpi_custom_rating'])) {
            $rating = sanitize_text_field($_POST['cpi_custom_rating']);
            update_post_meta($post_id, 'cpi_custom_rating', $rating);
        }
    }
}
add_action('save_post', 'cpi_custom_fields_save');

//Get articles from API
if (!function_exists('cpi_get_and_publish_posts')) {
    function cpi_get_and_publish_posts() {
        $response = wp_remote_get(
            'https://my.api.mockaroo.com/posts.json',
            array(
                'headers' => array('X-API-Key' => '413dfbf0')
            )
        );
        $articles = json_decode(wp_remote_retrieve_body($response));
        //Go through each post 
        foreach ($articles as $article) {
            // Check if post exists in the database
            $args = array(
                'post_type'      => 'post',
                'posts_per_page' => 1,
                'title'          => $article->title
            );
            $post = get_posts($args);
            //$post_id = post_exists($article->title); first method

            // If post does not exist, create a new post
            if (!$post) {
                $post_data = array(
                    'post_title'    => $article->title,
                    'post_content'  => $article->content,
                    'post_status'   => 'publish',
                    'post_author'   => cpi_get_first_admin_id(),
                    'post_category' => array(cpi_get_category_id($article->category)),
                    'post_date'     => cpi_random_date(),
                    'meta_input'    => array(
                        'cpi_custom_link'   => $article->site_link,
                        'cpi_custom_rating' => $article->rating,
                    ),
                );

                // Add post to database
                $post_id = wp_insert_post(wp_slash($post_data));

                // Set post thumbnail (featured image)
                if ($article->image) {
                    cpi_upload_image($article->image, $article->title, $post_id);
                }
            }
        }
    }
}

// Function for uploading images for media library
if (!function_exists('cpi_upload_image')) {
    function cpi_upload_image($url, $title, $post_id) {
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $photo_title = "Image for - $title";
        $image_id = media_sideload_image($url, $post_id, $photo_title, 'id');
        set_post_thumbnail($post_id, $image_id);
    }
}


// Function to get the ID of a category. If the category does not exist, create it
if (!function_exists('cpi_get_category_id')) {
    function cpi_get_category_id($category_name) {
        $category = get_term_by('name', $category_name, 'category', 'ARRAY_A');
        if (!$category) {
            $category = wp_insert_term($category_name, 'category');
        }
        return $category['term_id'];
    }
}

// Function to get the ID of the first user with the administrator role
if (!function_exists('cpi_get_first_admin_id')) {
    function cpi_get_first_admin_id() {
        $users = get_users(array('role' => 'administrator'));
        return $users[0]->ID;
    }
}

// Function to get a random date between "today" and "minus 1 month"
if (!function_exists('cpi_random_date')) {
    function cpi_random_date() {
        $current_time = current_time('timestamp');
        $one_month_time = strtotime('-1 month', $current_time);
        $random_time = rand($one_month_time, $current_time);
        return date('Y-m-d H:i:s', $random_time);
    }
}

//-------------------------------------------------------
//-- Second part: Add shortcode
//-------------------------------------------------------
if (!function_exists('cpi_article_list_shortcode')) {
    function cpi_article_list_shortcode($atts) {
        // Parse shortcode attributes
        $atts = shortcode_atts(
            array(
                'title' => '',
                'count' => -1,
                'sort'  => 'date',
                'order' => 'DESC',
                'ids'   => ''
            ),
            $atts
        );
        // 
        if ($atts['sort'] == 'rating') {
            $atts['sort'] = 'meta_value_num';
        }
        // Prepare query arguments
        $query_args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'meta_key'       => 'cpi_custom_rating',
            'orderby'        => $atts['sort'],
            'order'          => $atts['order'],
            'posts_per_page' => $atts['count']
        );

        // Handle IDs parameter
        if (!empty($atts['ids'])) {
            $query_args['post__in'] = explode(',', $atts['ids']);
        }

        // Retrieve posts using get_posts()
        global $post;
        $posts = get_posts($query_args);

        if ($posts) {
            // Output posts
            $output = '';
            // Item wrapper start
            $output .= '<div class="cpi-item-wrapper">';

            // Shortcode title
            if (!empty($atts['title'])) {
                $output .= '<h2 class="cpi-shortcode-title">' . esc_html($atts['title']) . '</h2>';
            }

            foreach ($posts as $post) {
                setup_postdata($post);
                $id = $post->ID;
                // Item start 
                $output .= '<div class="cpi-item">';

                // Item image start
                $bacground = get_the_post_thumbnail_url() ? ' style="background-image: url(' . get_the_post_thumbnail_url() . ');" ' : '';
                $output .= '<div class="cpi-img" ' . $bacground . '>';
                // Item image end
                $output .= '</div>';

                // Item body srart
                $output .= '<div class="cpi-item-body">';


                // Item category start
                $categories = get_the_category($id);
                if (!empty($categories)) {
                    $output .= '<span class="cpi-item-category">';
                    foreach ($categories as $category) {
                        $output .= '<a href="' . esc_url(get_category_link($category->term_id)) . '">' . esc_html($category->name) . '</a>';
                    }
                    $output .= '</span>';
                }
                // Item category end

                // Item title start
                $title = get_the_title();
                if (!empty($title)) {
                    $output .= '<h4 class="cpi-item-title"><a href="' . esc_url(get_permalink()) . '"> ' . $title . ' </a></h4>';
                }
                // Item title end

                // Item footer start
                $output .= '<div class="cpi-item-footer">';

                // Read more link start
                $output .= '<a class="cpi-item-read-more-link" href="' . esc_url(get_permalink()) . '"> Read More </a>';

                // Custom fields start
                $rating = get_post_meta($id, 'cpi_custom_rating', true);
                $custom_link = get_post_meta($id, 'cpi_custom_link', true);

                if (!empty($rating)) {
                    $output .= '<div class="cpi-item-rating"> <span class="cpi-rating-emoji">⭐</span> <span class="cpi-rating-text">' . esc_html($rating) . '</span></div>';
                }
                if (!empty($custom_link)) {
                    $output .= '<a class="cpi-item-custom-link" target="blank" href="' . esc_url($custom_link) . '"> Visit Site </a>';
                }
                // Custom fields end

                // Item footer end
                $output .= '</div>';

                // Item body end
                $output .= '</div>';
                // Item end
                $output .= '</div>';
            }
        } else {
            $output .= '<p>No articles found.</p>';
        }

        // Item wrapper end
        $output .= '</div>';

        wp_reset_postdata();

        return $output;
    }
}
add_shortcode('cpi-article-list', 'cpi_article_list_shortcode');

// Enqueue the stylesheet  if the shortcode has been used on this page
if (!function_exists('cpi_enqueue_scripts')) {
    function cpi_enqueue_scripts() {
        if (has_shortcode(get_the_content(), 'cpi-article-list')) {
            wp_enqueue_style('cpi-styles', plugin_dir_url(__FILE__) . 'cpi-styles.css');
        }

    }
}
add_action('wp_enqueue_scripts', 'cpi_enqueue_scripts');


// Add menu item in admin panel
if (!function_exists('cpi_add_plugin_menu_item')) {
    function cpi_add_plugin_menu_item() {
        add_menu_page(
            'Сustom post importer  settings',
            'Сustom post importer',
            'manage_options',
            'cpi-settings-page',
            'cpi_settings_page',
            '', 
            10
        );
    }
}
// Description plugin in admin panel
if (!function_exists('cpi_settings_page')) {
    function cpi_settings_page() {
        ?>
        <div class="wrap">
            <h1>Сustom post importer</h1>
            <p>
                This plugin fetches articles from an API and creates new posts in WordPress. Also displays posts via shortcode.
            </p>
            <p> Insert the shortcode <b>[cpi-article-list]</b> on the page to display the post</p>
            <p>A shortcode has the following attributes</p>
            <ul>
                <li>title - H2 title before the list of articles. For example [cpi-article-list title="Article"] </li>
                <li>count - the number of articles for output. For example [cpi-article-list count="3"] </li>
                <li>sort - the value can be one of: date, title, rating. For example [cpi-article-list sort="rating"] </li>
                <li>ids - the ability to specify article ids separated by commas. For example [cpi-article-list ids="1,2,3"] </li>
                <li>order DESC or ASC. For example [cpi-article-list order="ASC"] </li>
            </ul>
        </div>
        <?php
    }
}
add_action( 'admin_menu', 'cpi_add_plugin_menu_item' );
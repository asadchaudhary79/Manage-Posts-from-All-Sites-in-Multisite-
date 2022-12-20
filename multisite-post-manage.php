<?php

// Multisite Post and Pages 


//Condation apply on that pages 
$edtiable_parent_posts = [
    24443, 30841, 31702, 24499, 24447, 24480, 30853, 24489, 24454, 30948, 24507, 24522, 24485, 25606, 24462, 24513, 24504, 25623, 29293, 30106, 29421, 30581, 24405, 26743, 24410, 3, 2682, 24413, 24416, 35975, 24420, 24423,
];


// flter hook userd for wp post import 

add_filter('wp_import_post_data_raw', 'wp_import_post_date_multisite', 10, 1);

function wp_import_post_date_multisite($post)
{
    // global $edtiable_parent_posts;

    if (!is_multisite() || is_main_site()) return $post;
    //if (!in_array($post['post_id'], $edtiable_parent_posts)) return $post;
    $blog_ids = get_sites();

    foreach ($blog_ids as $site) {
        $domain = $site->domain;
        $domain = preg_replace('/www./', '', $domain);
    }

    $site_name = get_site_meta(get_current_blog_id(), 'blog_city', true);

    $post['post_title'] = preg_replace('/(London|london|LONDON)/', $site_name, $post['post_title']);
    $post['post_name'] = preg_replace('/(London|london|LONDON)/', $site_name, $post['post_name']);

    if (!empty($post['post_content'])) {
        // $post['post_content'] = preg_replace('/London/', $site_name, $post['post_content']);
        // $post['post_content'] = wp_multisite_import_post_attachments($post['post_content']);
        $post['post_content'] = preg_replace('/rentlondonflat.com/', $domain, $post['post_content']);
        $post['post_content'] = preg_replace('/(London|london|LONDON)/', $site_name, $post['post_content']);
        $post['post_content'] = wp_multisite_import_post_attachments($post['post_content']);
    }

    return $post;
}


// save post hook for multisite blog 

add_action('save_post', 'save_post_data_for_multisite', 10, 2);

function save_post_data_for_multisite($original_post_id, $original_post)
{
    global $edtiable_parent_posts;
    if (!in_array($original_post_id, $edtiable_parent_posts)) return;

    // do not publish revisions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
        return $original_post_id;
    }
    if ('publish' !== get_post_status($original_post)) {
        return $original_post_id;
    }

    remove_action('save_post', __FUNCTION__);

    $blog_ids = get_sites();

    foreach ($blog_ids as $site) {
        $blog_id = $site->id;
        $domain = $site->domain;
        $domain = preg_replace('/www./', '', $domain);
        $site_name = get_site_meta($blog_id, 'blog_city', true);

        if ($blog_id === 1)
            continue;
        switch_to_blog($blog_id, $domain);

        $post_title = preg_replace('/(London|london|LONDON)/', $site_name, $original_post->post_title);
        $post_name = preg_replace('/(London|london|LONDON)/', $site_name, $original_post->post_name);

        $post_content = '';

        if (!empty($original_post->post_content)) {

            $post_content = preg_replace('/rentlondonflat.com/', $domain, $original_post->post_content);

            $post_content = preg_replace('/(London|london|LONDON)/', $site_name, $post_content);

            $post_content = wp_multisite_import_post_attachments($post_content);
        }

        $post_data = array(
            'ID' => $original_post->ID,
            'post_date' => $original_post->post_date,
            'post_modified' => $original_post->post_modified,
            'post_content' => $post_content,
            'post_title' => $post_title,
            'post_excerpt' => $original_post->post_excerpt,
            'post_status' => 'publish',
            'post_name' => $post_name,
            'post_type' => $original_post->post_type,
        );

        $post_meta = get_post_custom($original_post_id, '_wp_page_template', true);

        $post_id = wp_insert_post($post_data);
        if ($post_id) {
            foreach ($post_meta as $key => $value) {
                $post_meta_value = preg_replace('/(London|london|LONDON)/', $site_name, $value[0]);
                add_post_meta($post_id, $key, $post_meta_value);
            }
            update_post_meta($post_id, '_wp_page_template', $post_meta['_wp_page_template'][0]);
        }

        restore_current_blog();
    }
}


function wp_multisite_import_post_attachments($post_content)
{
    $post_content = preg_replace_callback([
        '/\[vc_single_image(.+?)?\](?:(.+?))?/',
        '/\[vc_images_carousel(.+?)?\](?:(.+?))?/',
        '/\[vc_gallery(.+?)?\](?:(.+?))?/',
        '/\[bsf-info-box(.+?)?\](?:(.+?))?/'
    ], function ($matches) {
        //preg_match('/(images|image)="\d+(,\d+)*"/', $matches[0], $matches_str);
        //preg_match('/\d+(,\d+)*/', $matches_str[0], $matches_ids);
        preg_match('/(images|image)="\d+(,\d+)*"|(icon_img="id\^)\d+/', $matches[0], $matches_str);
        preg_match('/\d+(,\d+)*|\d+/', $matches_str[0], $matches_ids);

        $parent_img_ids = explode(',', $matches_ids[0]);

        foreach ($parent_img_ids as $id) {
            $img_ids = [];
            global $wpdb;

            $attachment = false;
            //swtich to parent blog to fetch attachent with $id
            switch_to_blog(1);
            $attachment = wp_get_attachment_image_src($id, 'full');
            restore_current_blog();

            if ($attachment) {
                $img_url = $attachment[0];
                $filename = basename($img_url);
                $attach_id = $wpdb->get_var("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE '%/$filename'");

                if (!$attach_id) {
                    $attach_id = wp_insert_attachment_from_url($img_url);
                    if ($attach_id) {
                        array_push($img_ids, $attach_id);
                    }
                } else {
                    array_push($img_ids, $attach_id);
                }
            }
        }

        return preg_replace(
            ['/image="\d+(,\d+)*"/', '/images="\d+(,\d+)*"/', '/(icon_img="id\^)\d+/'],
            ['image="' . implode(',', $img_ids) . '"', 'images="' . implode(',', $img_ids) . '"', 'icon_img="id^' . $img_ids],
            $matches[0]
        );
        // return preg_replace(
        //     ['/image="\d+(,\d+)*"/', '/images="\d+(,\d+)*"/'],
        //     ['image="' . implode(',', $img_ids) . '"', 'images="' . implode(',', $img_ids) . '"'],
        //     $matches[0]
        // );
    }, $post_content);

    return $post_content;
}


function wp_insert_attachment_from_url($url, $parent_post_id = null)
{

    if (!class_exists('WP_Http')) {
        require_once ABSPATH . WPINC . '/class-http.php';
    }

    $http     = new WP_Http();
    $response = $http->request($url);

    if (is_a($response, 'WP_Error')) {
        return false;
    }

    $upload = wp_upload_bits(basename($url), null, $response['body']);
    if (!empty($upload['error'])) {
        return false;
    }

    $file_path        = $upload['file'];
    $file_name        = basename($file_path);
    $file_type        = wp_check_filetype($file_name, null);
    $attachment_title = sanitize_file_name(pathinfo($file_name, PATHINFO_FILENAME));
    $wp_upload_dir    = wp_upload_dir();

    $post_info = array(
        'guid'           => $wp_upload_dir['url'] . '/' . $file_name,
        'post_mime_type' => $file_type['type'],
        'post_title'     => $attachment_title,
        'post_content'   => '',
        'post_status'    => 'inherit',
    );

    // Create the attachment.
    $attach_id = wp_insert_attachment($post_info, $file_path, $parent_post_id);

    // Include image.php.
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Generate the attachment metadata.
    $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);

    // Assign metadata to attachment.
    wp_update_attachment_metadata($attach_id, $attach_data);

    return $attach_id;
}






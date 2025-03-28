<?php
/*
  Plugin Name - Fake Page Plugin 2
  Plugin URI - http://scott.sherrillmix.com/blog/blogger/creating-a-better-fake-post-with-a-wordpress-plugin/
  Description - Creates a fake page without a 404 error (based on <a href="http://headzoo.com/tutorials/wordpress-creating-a-fake-post-with-a-plugin">Sean Hickey's Fake Plugin Page</a>)
  Author - Scott Sherrill-Mix
  Author URI - http://scott.sherrillmix.com/blog/
  Version - 1.1
 */

class FakePage
{
    /**
     * The slug for the fake post.  This is the URL for your plugin, like:
     * http://site.com/about-me or http://site.com/?page_id=about-me
     * @var string
     */
    var $page_slug = 'about-me';

    /**
     * The title for your fake post.
     * @var string
     */
    var $page_title = 'About Me';

    /**
     * The content for your fake post.
     * @var string
     */
    var $page_content = '';

    var $context = null;
    

    /**
     * Allow pings?
     * @var string
     */
    var $ping_status = 'open';

    /**
     * Class constructor
     */
    function __construct( $aSlug, $aTitle, $aContent, $context = array())
    {
        /**
         * We'll wait til WordPress has looked for posts, and then
         * check to see if the requested url matches our target.
         */
        $this->page_slug    = $aSlug;
        $this->page_title   = $aTitle;
        $this->page_content = $aContent;
        $this->context      = $context;

        add_filter('the_posts',array(&$this,'detectPost'), 100000);
    }


    /**
     * Called by the 'detectPost' action
     */
    function createPost()
    {

        /**
         * What we are going to do here, is create a fake post.  A post
         * that doesn't actually exist. We're gonna fill it up with
         * whatever values you want.  The content of the post will be
         * the output from your plugin.
         */

        /**
         * Create a fake post.
         */
        $post = new stdClass;

        /**
         * The author ID for the post.  Usually 1 is the sys admin.  Your
         * plugin can find out the real author ID without any trouble.
         */
        $post->post_author = 1;

        /**
         * The safe name for the post.  This is the post slug.
         */
        $post->post_name = $this->page_slug;

        /**
         * Not sure if this is even important.  But gonna fill it up anyway.
         */
        $post->guid = get_bloginfo('wpurl') . '/' . $this->page_slug;


        /**
         * The title of the page.
         */
        $post->post_title = $this->page_title;

        /**
         * This is the content of the post.  This is where the output of
         * your plugin should go.  Just store the output from all your
         * plugin function calls, and put the output into this var.
         */
        $post->post_content = $this->getContent();

        /**
         * Fake post ID to prevent WP from trying to show comments for
         * a post that doesn't really exist.
         */
        //$post->ID = -1;
        $post->ID = PHP_INT_MAX;
        if( $this->context['post_type'] ) {
			$post->post_type = $this->context['post_type'];
		}
		else {
			$post->post_type = 'page';
		}
        /**
         * Static means a page, not a post.
         */
        $post->post_status = 'static';

        /**
         * Turning off comments for the post.
         */
        $post->comment_status = 'closed';

        /**
         * Let people ping the post?  Probably doesn't matter since
         * comments are turned off, so not sure if WP would even
         * show the pings.
         */
        $post->ping_status = $this->ping_status;

        $post->comment_count = 0;

        /**
         * You can pretty much fill these up with anything you want.  The
         * current date is fine.  It's a fake post right?  Maybe the date
         * the plugin was activated?
         */
        $post->post_date = current_time('mysql');
        $post->post_date_gmt = current_time('mysql', 1);
        
        $post->filter = 'raw'; // important!

        // set any context fields
        if (!empty($this->context)) {
            foreach ($this->context as $key => $value) {
                $post->$key = $value;
            }
        }

        return($post);
    }

    function getContent()
    {
        if (trim($this->page_content) == '')
            return '<p>========================</p>';
        else
            return $this->page_content;
    }

    function detectPost($posts){
        global $wp;
        global $wp_query;
        /**
         * Check if the requested page matches our target
         */

        // error_log( "dbg__ detectPosts: " . strtolower($wp->request) . " |" . strtolower($this->page_slug) . " | " . $wp->query_vars['page_id'] . " | " . $this->page_slug );


        if ( 1 || strtolower($wp->request) == strtolower($this->page_slug) || $wp->query_vars['page_id'] == $this->page_slug){
            //Add the fake post
            $posts=NULL;
            $posts[]=$this->createPost();

            /**
             * Trick wp_query into thinking this is a page (necessary for wp_title() at least)
             * Not sure if it's cheating or not to modify global variables in a filter
             * but it appears to work and the codex doesn't directly say not to.
             */
            //$wp_query->is_page = true;
            // Not sure if this one is necessary but might as well set it like a true page
            
            //Longer permalink structures may not match the fake post slug and cause a 404 error so we catch the error here
            //unset($wp_query->query["error"]); //glock
            $wp_query->query_vars["error"]="";
			
			$wp_post = new WP_Post($posts[0]);
			$wp_query->post = $wp_post;
			$wp_query->posts = array($wp_post);

			$wp_query->queried_object = $wp_post;
			// $wp_query->queried_object_id = $post_id;
			$wp_query->queried_object_id = PHP_INT_MAX;
			$wp_query->found_posts = 1;
			$wp_query->post_count = 1;
			$wp_query->max_num_pages = 1; 
			$wp_query->is_page = true;
			$wp_query->is_singular = true;
			$wp_query->is_single = false; 
			$wp_query->is_attachment = false;
			$wp_query->is_archive = false; 
			$wp_query->is_category = false;
			$wp_query->is_tag = false; 
			$wp_query->is_tax = false;
			$wp_query->is_author = false;
			$wp_query->is_date = false;
			$wp_query->is_year = false;
			$wp_query->is_month = false;
			$wp_query->is_day = false;
			$wp_query->is_time = false;
			$wp_query->is_search = false;
			$wp_query->is_feed = false;
			$wp_query->is_comment_feed = false;
			$wp_query->is_trackback = false;
			$wp_query->is_home = false;
			$wp_query->is_embed = false;
			$wp_query->is_404 = false; 
			$wp_query->is_paged = false;
			$wp_query->is_admin = false; 
			$wp_query->is_preview = false; 
			$wp_query->is_robots = false; 
			$wp_query->is_posts_page = false;
			$wp_query->is_post_type_archive = false;
        }
        remove_filter('the_posts',array(&$this,'detectPost'), 100000); //glock. add this to not to brake other page loops
        return $posts;
    }
}
?>
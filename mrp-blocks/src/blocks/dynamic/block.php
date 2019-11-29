<?php

function mrp_dynamic_block_render( $attributes, $content ) {
	return sprintf(
	  '<div class="%s">Dynamic</div>',
	  'wp-block-cgb-mrp-dynamic-block');
  }


function mrp_dynamic_block_register() {
	register_block_type( 'cgb/mrp-dynamic-block', array(
		'render_callback' => 'mrp_dynamic_block_render',
	) );
}


add_action( 'init', 'mrp_dynamic_block_register' );

<?php

function mrp_cgb__render_block( $attributes, $content ) {
	return sprintf(
	  '<div class="%s">DYNAMIC!
		[mrp account_id=1206 searchform_def=idx.browse embed=true context=recip]
	  </div>',
	  'wp-block-cgb-mrp-dynamic-block');
  }

register_block_type( 'cgb/mrp-dynamic-block', array(
	'render_callback' => 'mrp_cgb__render_block',
) );

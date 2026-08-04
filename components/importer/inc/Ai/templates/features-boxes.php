<?php
/**
 * AI section template: features / boxes
 * Ported from inspiro-patterns/includes/patterns/services-boxes.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'features',
	'variant'     => 'boxes',
	'description' => 'Kicker line and large heading above three dark rounded boxes, each with a title and short description',
	'images'      => 0,
	'image_query' => '',
	'slots'       => array( 'heading', 'intro' ),
	'items'       => 3,
	'item_fields' => array( 'title', 'text' ),
	'defaults'    => array(),
	'markup'      => <<<'HTML'
<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide"><!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"#0cb3a8"}}},"color":{"text":"#0cb3a8"}}} -->
<p class="has-text-color has-link-color" style="color:#0cb3a8">{{intro}}</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"fontSize":"max-48"} -->
<h2 class="wp-block-heading has-max-48-font-size">{{heading}}</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"38px"} -->
<div style="height:38px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"style":{"spacing":{"padding":{"top":"30px","right":"30px","bottom":"30px","left":"30px"}},"border":{"width":"0px","style":"none","radius":"5px"},"color":{"background":"#1f1e1e","text":"#e7e7e7"},"elements":{"link":{"color":{"text":"#e7e7e7"}}}}} -->
<div class="wp-block-column has-text-color has-background has-link-color" style="border-style:none;border-width:0px;border-radius:5px;color:#e7e7e7;background-color:#1f1e1e;padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px"><!-- wp:heading {"level":3,"textColor":"white"} -->
<h3 class="wp-block-heading has-white-color has-text-color">{{item_1_title}}</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>{{item_1_text}}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"style":{"spacing":{"padding":{"top":"30px","right":"30px","bottom":"30px","left":"30px"}},"border":{"width":"0px","style":"none","radius":"5px"},"color":{"background":"#1f1e1e","text":"#e7e7e7"},"elements":{"link":{"color":{"text":"#e7e7e7"}}}}} -->
<div class="wp-block-column has-text-color has-background has-link-color" style="border-style:none;border-width:0px;border-radius:5px;color:#e7e7e7;background-color:#1f1e1e;padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px"><!-- wp:heading {"level":3,"textColor":"white"} -->
<h3 class="wp-block-heading has-white-color has-text-color">{{item_2_title}}</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>{{item_2_text}}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column {"style":{"spacing":{"padding":{"top":"30px","right":"30px","bottom":"30px","left":"30px"}},"border":{"width":"0px","style":"none","radius":"5px"},"color":{"background":"#1f1e1e","text":"#e7e7e7"},"elements":{"link":{"color":{"text":"#e7e7e7"}}}}} -->
<div class="wp-block-column has-text-color has-background has-link-color" style="border-style:none;border-width:0px;border-radius:5px;color:#e7e7e7;background-color:#1f1e1e;padding-top:30px;padding-right:30px;padding-bottom:30px;padding-left:30px"><!-- wp:heading {"level":3,"textColor":"white"} -->
<h3 class="wp-block-heading has-white-color has-text-color">{{item_3_title}}</h3>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>{{item_3_text}}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group -->
HTML,
);

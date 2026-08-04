<?php
/**
 * AI section template: features / covers
 * Ported from inspiro-patterns/includes/patterns/features-cover-columns.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'features',
	'variant'     => 'covers',
	'description' => 'Two square photo cover columns, each with a title, short description and a white button pinned to the bottom',
	'images'      => 2,
	'image_query' => '',
	'slots'       => array( 'button_text' ),
	'items'       => 2,
	'item_fields' => array( 'title', 'text' ),
	'defaults'    => array( 'button_text' => 'Explore' ),
	'markup'      => <<<'HTML'
<!-- wp:columns {"align":"full","style":{"spacing":{"padding":{"right":"var:preset|spacing|small","left":"var:preset|spacing|small","top":"var:preset|spacing|small","bottom":"var:preset|spacing|small"},"blockGap":{"left":"var:preset|spacing|small"}}}} -->
<div class="wp-block-columns alignfull" style="padding-top:var(--wp--preset--spacing--small);padding-right:var(--wp--preset--spacing--small);padding-bottom:var(--wp--preset--spacing--small);padding-left:var(--wp--preset--spacing--small)"><!-- wp:column {"verticalAlignment":"stretch"} -->
<div class="wp-block-column is-vertically-aligned-stretch"><!-- wp:cover {"url":"{{image_1}}","dimRatio":10,"isUserOverlayColor":true,"style":{"border":{"radius":"0px"},"spacing":{"blockGap":"0"},"dimensions":{"aspectRatio":"1"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-cover" style="border-radius:0px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-10 has-background-dim"></span><img class="wp-block-cover__image-background" alt="" src="{{image_1}}" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">{{item_1_title}}</h3>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|x-small"} -->
<div style="height:var(--wp--preset--spacing--x-small)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:paragraph {"fontSize":"medium"} -->
<p class="has-medium-font-size">{{item_1_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"white","style":{"color":{"text":"#1f1f1f"},"elements":{"link":{"color":{"text":"#1f1f1f"}}},"border":{"radius":"4px"}},"fontSize":"small"} -->
<div class="wp-block-button has-custom-font-size has-small-font-size"><a class="wp-block-button__link has-white-background-color has-text-color has-background has-link-color wp-element-button" href="{{button_url}}" style="border-radius:4px;color:#1f1f1f">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"stretch"} -->
<div class="wp-block-column is-vertically-aligned-stretch"><!-- wp:cover {"url":"{{image_2}}","dimRatio":10,"isUserOverlayColor":true,"style":{"border":{"radius":"0px"},"spacing":{"blockGap":"0"},"dimensions":{"aspectRatio":"1"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-cover" style="border-radius:0px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-10 has-background-dim"></span><img class="wp-block-cover__image-background" alt="" src="{{image_2}}" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3,"fontSize":"large"} -->
<h3 class="wp-block-heading has-large-font-size">{{item_2_title}}</h3>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"var:preset|spacing|x-small"} -->
<div style="height:var(--wp--preset--spacing--x-small)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:paragraph {"fontSize":"medium"} -->
<p class="has-medium-font-size">{{item_2_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"white","style":{"color":{"text":"#1f1f1f"},"elements":{"link":{"color":{"text":"#1f1f1f"}}},"border":{"radius":"4px"}},"fontSize":"small"} -->
<div class="wp-block-button has-custom-font-size has-small-font-size"><a class="wp-block-button__link has-white-background-color has-text-color has-background has-link-color wp-element-button" href="{{button_url}}" style="border-radius:4px;color:#1f1f1f">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->
HTML,
);

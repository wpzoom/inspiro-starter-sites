<?php
/**
 * AI section template: hero / cover
 * Ported from inspiro-patterns/includes/patterns/hero-cover-cta.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'hero',
	'variant'     => 'cover',
	'description' => 'Full-width photo cover with dark gradient, large heading, subtext and outline button',
	'images'      => 1,
	'image_query' => '',
	'slots'       => array( 'heading', 'text', 'button_text' ),
	'items'       => 0,
	'item_fields' => array(),
	'defaults'    => array( 'button_text' => 'Learn More' ),
	'markup'      => <<<'HTML'
<!-- wp:cover {"url":"{{image_1}}","dimRatio":60,"minHeight":549,"minHeightUnit":"px","customGradient":"linear-gradient(180deg,rgba(255,255,255,0) 14%,rgb(0,0,0) 83%)","contentPosition":"center center","align":"full"} -->
<div class="wp-block-cover alignfull" style="min-height:549px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-60 has-background-dim wp-block-cover__gradient-background has-background-gradient" style="background:linear-gradient(180deg,rgba(255,255,255,0) 14%,rgb(0,0,0) 83%)"></span><img class="wp-block-cover__image-background" alt="" src="{{image_1}}" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:group {"layout":{"type":"constrained","contentSize":"1200px"}} -->
<div class="wp-block-group"><!-- wp:spacer {"height":"88px"} -->
<div style="height:88px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:heading {"level":1,"textColor":"white","fontSize":"max-48"} -->
<h1 class="wp-block-heading has-white-color has-text-color has-max-48-font-size">{{heading}}</h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"white"} -->
<p class="has-white-color has-text-color">{{text}}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"textColor":"white","className":"is-style-outline","style":{"border":{"radius":"0px"},"typography":{"textTransform":"uppercase"},"elements":{"link":{"color":{"text":"var:preset|color|white"}}}}} -->
<div class="wp-block-button is-style-outline" style="text-transform:uppercase"><a class="wp-block-button__link has-white-color has-text-color has-link-color wp-element-button" href="{{button_url}}" style="border-radius:0px">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->

<!-- wp:spacer {"height":"63px"} -->
<div style="height:63px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group --></div></div>
<!-- /wp:cover -->
HTML,
);

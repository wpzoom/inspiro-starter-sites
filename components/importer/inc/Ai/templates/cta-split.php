<?php
/**
 * AI section template: cta / split
 * Ported from inspiro-patterns/includes/patterns/cta-image-split.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'cta',
	'variant'     => 'split',
	'description' => 'Dark rounded call-to-action panel with a square image on the left and heading, text and white button on the right',
	'images'      => 1,
	'image_query' => '',
	'slots'       => array( 'heading', 'text', 'button_text' ),
	'items'       => 0,
	'item_fields' => array(),
	'defaults'    => array( 'button_text' => 'Get Started' ),
	'markup'      => <<<'HTML'
<!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull"><!-- wp:columns {"style":{"color":{"background":"#014b4b"},"spacing":{"padding":{"top":"var:preset|spacing|small","bottom":"var:preset|spacing|small","left":"var:preset|spacing|small","right":"var:preset|spacing|small"},"blockGap":{"top":"var:preset|spacing|small","left":"var:preset|spacing|medium"}},"border":{"radius":"20px"}}} -->
<div class="wp-block-columns has-background" style="border-radius:20px;background-color:#014b4b;padding-top:var(--wp--preset--spacing--small);padding-right:var(--wp--preset--spacing--small);padding-bottom:var(--wp--preset--spacing--small);padding-left:var(--wp--preset--spacing--small)"><!-- wp:column {"width":"37%","layout":{"type":"constrained"}} -->
<div class="wp-block-column" style="flex-basis:37%"><!-- wp:image {"aspectRatio":"1","scale":"cover","sizeSlug":"full","linkDestination":"none","style":{"border":{"radius":"20px"}}} -->
<figure class="wp-block-image size-full has-custom-border"><img src="{{image_1}}" alt="" style="border-radius:20px;aspect-ratio:1;object-fit:cover"/></figure>
<!-- /wp:image --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","width":"61%","layout":{"type":"constrained"}} -->
<div class="wp-block-column is-vertically-aligned-center" style="flex-basis:61%"><!-- wp:heading {"style":{"elements":{"link":{"color":{"text":"var:preset|color|white"}}}},"textColor":"white","fontSize":"large"} -->
<h2 class="wp-block-heading has-white-color has-text-color has-link-color has-large-font-size">{{heading}}</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"white"} -->
<p class="has-white-color has-text-color">{{text}}</p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"var:preset|spacing|x-small"} -->
<div style="height:var(--wp--preset--spacing--x-small)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:buttons {"style":{"spacing":{"blockGap":{"left":"var:preset|spacing|small"}}}} -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"white","style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"}}},"border":{"radius":"3px","width":"1px","style":"solid"},"spacing":{"padding":{"left":"var:preset|spacing|x-small","right":"var:preset|spacing|x-small"}}},"fontSize":"small","borderColor":"white"} -->
<div class="wp-block-button has-custom-font-size has-small-font-size"><a class="wp-block-button__link has-white-background-color has-text-color has-background has-link-color has-border-color has-white-border-color wp-element-button" href="{{button_url}}" style="border-style:solid;border-width:1px;border-radius:3px;color:#111a2b;padding-right:var(--wp--preset--spacing--x-small);padding-left:var(--wp--preset--spacing--x-small)"><strong>{{button_text}}</strong></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group -->
HTML,
);

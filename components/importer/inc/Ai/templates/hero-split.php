<?php
/**
 * AI section template: hero / split
 * Ported from inspiro-patterns/includes/patterns/hero-coach-split.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'hero',
	'variant'     => 'split',
	'description' => 'Split hero with heading, text and button on the left and a rounded photo cover with a floating intro card on the right',
	'images'      => 1,
	'image_query' => '',
	'slots'       => array( 'heading', 'text', 'intro', 'button_text' ),
	'items'       => 0,
	'item_fields' => array(),
	'defaults'    => array( 'button_text' => 'Get Started' ),
	'markup'      => <<<'HTML'
<!-- wp:group {"align":"full","style":{"color":{"background":"#f6f8fb"},"spacing":{"padding":{"top":"0","bottom":"0","left":"var:preset|spacing|x-small","right":"var:preset|spacing|x-small"},"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-background" style="background-color:#f6f8fb;margin-top:0;margin-bottom:0;padding-top:0;padding-right:var(--wp--preset--spacing--x-small);padding-bottom:0;padding-left:var(--wp--preset--spacing--x-small)"><!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:columns {"verticalAlignment":"center","style":{"spacing":{"blockGap":{"top":"var:preset|spacing|x-small","left":"var:preset|spacing|large"}}}} -->
<div class="wp-block-columns are-vertically-aligned-center"><!-- wp:column {"verticalAlignment":"center","style":{"spacing":{"blockGap":"var:preset|spacing|small"}},"layout":{"type":"constrained"}} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:heading {"level":1,"style":{"color":{"text":"#004b3e"},"elements":{"link":{"color":{"text":"#004b3e"}}},"typography":{"fontStyle":"normal","fontWeight":"1000"}},"fontSize":"x-large"} -->
<h1 class="wp-block-heading has-text-color has-link-color has-x-large-font-size" style="color:#004b3e;font-style:normal;font-weight:1000"><strong>{{heading}}</strong></h1>
<!-- /wp:heading -->

<!-- wp:paragraph {"style":{"color":{"text":"#626c82"},"elements":{"link":{"color":{"text":"#626c82"}}}}} -->
<p class="has-text-color has-link-color" style="color:#626c82">{{text}}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"textColor":"white","style":{"color":{"background":"#014b4b"},"elements":{"link":{"color":{"text":"var:preset|color|white"}}},"border":{"radius":"3px"},"spacing":{"padding":{"left":"var:preset|spacing|x-small","right":"var:preset|spacing|x-small"}}},"fontSize":"small"} -->
<div class="wp-block-button has-custom-font-size has-small-font-size"><a class="wp-block-button__link has-white-color has-text-color has-background has-link-color wp-element-button" href="{{button_url}}" style="border-radius:3px;background-color:#014b4b;padding-right:var(--wp--preset--spacing--x-small);padding-left:var(--wp--preset--spacing--x-small)"><strong>{{button_text}}</strong></a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column -->

<!-- wp:column {"verticalAlignment":"center","layout":{"type":"constrained"}} -->
<div class="wp-block-column is-vertically-aligned-center"><!-- wp:cover {"url":"{{image_1}}","dimRatio":0,"focalPoint":{"x":0.44,"y":0.07},"minHeight":500,"contentPosition":"bottom center","style":{"spacing":{"padding":{"top":"var:preset|spacing|x-small","bottom":"var:preset|spacing|x-small","left":"var:preset|spacing|x-small","right":"var:preset|spacing|x-small"}},"border":{"radius":{"topLeft":"80px","topRight":"0px","bottomLeft":"0px","bottomRight":"80px"}}},"layout":{"type":"default"}} -->
<div class="wp-block-cover has-custom-content-position is-position-bottom-center" style="border-top-left-radius:80px;border-top-right-radius:0px;border-bottom-left-radius:0px;border-bottom-right-radius:80px;padding-top:var(--wp--preset--spacing--x-small);padding-right:var(--wp--preset--spacing--x-small);padding-bottom:var(--wp--preset--spacing--x-small);padding-left:var(--wp--preset--spacing--x-small);min-height:500px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-0 has-background-dim"></span><img class="wp-block-cover__image-background" alt="" src="{{image_1}}" style="object-position:44% 7%" data-object-fit="cover" data-object-position="44% 7%"/><div class="wp-block-cover__inner-container"><!-- wp:columns {"verticalAlignment":"bottom"} -->
<div class="wp-block-columns are-vertically-aligned-bottom"><!-- wp:column {"verticalAlignment":"bottom","width":"85%","style":{"border":{"radius":"20px"},"spacing":{"padding":{"top":"var:preset|spacing|x-small","bottom":"var:preset|spacing|x-small","left":"var:preset|spacing|small","right":"var:preset|spacing|small"}},"shadow":"var:preset|shadow|natural"},"backgroundColor":"white","layout":{"type":"constrained","justifyContent":"left"}} -->
<div class="wp-block-column is-vertically-aligned-bottom has-white-background-color has-background" style="border-radius:20px;padding-top:var(--wp--preset--spacing--x-small);padding-right:var(--wp--preset--spacing--small);padding-bottom:var(--wp--preset--spacing--x-small);padding-left:var(--wp--preset--spacing--small);box-shadow:var(--wp--preset--shadow--natural);flex-basis:85%"><!-- wp:heading {"level":4,"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"}}},"spacing":{"padding":{"top":"0","bottom":"0"},"margin":{"top":"0","bottom":"0"}}}} -->
<h4 class="wp-block-heading has-text-color has-link-color" style="color:#111a2b;margin-top:0;margin-bottom:0;padding-top:0;padding-bottom:0">{{intro}}</h4>
<!-- /wp:heading --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group -->
HTML,
);

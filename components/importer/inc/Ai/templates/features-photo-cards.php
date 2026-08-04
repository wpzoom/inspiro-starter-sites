<?php
/**
 * AI section template: features / photo-cards
 * Ported from inspiro-patterns/includes/patterns/services-covers.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'features',
	'variant'     => 'photo-cards',
	'description' => 'Kicker, heading and intro text above three dark photo overlay cards, each with a title, short text and white button',
	'images'      => 3,
	'image_query' => '',
	'slots'       => array( 'heading', 'intro', 'text', 'button_text' ),
	'items'       => 3,
	'item_fields' => array( 'title', 'text' ),
	'defaults'    => array( 'button_text' => 'Learn More' ),
	'markup'      => <<<'HTML'
<!-- wp:group {"align":"full","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull"><!-- wp:spacer -->
<div style="height:100px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:paragraph {"align":"left","style":{"typography":{"letterSpacing":"1px"},"elements":{"link":{"color":{"text":"#0cb3a8"}}},"color":{"text":"#0cb3a8"}}} -->
<p class="has-text-align-left has-text-color has-link-color" style="color:#0cb3a8;letter-spacing:1px">{{intro}}</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"textAlign":"left","fontSize":"large"} -->
<h2 class="wp-block-heading has-text-align-left has-large-font-size">{{heading}}</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"left"} -->
<p class="has-text-align-left">{{text}}</p>
<!-- /wp:paragraph -->

<!-- wp:spacer {"height":"23px"} -->
<div style="height:23px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column {"style":{"spacing":{"padding":{"top":"0","right":"0","bottom":"0","left":"0"}}}} -->
<div class="wp-block-column" style="padding-top:0;padding-right:0;padding-bottom:0;padding-left:0"><!-- wp:cover {"url":"{{image_1}}","dimRatio":70,"customOverlayColor":"#000000","isUserOverlayColor":true,"contentPosition":"bottom left","style":{"spacing":{"padding":{"top":"var:preset|spacing|small","right":"var:preset|spacing|small","bottom":"var:preset|spacing|small","left":"var:preset|spacing|small"}}}} -->
<div class="wp-block-cover has-custom-content-position is-position-bottom-left" style="padding-top:var(--wp--preset--spacing--small);padding-right:var(--wp--preset--spacing--small);padding-bottom:var(--wp--preset--spacing--small);padding-left:var(--wp--preset--spacing--small)"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-70 has-background-dim" style="background-color:#000000"></span><img class="wp-block-cover__image-background" alt="" src="{{image_1}}" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"left":"0","top":"0","right":"0","bottom":"0"}},"typography":{"fontSize":"28px"}},"textColor":"white"} -->
<h3 class="wp-block-heading has-white-color has-text-color" style="margin-top:0;margin-right:0;margin-bottom:0;margin-left:0;font-size:28px">{{item_1_title}}</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"white","fontSize":"small"} -->
<p class="has-white-color has-text-color has-small-font-size">{{item_1_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"white","textColor":"secondary","className":"is-style-fill","style":{"elements":{"link":{"color":{"text":"var:preset|color|secondary"}}},"border":{"radius":"5px"}}} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link has-secondary-color has-white-background-color has-text-color has-background has-link-color wp-element-button" href="{{button_url}}" style="border-radius:5px">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column -->

<!-- wp:column {"style":{"spacing":{"padding":{"top":"0","right":"0","bottom":"0","left":"0"}}}} -->
<div class="wp-block-column" style="padding-top:0;padding-right:0;padding-bottom:0;padding-left:0"><!-- wp:cover {"url":"{{image_2}}","dimRatio":70,"customOverlayColor":"#000000","isUserOverlayColor":true,"contentPosition":"bottom left","style":{"spacing":{"padding":{"top":"var:preset|spacing|small","right":"var:preset|spacing|small","bottom":"var:preset|spacing|small","left":"var:preset|spacing|small"}}}} -->
<div class="wp-block-cover has-custom-content-position is-position-bottom-left" style="padding-top:var(--wp--preset--spacing--small);padding-right:var(--wp--preset--spacing--small);padding-bottom:var(--wp--preset--spacing--small);padding-left:var(--wp--preset--spacing--small)"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-70 has-background-dim" style="background-color:#000000"></span><img class="wp-block-cover__image-background" alt="" src="{{image_2}}" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"left":"0","top":"0","right":"0","bottom":"0"}},"typography":{"fontSize":"28px"}},"textColor":"white"} -->
<h3 class="wp-block-heading has-white-color has-text-color" style="margin-top:0;margin-right:0;margin-bottom:0;margin-left:0;font-size:28px">{{item_2_title}}</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"white","fontSize":"small"} -->
<p class="has-white-color has-text-color has-small-font-size">{{item_2_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"white","textColor":"secondary","className":"is-style-fill","style":{"elements":{"link":{"color":{"text":"var:preset|color|secondary"}}},"border":{"radius":"5px"}}} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link has-secondary-color has-white-background-color has-text-color has-background has-link-color wp-element-button" href="{{button_url}}" style="border-radius:5px">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column -->

<!-- wp:column {"style":{"spacing":{"padding":{"top":"0","right":"0","bottom":"0","left":"0"}}}} -->
<div class="wp-block-column" style="padding-top:0;padding-right:0;padding-bottom:0;padding-left:0"><!-- wp:cover {"url":"{{image_3}}","dimRatio":70,"customOverlayColor":"#000000","isUserOverlayColor":true,"contentPosition":"bottom left","style":{"spacing":{"padding":{"top":"var:preset|spacing|small","right":"var:preset|spacing|small","bottom":"var:preset|spacing|small","left":"var:preset|spacing|small"}}}} -->
<div class="wp-block-cover has-custom-content-position is-position-bottom-left" style="padding-top:var(--wp--preset--spacing--small);padding-right:var(--wp--preset--spacing--small);padding-bottom:var(--wp--preset--spacing--small);padding-left:var(--wp--preset--spacing--small)"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-70 has-background-dim" style="background-color:#000000"></span><img class="wp-block-cover__image-background" alt="" src="{{image_3}}" data-object-fit="cover"/><div class="wp-block-cover__inner-container"><!-- wp:heading {"level":3,"style":{"spacing":{"margin":{"left":"0","top":"0","right":"0","bottom":"0"}},"typography":{"fontSize":"28px"}},"textColor":"white"} -->
<h3 class="wp-block-heading has-white-color has-text-color" style="margin-top:0;margin-right:0;margin-bottom:0;margin-left:0;font-size:28px">{{item_3_title}}</h3>
<!-- /wp:heading -->

<!-- wp:paragraph {"textColor":"white","fontSize":"small"} -->
<p class="has-white-color has-text-color has-small-font-size">{{item_3_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"backgroundColor":"white","textColor":"secondary","className":"is-style-fill","style":{"elements":{"link":{"color":{"text":"var:preset|color|secondary"}}},"border":{"radius":"5px"}}} -->
<div class="wp-block-button is-style-fill"><a class="wp-block-button__link has-secondary-color has-white-background-color has-text-color has-background has-link-color wp-element-button" href="{{button_url}}" style="border-radius:5px">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div></div>
<!-- /wp:cover --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:spacer {"height":"88px"} -->
<div style="height:88px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group -->
HTML,
);

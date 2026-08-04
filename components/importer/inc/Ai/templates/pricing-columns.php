<?php
/**
 * AI section template: pricing / columns
 * Ported from inspiro-patterns/includes/patterns/pricing-table-alt.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'pricing',
	'variant'     => 'columns',
	'description' => 'Centered heading and intro above three bordered pricing columns with plan name, price, one text line and a button; the third plan is highlighted',
	'images'      => 0,
	'image_query' => '',
	'slots'       => array( 'heading', 'intro', 'button_text' ),
	'items'       => 3,
	'item_fields' => array( 'title', 'price', 'text' ),
	'defaults'    => array( 'button_text' => 'Get Started' ),
	'markup'      => <<<'HTML'
<!-- wp:group {"align":"wide","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignwide"><!-- wp:spacer {"height":"40px"} -->
<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:heading {"textAlign":"center"} -->
<h2 class="wp-block-heading has-text-align-center">{{heading}}</h2>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"spacing":{"margin":{"top":"1rem","bottom":"2.5rem"}}}} -->
<p class="has-text-align-center" style="margin-top:1rem;margin-bottom:2.5rem">{{intro}}</p>
<!-- /wp:paragraph -->

<!-- wp:columns {"align":"wide","style":{"spacing":{"margin":{"bottom":"0"}}}} -->
<div class="wp-block-columns alignwide" style="margin-bottom:0"><!-- wp:column {"width":"33%","style":{"color":{"text":"#000000"},"elements":{"link":{"color":{"text":"#000000"}}}}} -->
<div class="wp-block-column has-text-color has-link-color" style="color:#000000;flex-basis:33%"><!-- wp:columns {"style":{"spacing":{"padding":{"top":"2em","bottom":"2em","left":"2em","right":"2em"},"margin":{"top":"0","bottom":"0"}},"border":{"radius":"12px","color":"#3c21ff","width":"1px"}}} -->
<div class="wp-block-columns has-border-color" style="border-color:#3c21ff;border-width:1px;border-radius:12px;margin-top:0;margin-bottom:0;padding-top:2em;padding-right:2em;padding-bottom:2em;padding-left:2em"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"textAlign":"center","level":3,"style":{"color":{"text":"#3c21ff"},"elements":{"link":{"color":{"text":"#3c21ff"}}}},"fontSize":"large"} -->
<h3 class="wp-block-heading has-text-align-center has-text-color has-link-color has-large-font-size" style="color:#3c21ff"><strong>{{item_1_title}}</strong></h3>
<!-- /wp:heading -->

<!-- wp:heading {"textAlign":"center","level":4,"style":{"elements":{"link":{"color":{"text":"#3c21ff"}}},"color":{"text":"#3c21ff"}},"fontSize":"x-large"} -->
<h4 class="wp-block-heading has-text-align-center has-text-color has-link-color has-x-large-font-size" style="color:#3c21ff"><strong>{{item_1_price}}</strong></h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"lineHeight":"1.5"},"spacing":{"margin":{"top":"2.5rem","bottom":"2.5rem"}}},"fontSize":"medium"} -->
<p class="has-text-align-center has-medium-font-size" style="margin-top:2.5rem;margin-bottom:2.5rem;line-height:1.5">{{item_1_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"align":"full","layout":{"type":"flex","justifyContent":"center","orientation":"horizontal"}} -->
<div class="wp-block-buttons alignfull"><!-- wp:button {"textColor":"white","width":100,"style":{"color":{"background":"#3c21ff"},"border":{"radius":"8px"}}} -->
<div class="wp-block-button has-custom-width wp-block-button__width-100"><a class="wp-block-button__link has-white-color has-text-color has-background wp-element-button" href="{{button_url}}" style="border-radius:8px;background-color:#3c21ff">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"33%","style":{"color":{"text":"#000000"},"elements":{"link":{"color":{"text":"#000000"}}},"spacing":{"padding":{"top":"0em","right":"0em","bottom":"0em","left":"0em"}}}} -->
<div class="wp-block-column has-text-color has-link-color" style="color:#000000;padding-top:0em;padding-right:0em;padding-bottom:0em;padding-left:0em;flex-basis:33%"><!-- wp:columns {"style":{"spacing":{"padding":{"top":"2em","bottom":"2em","left":"2em","right":"2em"},"margin":{"top":"0","bottom":"0"}},"border":{"radius":"12px","color":"#3c21ff","width":"1px"}}} -->
<div class="wp-block-columns has-border-color" style="border-color:#3c21ff;border-width:1px;border-radius:12px;margin-top:0;margin-bottom:0;padding-top:2em;padding-right:2em;padding-bottom:2em;padding-left:2em"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"textAlign":"center","level":3,"style":{"color":{"text":"#3c21ff"},"elements":{"link":{"color":{"text":"#3c21ff"}}}},"fontSize":"large"} -->
<h3 class="wp-block-heading has-text-align-center has-text-color has-link-color has-large-font-size" style="color:#3c21ff"><strong>{{item_2_title}}</strong></h3>
<!-- /wp:heading -->

<!-- wp:heading {"textAlign":"center","level":4,"style":{"color":{"text":"#3c21ff"},"elements":{"link":{"color":{"text":"#3c21ff"}}}},"fontSize":"x-large"} -->
<h4 class="wp-block-heading has-text-align-center has-text-color has-link-color has-x-large-font-size" style="color:#3c21ff"><strong>{{item_2_price}}</strong></h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"lineHeight":"1.5"},"spacing":{"margin":{"top":"2.5rem","bottom":"2.5rem"}}},"fontSize":"medium"} -->
<p class="has-text-align-center has-medium-font-size" style="margin-top:2.5rem;margin-bottom:2.5rem;line-height:1.5">{{item_2_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"align":"full","layout":{"type":"flex","justifyContent":"center","orientation":"horizontal"}} -->
<div class="wp-block-buttons alignfull"><!-- wp:button {"textColor":"white","width":100,"style":{"color":{"background":"#3c21ff"},"border":{"radius":"8px"}}} -->
<div class="wp-block-button has-custom-width wp-block-button__width-100"><a class="wp-block-button__link has-white-color has-text-color has-background wp-element-button" href="{{button_url}}" style="border-radius:8px;background-color:#3c21ff">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:column -->

<!-- wp:column {"width":"33%","style":{"color":{"text":"#000000"},"elements":{"link":{"color":{"text":"#000000"}}},"spacing":{"padding":{"top":"0em","right":"0em","bottom":"0em","left":"0em"},"blockGap":"0"}}} -->
<div class="wp-block-column has-text-color has-link-color" style="color:#000000;padding-top:0em;padding-right:0em;padding-bottom:0em;padding-left:0em;flex-basis:33%"><!-- wp:columns {"style":{"spacing":{"padding":{"top":"2em","bottom":"2em","left":"2em","right":"2em"},"margin":{"top":"0","bottom":"0"}},"border":{"width":"1px","radius":"12px"},"color":{"background":"#3c21ff"},"elements":{"link":{"color":{"text":"var:preset|color|white"},":hover":{"color":{"text":"#101014"}}}}},"textColor":"white"} -->
<div class="wp-block-columns has-white-color has-text-color has-background has-link-color" style="border-width:1px;border-radius:12px;background-color:#3c21ff;margin-top:0;margin-bottom:0;padding-top:2em;padding-right:2em;padding-bottom:2em;padding-left:2em"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:heading {"textAlign":"center","level":3,"style":{"elements":{"link":{"color":{"text":"var:preset|color|white"}}}},"textColor":"white","fontSize":"large"} -->
<h3 class="wp-block-heading has-text-align-center has-white-color has-text-color has-link-color has-large-font-size"><strong>{{item_3_title}}</strong></h3>
<!-- /wp:heading -->

<!-- wp:heading {"textAlign":"center","level":4,"style":{"elements":{"link":{"color":{"text":"var:preset|color|white"}}}},"textColor":"white","fontSize":"x-large"} -->
<h4 class="wp-block-heading has-text-align-center has-white-color has-text-color has-link-color has-x-large-font-size"><strong>{{item_3_price}}</strong></h4>
<!-- /wp:heading -->

<!-- wp:paragraph {"align":"center","style":{"typography":{"lineHeight":"1.5"},"spacing":{"margin":{"top":"2.5rem","bottom":"2.5rem"}}},"fontSize":"medium"} -->
<p class="has-text-align-center has-medium-font-size" style="margin-top:2.5rem;margin-bottom:2.5rem;line-height:1.5">{{item_3_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"align":"full","layout":{"type":"flex","justifyContent":"center","orientation":"horizontal"}} -->
<div class="wp-block-buttons alignfull"><!-- wp:button {"backgroundColor":"white","width":100,"style":{"color":{"text":"#101014"},"border":{"radius":"8px"}}} -->
<div class="wp-block-button has-custom-width wp-block-button__width-100"><a class="wp-block-button__link has-white-background-color has-text-color has-background wp-element-button" href="{{button_url}}" style="border-radius:8px;color:#101014">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:spacer {"height":"40px"} -->
<div style="height:40px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group -->
HTML,
);

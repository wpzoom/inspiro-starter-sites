<?php
/**
 * AI section template: cta / centered
 * Ported from inspiro-patterns/includes/patterns/cta-centered-buttons.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'cta',
	'variant'     => 'centered',
	'description' => 'Centered call-to-action band with heading, short paragraph and a single dark button',
	'images'      => 0,
	'image_query' => '',
	'slots'       => array( 'heading', 'text', 'button_text' ),
	'items'       => 0,
	'item_fields' => array(),
	'defaults'    => array( 'button_text' => 'Get Started' ),
	'markup'      => <<<'HTML'
<!-- wp:group {"align":"full","style":{"spacing":{"margin":{"top":"0px","bottom":"0px"},"padding":{"top":"var:preset|spacing|x-large","bottom":"var:preset|spacing|x-large"}}},"backgroundColor":"background","layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull has-background-background-color has-background" style="margin-top:0px;margin-bottom:0px;padding-top:var(--wp--preset--spacing--x-large);padding-bottom:var(--wp--preset--spacing--x-large)"><!-- wp:group {"align":"wide","style":{"spacing":{"blockGap":"0px","padding":{"top":"var:preset|spacing|medium","bottom":"var:preset|spacing|medium","left":"var:preset|spacing|small","right":"var:preset|spacing|small"}}},"layout":{"type":"default"}} -->
<div class="wp-block-group alignwide" style="padding-top:var(--wp--preset--spacing--medium);padding-right:var(--wp--preset--spacing--small);padding-bottom:var(--wp--preset--spacing--medium);padding-left:var(--wp--preset--spacing--small)"><!-- wp:heading {"textAlign":"center","fontSize":"large"} -->
<h2 class="wp-block-heading has-text-align-center has-large-font-size">{{heading}}</h2>
<!-- /wp:heading -->

<!-- wp:columns -->
<div class="wp-block-columns"><!-- wp:column -->
<div class="wp-block-column"></div>
<!-- /wp:column -->

<!-- wp:column {"width":"720px"} -->
<div class="wp-block-column" style="flex-basis:720px"><!-- wp:paragraph {"align":"center","style":{"spacing":{"margin":{"top":"var:preset|spacing|small"}}}} -->
<p class="has-text-align-center" style="margin-top:var(--wp--preset--spacing--small)">{{text}}</p>
<!-- /wp:paragraph -->

<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"var:preset|spacing|medium"}}},"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons" style="margin-top:var(--wp--preset--spacing--medium)"><!-- wp:button {"style":{"color":{"background":"#101014"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link has-background wp-element-button" href="{{button_url}}" style="background-color:#101014">{{button_text}}</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"></div>
<!-- /wp:column --></div>
<!-- /wp:columns --></div>
<!-- /wp:group --></div>
<!-- /wp:group -->
HTML,
);

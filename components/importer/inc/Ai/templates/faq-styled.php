<?php
/**
 * AI section template: faq / styled
 * Ported from inspiro-patterns/includes/patterns/faq-styled-accordion.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'faq',
	'variant'     => 'styled',
	'description' => 'FAQ section with an uppercase kicker, large heading and five styled question-and-answer accordions',
	'images'      => 0,
	'image_query' => '',
	'slots'       => array( 'heading', 'intro' ),
	'items'       => 5,
	'item_fields' => array( 'title', 'text' ),
	'defaults'    => array( 'intro' => 'FAQ' ),
	'markup'      => <<<'HTML'
<!-- wp:group {"layout":{"type":"constrained"}} -->
<div class="wp-block-group"><!-- wp:spacer {"height":"var:preset|spacing|large"} -->
<div style="height:var(--wp--preset--spacing--large)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:group {"style":{"spacing":{"padding":{"top":"var:preset|spacing|x-small","bottom":"var:preset|spacing|x-small","left":"0","right":"0"}}},"layout":{"type":"flex","flexWrap":"nowrap"}} -->
<div class="wp-block-group" style="padding-top:var(--wp--preset--spacing--x-small);padding-right:0;padding-bottom:var(--wp--preset--spacing--x-small);padding-left:0"><!-- wp:separator {"className":"is-style-default","style":{"color":{"background":"#82be11"},"layout":{"selfStretch":"fixed","flexSize":"50px"},"spacing":{"margin":{"top":"var:preset|spacing|x-small","bottom":"var:preset|spacing|x-small"}}}} -->
<hr class="wp-block-separator has-text-color has-alpha-channel-opacity has-background is-style-default" style="margin-top:var(--wp--preset--spacing--x-small);margin-bottom:var(--wp--preset--spacing--x-small);background-color:#82be11;color:#82be11"/>
<!-- /wp:separator -->

<!-- wp:paragraph {"style":{"color":{"text":"#82be11"},"elements":{"link":{"color":{"text":"#82be11"}}},"typography":{"textTransform":"uppercase"},"spacing":{"margin":{"bottom":"0"}}},"fontSize":"medium"} -->
<p class="has-text-color has-link-color has-medium-font-size" style="color:#82be11;margin-bottom:0;text-transform:uppercase">{{intro}}</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->

<!-- wp:heading {"style":{"color":{"text":"#014b4b"},"elements":{"link":{"color":{"text":"#014b4b"}}},"spacing":{"padding":{"bottom":"var:preset|spacing|x-small"}}},"fontSize":"large"} -->
<h2 class="wp-block-heading has-text-color has-link-color has-large-font-size" style="color:#014b4b;padding-bottom:var(--wp--preset--spacing--x-small)">{{heading}}</h2>
<!-- /wp:heading -->

<!-- wp:group {"style":{"spacing":{"padding":{"right":"var:preset|spacing|x-small","left":"var:preset|spacing|x-small"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="padding-right:var(--wp--preset--spacing--x-small);padding-left:var(--wp--preset--spacing--x-small)"><!-- wp:details {"showContent":true,"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"},":hover":{"color":{"text":"#014b4b"}}}},"typography":{"fontStyle":"normal","fontWeight":"700"}},"fontSize":"medium"} -->
<details class="wp-block-details has-text-color has-link-color has-medium-font-size" style="color:#111a2b;font-style:normal;font-weight:700" open><summary><a>{{item_1_title}}</a></summary><!-- wp:paragraph {"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"}}},"typography":{"fontStyle":"normal","fontWeight":"400"}}} -->
<p class="has-text-color has-link-color" style="color:#111a2b;font-style:normal;font-weight:400">{{item_1_text}}</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details {"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"},":hover":{"color":{"text":"#014b4b"}}}},"typography":{"fontStyle":"normal","fontWeight":"700"}},"fontSize":"medium"} -->
<details class="wp-block-details has-text-color has-link-color has-medium-font-size" style="color:#111a2b;font-style:normal;font-weight:700"><summary><a>{{item_2_title}}</a></summary><!-- wp:paragraph {"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"}}},"typography":{"fontStyle":"normal","fontWeight":"400"}}} -->
<p class="has-text-color has-link-color" style="color:#111a2b;font-style:normal;font-weight:400">{{item_2_text}}</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details {"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"},":hover":{"color":{"text":"#014b4b"}}}},"typography":{"fontStyle":"normal","fontWeight":"700"}},"fontSize":"medium"} -->
<details class="wp-block-details has-text-color has-link-color has-medium-font-size" style="color:#111a2b;font-style:normal;font-weight:700"><summary><a>{{item_3_title}}</a></summary><!-- wp:paragraph {"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"}}},"typography":{"fontStyle":"normal","fontWeight":"400"}}} -->
<p class="has-text-color has-link-color" style="color:#111a2b;font-style:normal;font-weight:400">{{item_3_text}}</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details {"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"},":hover":{"color":{"text":"#014b4b"}}}},"typography":{"fontStyle":"normal","fontWeight":"700"}},"fontSize":"medium"} -->
<details class="wp-block-details has-text-color has-link-color has-medium-font-size" style="color:#111a2b;font-style:normal;font-weight:700"><summary><a>{{item_4_title}}</a></summary><!-- wp:paragraph {"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"}}},"typography":{"fontStyle":"normal","fontWeight":"400"}}} -->
<p class="has-text-color has-link-color" style="color:#111a2b;font-style:normal;font-weight:400">{{item_4_text}}</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details -->

<!-- wp:details {"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"},":hover":{"color":{"text":"#014b4b"}}}},"typography":{"fontStyle":"normal","fontWeight":"700"}},"fontSize":"medium"} -->
<details class="wp-block-details has-text-color has-link-color has-medium-font-size" style="color:#111a2b;font-style:normal;font-weight:700"><summary><a>{{item_5_title}}</a></summary><!-- wp:paragraph {"style":{"color":{"text":"#111a2b"},"elements":{"link":{"color":{"text":"#111a2b"}}},"typography":{"fontStyle":"normal","fontWeight":"400"}}} -->
<p class="has-text-color has-link-color" style="color:#111a2b;font-style:normal;font-weight:400">{{item_5_text}}</p>
<!-- /wp:paragraph --></details>
<!-- /wp:details --></div>
<!-- /wp:group -->

<!-- wp:spacer {"height":"var:preset|spacing|medium"} -->
<div style="height:var(--wp--preset--spacing--medium)" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group -->
HTML,
);

<?php
/**
 * AI section template: quote / reviews
 * Ported from inspiro-patterns/includes/patterns/testimonials-reviews.php
 *
 * @package Inspiro Starter Sites
 */

defined( 'ABSPATH' ) || exit;

return array(
	'type'        => 'quote',
	'variant'     => 'reviews',
	'description' => 'Heading above three review columns, each with quote text, a round headshot, reviewer name, role line and five stars',
	'images'      => 3,
	'image_query' => 'professional portrait headshot',
	'slots'       => array( 'heading' ),
	'items'       => 3,
	'item_fields' => array( 'title', 'text', 'meta' ),
	'defaults'    => array(),
	'markup'      => <<<'HTML'
<!-- wp:group {"style":{"spacing":{"margin":{"top":"0","bottom":"0"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group" style="margin-top:0;margin-bottom:0"><!-- wp:spacer {"height":"61px"} -->
<div style="height:61px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:heading {"textAlign":"left","fontSize":"large"} -->
<h2 class="wp-block-heading has-text-align-left has-large-font-size">{{heading}}</h2>
<!-- /wp:heading -->

<!-- wp:spacer {"height":"30px"} -->
<div style="height:30px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer -->

<!-- wp:columns {"align":"wide"} -->
<div class="wp-block-columns alignwide"><!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>{{item_1_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:image {"width":"48px","aspectRatio":"1","scale":"cover","sizeSlug":"full","linkDestination":"none","style":{"border":{"radius":"100px"}}} -->
<figure class="wp-block-image size-full is-resized has-custom-border"><img src="{{image_1}}" alt="" style="border-radius:100px;aspect-ratio:1;object-fit:cover;width:48px"/></figure>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p><strong>{{item_1_title}}</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">{{item_1_meta}}</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"#ffb700"}}},"color":{"text":"#ffb700"}}} -->
<p class="has-text-color has-link-color" style="color:#ffb700">★★★★★</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>{{item_2_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:image {"width":"48px","aspectRatio":"1","scale":"cover","sizeSlug":"full","linkDestination":"none","style":{"border":{"radius":"100px"}}} -->
<figure class="wp-block-image size-full is-resized has-custom-border"><img src="{{image_2}}" alt="" style="border-radius:100px;aspect-ratio:1;object-fit:cover;width:48px"/></figure>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p><strong>{{item_2_title}}</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">{{item_2_meta}}</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"#ffb700"}}},"color":{"text":"#ffb700"}}} -->
<p class="has-text-color has-link-color" style="color:#ffb700">★★★★★</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column"><!-- wp:paragraph -->
<p>{{item_3_text}}</p>
<!-- /wp:paragraph -->

<!-- wp:image {"width":"48px","aspectRatio":"1","scale":"cover","sizeSlug":"full","linkDestination":"none","style":{"border":{"radius":"100px"}}} -->
<figure class="wp-block-image size-full is-resized has-custom-border"><img src="{{image_3}}" alt="" style="border-radius:100px;aspect-ratio:1;object-fit:cover;width:48px"/></figure>
<!-- /wp:image -->

<!-- wp:paragraph -->
<p><strong>{{item_3_title}}</strong></p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"fontSize":"small"} -->
<p class="has-small-font-size">{{item_3_meta}}</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph {"style":{"elements":{"link":{"color":{"text":"#ffb700"}}},"color":{"text":"#ffb700"}}} -->
<p class="has-text-color has-link-color" style="color:#ffb700">★★★★★</p>
<!-- /wp:paragraph --></div>
<!-- /wp:column --></div>
<!-- /wp:columns -->

<!-- wp:spacer {"height":"59px"} -->
<div style="height:59px" aria-hidden="true" class="wp-block-spacer"></div>
<!-- /wp:spacer --></div>
<!-- /wp:group -->
HTML,
);

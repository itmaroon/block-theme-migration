<?php

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Plugin class was not loaded.' );
}

$registry = WP_Block_Type_Registry::get_instance();
foreach ( array( 'itmar/confirm-figure-block', 'itmar/thanks-figure-block' ) as $block_name ) {
	if ( ! $registry->is_registered( $block_name ) ) {
		register_block_type(
			$block_name,
			array(
				'attributes' => array(
					'displayMapping'  => array( 'type' => 'object', 'default' => (object) array() ),
					'sel_form_names'  => array( 'type' => 'array', 'default' => array() ),
					'inputValueMapping' => array( 'type' => 'object', 'default' => (object) array() ),
					'selectedSlug'    => array( 'type' => 'string', 'default' => '' ),
					'selectedPageUrl' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}
}

$source = 'fixed-page-for-contact-form-block';
$target = 'https://destination.example/contact-form-static-page/';
$map    = array(
	$source => array(
		'slug' => 'contact-form-static-page',
		'url'  => $target,
		'path' => '/contact-form-static-page/',
	),
);

$make_block = static function ( string $block_name, bool $include_array ) use ( $source ): string {
	// Simulate content already corrupted by an associative json_decode/json_encode round trip.
	$attrs = '{"displayMapping":[],"inputValueMapping":[]';
	if ( $include_array ) {
		$attrs .= ',"sel_form_names":[]';
	}
	$attrs .= ',"selectedSlug":"' . $source . '","selectedPageUrl":"https://develope-equip.local/' . $source . '/"}';
	$html_attrs = str_replace( '"', '&quot;', $attrs );
	return '<!-- wp:' . $block_name . ' ' . $attrs . ' -->'
		. '<div class="wp-block-' . str_replace( '/', '-', $block_name ) . '" data-attributes="' . $html_attrs . '"></div>'
		. '<!-- /wp:' . $block_name . ' -->';
};

$content = $make_block( 'itmar/confirm-figure-block', true ) . $make_block( 'itmar/thanks-figure-block', false );
$replace = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'replace_mapped_page_links' );
$result  = $replace->invoke( null, $content, $map, 'https://develope-equip.local/' );

if ( $result['count'] < 4 || str_contains( $result['content'], 'develope-equip.local' ) ) {
	throw new RuntimeException( 'The figure-block links were not rewritten.' );
}
if ( 2 !== substr_count( $result['content'], '"displayMapping":{}' ) || 2 !== substr_count( $result['content'], '&quot;displayMapping&quot;:{}' ) ) {
	throw new RuntimeException( 'displayMapping changed from an empty object to an empty array.' );
}
if ( 2 !== substr_count( $result['content'], '"inputValueMapping":{}' ) || 2 !== substr_count( $result['content'], '&quot;inputValueMapping&quot;:{}' ) ) {
	throw new RuntimeException( 'Another object-typed attribute changed from an empty object to an empty array.' );
}
if ( 1 !== substr_count( $result['content'], '"sel_form_names":[]' ) || 1 !== substr_count( $result['content'], '&quot;sel_form_names&quot;:[]' ) ) {
	throw new RuntimeException( 'An array-typed attribute did not remain an empty array.' );
}
if ( str_contains( $result['content'], '"displayMapping":[]' ) || str_contains( $result['content'], '&quot;displayMapping&quot;:[]' ) ) {
	throw new RuntimeException( 'An object-typed displayMapping was serialized as an array.' );
}

echo wp_json_encode(
	array(
		'confirmObjectShapePreserved' => true,
		'thanksObjectShapePreserved'  => true,
		'arrayShapePreserved'         => true,
	),
	JSON_PRETTY_PRINT
);

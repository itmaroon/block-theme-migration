<?php

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Plugin class was not loaded.' );
}

if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'itmar/design-button' ) ) {
	register_block_type(
		'itmar/design-button',
		array(
			'attributes' => array(
				'linkKind'       => array( 'type' => 'string', 'default' => 'none' ),
				'selectedSlug'    => array( 'type' => 'string', 'default' => '' ),
				'selectedPageUrl' => array( 'type' => 'string', 'default' => '' ),
				'disableOpacity'  => array( 'type' => 'number', 'default' => 0.7 ),
			),
		)
	);
}

$replace = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'replace_mapped_page_links' );
$source  = 'fixed-page-for-contact-form-block';
$target  = 'https://destination.example/contact-form-static-page/';
$unstable_float = '0.6999999999999999555910790149937383830547332763671875';
$content = '<!-- wp:itmar/design-button {"linkKind":"fixed","selectedSlug":"' . $source . '","selectedPageUrl":"https://develope-equip.local/' . $source . '/","disableOpacity":' . $unstable_float . '} -->'
	. '<div class="wp-block-itmar-design-button" data-attributes="{&quot;linkKind&quot;:&quot;fixed&quot;,&quot;selectedSlug&quot;:&quot;' . $source . '&quot;,&quot;selectedPageUrl&quot;:&quot;https://develope-equip.local/' . $source . '/&quot;,&quot;disableOpacity&quot;:' . $unstable_float . '}"><span data-attributes="{&quot;tooltip_text&quot;:&quot;keep-this&quot;}"><button data-back="fixed" data-selected_page="https://develope-equip.local/' . $source . '/">Contact</button></span></div>'
	. '<!-- /wp:itmar/design-button -->';
$previous_precision = ini_get( 'serialize_precision' );
ini_set( 'serialize_precision', '53' );
try {
	$result = $replace->invoke(
		null,
		$content,
		array(
			$source => array(
				'slug' => 'contact-form-static-page',
				'url'  => $target,
				'path' => '/contact-form-static-page/',
			),
		),
		'https://develope-equip.local/'
	);
} finally {
	ini_set( 'serialize_precision', (string) $previous_precision );
}

if ( $result['count'] < 2 || str_contains( $result['content'], 'develope-equip.local' ) ) {
	throw new RuntimeException( 'The design-button block attribute and saved data-selected_page were not rewritten together.' );
}
if ( ! str_contains( $result['content'], 'data-selected_page="' . esc_attr( $target ) . '"' ) ) {
	throw new RuntimeException( 'The saved design-button destination does not contain the destination URL.' );
}
if ( ! str_contains( $result['content'], '&quot;selectedSlug&quot;:&quot;contact-form-static-page&quot;' ) || ! str_contains( $result['content'], '&quot;selectedPageUrl&quot;:&quot;' . esc_attr( $target ) . '&quot;' ) ) {
	throw new RuntimeException( 'The design-button data-attributes JSON was not synchronized with its block attributes.' );
}
if ( ! str_contains( $result['content'], '&quot;tooltip_text&quot;:&quot;keep-this&quot;' ) ) {
	throw new RuntimeException( 'A nested tooltip data-attributes value was overwritten.' );
}
if ( ! str_contains( $result['content'], '"disableOpacity":0.7' ) || ! str_contains( $result['content'], '&quot;disableOpacity&quot;:0.7' ) || str_contains( $result['content'], '0.699999999999' ) ) {
	throw new RuntimeException( 'Block serialization changed the 0.7 numeric literal and would trigger block validation errors.' );
}

$precision_only = '<!-- wp:itmar/design-button {"disableOpacity":' . $unstable_float . '} -->'
	. '<div data-attributes="{&quot;disableOpacity&quot;:' . $unstable_float . '}"><button data-back="none">No link</button></div>'
	. '<!-- /wp:itmar/design-button -->';
$precision_result = $replace->invoke(
	null,
	$precision_only,
	array(
		$source => array(
			'slug' => 'contact-form-static-page',
			'url'  => $target,
			'path' => '/contact-form-static-page/',
		),
	),
	'https://develope-equip.local/'
);
if ( $precision_result['count'] < 1 || str_contains( $precision_result['content'], '0.699999999999' ) || ! str_contains( $precision_result['content'], '&quot;disableOpacity&quot;:0.7' ) ) {
	throw new RuntimeException( 'An already-mapped block was not repaired when only unstable JSON precision remained.' );
}

$option_name = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_mapping_option_name' );
$save        = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'save_mapping' );
$load        = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_saved_mapping' );
$name        = $option_name->invoke( null );
$sentinel    = new stdClass();
$previous    = get_option( $name, $sentinel );
$mapping     = array( 'page-source' => 'page|123' );

try {
	$save->invoke( null, $mapping );
	if ( $load->invoke( null ) !== $mapping ) {
		throw new RuntimeException( 'The theme-specific mapping selection was not persisted.' );
	}
} finally {
	if ( $previous === $sentinel ) {
		delete_option( $name );
	} else {
		update_option( $name, $previous, false );
	}
}

echo wp_json_encode(
	array(
		'designButtonLinksRewritten' => true,
		'themeMappingPersisted'      => true,
	),
	JSON_PRETTY_PRINT
);

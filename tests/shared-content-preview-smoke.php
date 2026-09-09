<?php

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Plugin class was not loaded.' );
}

$pages = get_pages( array( 'number' => 1 ) );
if ( empty( $pages ) ) {
	throw new RuntimeException( 'A destination page is required.' );
}

$pattern_manifest_method  = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_editable_pattern_manifest' );
$template_manifest_method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_template_manifest' );
$manifest = array(
	'theme'            => array( 'slug' => get_stylesheet() ),
	'sourceSite'       => array( 'homeUrl' => home_url( '/' ) ),
	'editablePatterns' => $pattern_manifest_method->invoke( null, array() ),
	'templates'        => $template_manifest_method->invoke( null ),
);

$mapping = array(
	'page-fixed-page-for-contact-form-block' => 'page|' . $pages[0]->ID,
	'page-e-commerce-site-block-test-page'   => 'page|' . $pages[0]->ID,
);
$map_method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'build_page_link_map' );
$link_map   = $map_method->invoke( null, $mapping );
$successful_mapping = array(
	'page-fixed-page-for-contact-form-block' => $mapping['page-fixed-page-for-contact-form-block'],
);
$shared_mapping_method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_shared_mapping' );
$preview_mapping       = $shared_mapping_method->invoke( null, $mapping, $successful_mapping, false );
$apply_mapping         = $shared_mapping_method->invoke( null, $mapping, $successful_mapping, true );
if ( $preview_mapping !== $mapping || $apply_mapping !== $successful_mapping ) {
	throw new RuntimeException( 'Shared-content mapping was not limited to successfully applied mappings.' );
}
$successful_link_map = $map_method->invoke( null, $apply_mapping );
if ( count( $successful_link_map ) !== 1 || isset( $successful_link_map['e-commerce-site-block-test-page'] ) ) {
	throw new RuntimeException( 'Failed mappings were not excluded from the shared-content link map.' );
}
$pattern_method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_theme_pattern_id_map' );
$pattern_refs   = $pattern_method->invoke( null, $manifest['editablePatterns'] ?? array() );
$shared_method  = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'process_shared_mapping_content' );
$result         = $shared_method->invoke(
	null,
	$manifest,
	$mapping,
	$link_map,
	$manifest['sourceSite']['homeUrl'] ?? '',
	false,
	false,
	$pattern_refs
);

if ( ! empty( $result['hasError'] ) || empty( $result['items'] ) ) {
	throw new RuntimeException( 'Shared theme content did not produce a clean mapping preview.' );
}

$preview = implode( "\n", $result['items'] );
if ( ! str_contains( $preview, 'Template part' ) && ! str_contains( $preview, 'Editable pattern' ) && ! str_contains( $preview, 'Automatic template' ) ) {
	throw new RuntimeException( 'No shared-content type was detected in the preview.' );
}

echo wp_json_encode( array( 'items' => count( $result['items'] ), 'sharedContentDetected' => true ), JSON_PRETTY_PRINT );

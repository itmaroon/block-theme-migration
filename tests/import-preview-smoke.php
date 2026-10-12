<?php

$templates = get_block_templates();
$pages     = get_pages( array( 'number' => 1 ) );

if ( ! $templates || ! $pages ) {
	throw new RuntimeException( 'Template or page test data is missing.' );
}

$automatic_check = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'is_automatically_assigned_template' );
$assignable_template = null;
foreach ( $templates as $template ) {
	if ( ! $automatic_check->invoke( null, $template->slug ) ) {
		$assignable_template = $template;
		break;
	}
}
if ( ! $assignable_template ) {
	throw new RuntimeException( 'No manually assignable template is available for the mapping test.' );
}

$sanitize = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'sanitize_manifest_templates' );
$sanitized = $sanitize->invoke(
	null,
	array(
		array(
			'slug'           => $assignable_template->slug,
			'title'          => $assignable_template->title,
			'allowedTargets' => array( 'page', 'unsupported' ),
		),
		array(
			'slug'           => 'front-page',
			'title'          => 'Front Page',
			'allowedTargets' => array( 'page' ),
		),
	)
);
if ( 1 !== count( $sanitized ) || array( 'page' ) !== $sanitized[0]['allowedTargets'] ) {
	throw new RuntimeException( 'Manifest target restrictions were not sanitized.' );
}

$sanitize_patterns = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'sanitize_editable_patterns' );
$editable = $sanitize_patterns->invoke(
	null,
	array(
		array(
			'sourceId'         => 100,
			'sourceKey'        => 'source-theme:100',
			'title'            => 'Synced test',
			'slug'             => 'synced-test',
			'postStatus'       => 'publish',
			'syncStatus'       => 'synced',
			'content'          => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
			'categories'       => array(),
			'themePatternSlug' => 'source-theme/synced-pattern-100',
		),
	),
	'source-theme'
);
if ( 1 !== count( $editable ) ) {
	throw new RuntimeException( 'Editable pattern manifest data was not accepted.' );
}

$replace = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'replace_pattern_references' );
$old_ref = '<!-- wp:block {"ref":100} /-->';
$new_ref = $replace->invoke( null, $old_ref, array( 100 => 900 ), array() );
if ( ! str_contains( $new_ref, '"ref":900' ) ) {
	throw new RuntimeException( 'Nested editable pattern IDs were not remapped.' );
}
$theme_ref = '<!-- wp:pattern {"slug":"source-theme/synced-pattern-100"} /-->';
$database_ref = $replace->invoke( null, $theme_ref, array(), array( 'source-theme/synced-pattern-100' => 900 ) );
if ( ! str_contains( $database_ref, 'wp:block' ) || ! str_contains( $database_ref, '"ref":900' ) ) {
	throw new RuntimeException( 'Theme pattern references were not reconnected to editable patterns.' );
}

$method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'map_one_template' );
$result = $method->invoke( null, $assignable_template->slug, 'page|' . $pages[0]->ID, false, false );

if ( is_wp_error( $result ) ) {
	throw new RuntimeException( $result->get_error_message() );
}

echo $result;

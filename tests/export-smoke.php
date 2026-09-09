<?php

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Plugin class was not loaded.' );
}

$filename = wp_generate_block_templates_export_file();
if ( is_wp_error( $filename ) ) {
	throw new RuntimeException( $filename->get_error_message() );
}

try {
	$templates = get_block_templates();
	if ( empty( $templates ) ) {
		throw new RuntimeException( 'No templates are available for the export test.' );
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
		throw new RuntimeException( 'No manually assignable template is available for the export test.' );
	}
	$assignable = array(
		array(
			'slug'           => $assignable_template->slug,
			'title'          => $assignable_template->title ?: $assignable_template->slug,
			'allowedTargets' => array( 'page' ),
		),
	);
	$original_post = $_POST;
	$_POST = array(
		'assignable'      => array( $assignable_template->slug => '1' ),
		'allowed_targets' => array( $assignable_template->slug => 'page' ),
	);
	$request_method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_requested_assignable_templates' );
	$requested = $request_method->invoke( null );
	$_POST = $original_post;
	if ( 1 !== count( $requested ) || array( 'page' ) !== $requested[0]['allowedTargets'] ) {
		throw new RuntimeException( 'The single radio-button destination type was not accepted.' );
	}
	$method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'enhance_export_zip' );
	$result = $method->invoke( null, $filename, $assignable );
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $filename ) ) {
		throw new RuntimeException( 'Could not reopen generated ZIP.' );
	}

	$manifest = json_decode( $zip->getFromName( 'block-theme-migration.json' ), true );
	$theme    = json_decode( $zip->getFromName( 'theme.json' ), true );
	$pattern_files = array();
	foreach ( $manifest['patterns'] ?? array() as $pattern ) {
		$pattern_files[] = $zip->getFromName( $pattern['file'] );
	}
	$zip->close();

	if ( ! is_array( $manifest ) || 4 !== ( $manifest['schemaVersion'] ?? null ) ) {
		throw new RuntimeException( 'Manifest was not generated.' );
	}
	if ( home_url( '/' ) !== ( $manifest['sourceSite']['homeUrl'] ?? '' ) ) {
		throw new RuntimeException( 'The source site URL needed for safe internal-link rewriting was not exported.' );
	}
	if ( ! isset( $manifest['assignableTemplates'] ) || ! is_array( $manifest['assignableTemplates'] ) ) {
		throw new RuntimeException( 'Assignable template metadata was not generated.' );
	}
	if ( 1 !== count( $manifest['assignableTemplates'] ) || array( 'page' ) !== $manifest['assignableTemplates'][0]['allowedTargets'] ) {
		throw new RuntimeException( 'Selected assignable template metadata was not preserved.' );
	}
	foreach ( $manifest['patterns'] ?? array() as $pattern ) {
		if ( ! preg_match( '#^patterns/[a-z0-9-]+\.php$#', $pattern['file'] ?? '' ) || str_contains( $pattern['slug'] ?? '', '%' ) ) {
			throw new RuntimeException( 'A generated pattern filename or slug is not ASCII.' );
		}
	}
	foreach ( $pattern_files as $pattern_file ) {
		if ( ! is_string( $pattern_file ) || ! str_contains( $pattern_file, 'Inserter: no' ) ) {
			throw new RuntimeException( 'A helper theme pattern is visible in the inserter.' );
		}
	}
	$source_patterns = get_posts( array( 'post_type' => 'wp_block', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1 ) );
	if ( count( $source_patterns ) !== count( $manifest['editablePatterns'] ?? array() ) ) {
		throw new RuntimeException( 'Not all editable database patterns were exported.' );
	}
	if ( ! isset( $theme['templateParts'] ) || ! is_array( $theme['templateParts'] ) ) {
		throw new RuntimeException( 'templateParts metadata was not generated.' );
	}

	echo wp_json_encode(
		array(
			'templates'     => count( $manifest['templates'] ?? array() ),
			'assignable'    => count( $manifest['assignableTemplates'] ?? array() ),
			'templateParts' => count( $manifest['templateParts'] ?? array() ),
			'patterns'      => count( $manifest['patterns'] ?? array() ),
			'editablePatterns' => count( $manifest['editablePatterns'] ?? array() ),
			'checks'        => count( $manifest['checks'] ?? array() ),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	);
} finally {
	if ( is_string( $filename ) && file_exists( $filename ) ) {
		unlink( $filename );
	}
}

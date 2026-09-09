<?php

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Plugin class was not loaded.' );
}

$filename = wp_generate_block_templates_export_file();
if ( is_wp_error( $filename ) ) {
	throw new RuntimeException( $filename->get_error_message() );
}

try {
	$automatic_check = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'is_automatically_assigned_template' );
	$source = null;
	foreach ( get_block_templates() as $template ) {
		if ( ! $automatic_check->invoke( null, $template->slug ) ) {
			$source = $template;
			break;
		}
	}
	if ( ! $source ) {
		throw new RuntimeException( 'No removable source template is available.' );
	}

	$enhance = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'enhance_export_zip' );
	$result = $enhance->invoke(
		null,
		$filename,
		array(
			array(
				'slug'           => $source->slug,
				'title'          => $source->title ?: $source->slug,
				'allowedTargets' => array( 'page' ),
			),
		),
		array( $source->slug )
	);
	if ( is_wp_error( $result ) ) {
		throw new RuntimeException( $result->get_error_message() );
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $filename ) ) {
		throw new RuntimeException( 'Could not reopen generated ZIP.' );
	}
	$entry    = 'templates/' . $source->slug . '.html';
	$manifest = json_decode( (string) $zip->getFromName( 'block-theme-migration.json' ), true );
	$theme    = json_decode( (string) $zip->getFromName( 'theme.json' ), true );
	if ( false !== $zip->locateName( $entry ) ) {
		throw new RuntimeException( 'The selected source template remains in the ZIP.' );
	}
	$zip->close();

	if ( ! in_array( $source->slug, $manifest['removedSourceTemplates'] ?? array(), true ) ) {
		throw new RuntimeException( 'The removed source was not recorded in the manifest.' );
	}
	if ( in_array( $source->slug, wp_list_pluck( $manifest['templates'] ?? array(), 'slug' ), true ) ) {
		throw new RuntimeException( 'The removed source remains in the template manifest.' );
	}
	if ( in_array( $source->slug, wp_list_pluck( $manifest['assignableTemplates'] ?? array(), 'slug' ), true ) ) {
		throw new RuntimeException( 'The removed source remains assignable.' );
	}
	if ( in_array( $source->slug, wp_list_pluck( $theme['customTemplates'] ?? array(), 'name' ), true ) ) {
		throw new RuntimeException( 'The removed source remains in theme.json customTemplates.' );
	}

	echo wp_json_encode(
		array(
			'removedEntry'    => $entry,
			'manifestCleaned' => true,
			'themeJsonCleaned' => true,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	);
} finally {
	if ( is_string( $filename ) && file_exists( $filename ) ) {
		unlink( $filename );
	}
}

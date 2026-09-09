<?php

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Plugin class was not loaded.' );
}

$slug      = 'btm-managed-' . wp_generate_uuid4();
$migration = array(
	'source'  => 'page-source-smoke',
	'target'  => 'page|999999',
	'package' => 'source-theme',
);
$post_id = 0;
$part_id = 0;

try {
	$save = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'save_template_override' );
	$first = $save->invoke(
		null,
		$slug,
		'Page: Smoke destination',
		'<!-- wp:paragraph --><p>First</p><!-- /wp:paragraph -->',
		false,
		$migration
	);
	if ( is_wp_error( $first ) ) {
		throw new RuntimeException( $first->get_error_message() );
	}
	$post_id = (int) $first;
	$second  = $save->invoke(
		null,
		$slug,
		'Page: Smoke destination',
		'<!-- wp:paragraph --><p>Second</p><!-- /wp:paragraph -->',
		false,
		$migration
	);
	if ( is_wp_error( $second ) || (int) $second !== $post_id ) {
		throw new RuntimeException( 'The same mapping did not update its managed wp_template record.' );
	}
	$post = get_post( $post_id );
	if ( ! $post || ! str_contains( $post->post_content, 'Second' ) ) {
		throw new RuntimeException( 'The managed template content was not updated.' );
	}
	if ( $migration['source'] !== get_post_meta( $post_id, '_itmar_btm_template_source', true )
		|| $migration['target'] !== get_post_meta( $post_id, '_itmar_btm_template_target', true )
		|| get_stylesheet() !== get_post_meta( $post_id, '_itmar_btm_template_theme', true ) ) {
		throw new RuntimeException( 'Managed template metadata was not saved.' );
	}

	$part_migration = array(
		'source'  => 'theme-part:smoke-part',
		'target'  => 'part|smoke-part',
		'package' => 'source-theme',
	);
	$save_part = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'save_template_part_override' );
	$first_part = $save_part->invoke( null, $slug . '-part', 'Smoke part', '<!-- wp:paragraph --><p>Part one</p><!-- /wp:paragraph -->', 'header', false, $part_migration );
	if ( is_wp_error( $first_part ) ) {
		throw new RuntimeException( $first_part->get_error_message() );
	}
	$part_id = (int) $first_part;
	$second_part = $save_part->invoke( null, $slug . '-part', 'Smoke part', '<!-- wp:paragraph --><p>Part two</p><!-- /wp:paragraph -->', 'header', false, $part_migration );
	if ( is_wp_error( $second_part ) || (int) $second_part !== $part_id ) {
		throw new RuntimeException( 'The same template-part mapping did not update its managed record.' );
	}

	echo wp_json_encode( array( 'samePostUpdated' => true, 'samePartUpdated' => true, 'metadataSaved' => true ), JSON_PRETTY_PRINT );
} finally {
	if ( $post_id ) {
		wp_delete_post( $post_id, true );
	}
	if ( $part_id ) {
		wp_delete_post( $part_id, true );
	}
	WP_Theme_JSON_Resolver::clean_cached_data();
}

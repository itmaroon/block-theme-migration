<?php

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Plugin class was not loaded.' );
}

$test_key = 'btm-smoke-' . wp_generate_uuid4();
$patterns = array(
	array(
		'sourceId'         => 910001,
		'sourceKey'        => $test_key . ':1',
		'title'            => 'BTM synced smoke pattern',
		'slug'             => $test_key . '-synced',
		'postStatus'       => 'publish',
		'syncStatus'       => 'synced',
		'content'          => '<!-- wp:block {"ref":910002} /-->',
		'categories'       => array(),
		'themePatternSlug' => '',
	),
	array(
		'sourceId'         => 910002,
		'sourceKey'        => $test_key . ':2',
		'title'            => 'BTM unsynced smoke pattern',
		'slug'             => $test_key . '-unsynced',
		'postStatus'       => 'publish',
		'syncStatus'       => 'unsynced',
		'content'          => '<!-- wp:paragraph --><p>Smoke test</p><!-- /wp:paragraph -->',
		'categories'       => array(),
		'themePatternSlug' => '',
	),
);

$created_ids = array();
try {
	$method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'import_editable_patterns' );
	$result = $method->invoke( null, $patterns );
	if ( ! empty( $result['hasError'] ) || 2 !== count( $result['sourceIdMap'] ?? array() ) ) {
		throw new RuntimeException( 'Pattern import returned an error or an incomplete ID map.' );
	}

	$created_ids = array_values( $result['sourceIdMap'] );
	$synced_id   = $result['sourceIdMap'][910001];
	$unsynced_id = $result['sourceIdMap'][910002];
	$synced      = get_post( $synced_id );
	if ( ! $synced || ! str_contains( $synced->post_content, '"ref":' . $unsynced_id ) ) {
		throw new RuntimeException( 'A nested synced pattern reference was not remapped to its destination ID.' );
	}
	if ( 'unsynced' !== get_post_meta( $unsynced_id, 'wp_pattern_sync_status', true ) ) {
		throw new RuntimeException( 'The unsynced pattern state was not restored.' );
	}
	if ( '' !== get_post_meta( $synced_id, 'wp_pattern_sync_status', true ) ) {
		throw new RuntimeException( 'The synced pattern state was not restored.' );
	}

	echo wp_json_encode(
		array(
			'imported'           => count( $created_ids ),
			'nestedRefRemapped' => true,
			'syncStatesRestored' => true,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
	);
} finally {
	foreach ( $created_ids as $post_id ) {
		wp_delete_post( $post_id, true );
	}
}


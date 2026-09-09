<?php

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Plugin class was not loaded.' );
}

if ( ! WP_Block_Type_Registry::get_instance()->is_registered( 'itmar/design-calender' ) ) {
	register_block_type(
		'itmar/design-calender',
		array(
			'attributes' => array(
				'dateSpan'      => array( 'type' => 'object' ),
				'selectedMonth' => array( 'type' => 'string' ),
			),
		)
	);
}

// Standalone fallback for testing Theme Migration's extension point without Block Collections loaded.
if ( ! function_exists( 'itmar_prepare_calendar_blocks_for_migration' ) ) {
	add_filter(
		'itmar_btm_prepare_blocks',
		static function ( array $blocks ): array {
		foreach ( $blocks as &$block ) {
			if ( 'itmar/design-calender' === ( $block['blockName'] ?? null ) ) {
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$html  = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
				if ( ! isset( $attrs['dateSpan'] ) && preg_match( '/\sdata-attributes\s*=\s*(["\'])(.*?)\1/is', $html, $match ) ) {
					$data = json_decode( html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' ), true );
					if ( is_array( $data['dateSpan'] ?? null ) ) {
						$attrs['dateSpan'] = $data['dateSpan'];
					}
				}
				if ( ! isset( $attrs['selectedMonth'] ) && preg_match( '/\sdata-selected_month\s*=\s*(["\'])(.*?)\1/is', $html, $match ) ) {
					$attrs['selectedMonth'] = html_entity_decode( $match[2], ENT_QUOTES, 'UTF-8' );
				}
				$block['attrs'] = $attrs;
			}
		}
		unset( $block );
		return $blocks;
		},
		10,
		1
	);
}

$legacy_content = '<!-- wp:itmar/design-calender -->'
	. '<div class="wp-block-itmar-design-calender" data-attributes="{&quot;dateSpan&quot;:{&quot;startYear&quot;:2023,&quot;startMonth&quot;:8,&quot;endYear&quot;:2027,&quot;endMonth&quot;:8}}" data-selected_month="2026/08"></div>'
	. '<!-- /wp:itmar/design-calender -->';

$replace = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'replace_mapped_page_links' );
$result  = $replace->invoke(
	null,
	$legacy_content,
	array(
		'unused-page' => array(
			'slug' => 'unused-destination',
			'url'  => 'https://destination.example/unused-destination/',
			'path' => '/unused-destination/',
		),
	),
	'https://develope-equip.local/'
);

if ( $result['count'] < 1 ) {
	throw new RuntimeException( 'Legacy calendar attributes were not hydrated from saved HTML.' );
}
if ( ! str_contains( $result['content'], '"dateSpan":{"startYear":2023,"startMonth":8,"endYear":2027,"endMonth":8}' ) ) {
	throw new RuntimeException( 'The saved calendar period was not copied into the block comment.' );
}
if ( ! str_contains( $result['content'], '"selectedMonth":"2026/08"' ) && ! str_contains( $result['content'], '"selectedMonth":"2026\/08"' ) ) {
	throw new RuntimeException( 'The saved selected month was not copied into the block comment.' );
}
if ( ! str_contains( $result['content'], '&quot;startMonth&quot;:8' ) || ! str_contains( $result['content'], 'data-selected_month="2026/08"' ) ) {
	throw new RuntimeException( 'Hydration changed the existing saved calendar HTML.' );
}

echo wp_json_encode(
	array(
		'dateSpanHydrated'      => true,
		'selectedMonthHydrated' => true,
		'savedHtmlPreserved'    => true,
	),
	JSON_PRETTY_PRINT
);

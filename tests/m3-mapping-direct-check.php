<?php
/**
 * 固定ページへの割り当て（Template Mapping）のテスト（M3）。
 * テーマの customTemplates に登録されたテンプレートは、コピーを作らず、ページのテンプレートの指定に、直接、設定すること。
 * 一時的な固定ページを 1 つ作り、最後に消す。ほかのものは変えない。
 */

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Itmar_Block_Theme_Migration was not loaded.' );
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$custom = array_keys( (array) WP_Theme_JSON_Resolver::get_theme_data()->get_custom_templates() );
$custom = array_values( array_filter( $custom, static fn( $slug ) => Itmar_Block_Theme_Migration::is_page_custom_template( $slug ) && get_block_file_template( get_stylesheet() . '//' . $slug, 'wp_template' ) ) );
if ( empty( $custom ) ) {
	echo "m3-mapping-direct-check: SKIPPED (the active theme has no page template in customTemplates)\n";
	return;
}
$source = $custom[0];

$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'BTM direct test', 'post_name' => 'btm-direct-test' ), true );
$assert( ! is_wp_error( $page_id ), 'could not create the test page' );
$spec  = 'page|' . $page_id;
$stylesheet = get_stylesheet();
$method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'map_one_template' );
$method->setAccessible( true );
$copy_exists = static function () use ( $stylesheet ): bool {
	$t = get_block_template( $stylesheet . '//page-btm-direct-test', 'wp_template' );
	return $t && ! empty( $t->wp_id );
};

try {
	// 1) プレビュー: 何も変えない
	$message = $method->invoke( null, $source, $spec, false, false, array(), $stylesheet );
	$assert( is_string( $message ) && false !== strpos( $message, 'directly' ), 'the preview must say the template is used directly: ' . wp_json_encode( $message ) );
	$assert( 'default' === get_post_meta( $page_id, '_wp_page_template', true ) || '' === get_post_meta( $page_id, '_wp_page_template', true ), 'the preview must not change the page' );
	$assert( ! $copy_exists(), 'the preview must not create a copy' );

	// 2) 適用: ページのテンプレートの指定が、テーマのテンプレートになる。コピーは、作られない
	$message = $method->invoke( null, $source, $spec, true, false, array(), $stylesheet );
	$assert( is_string( $message ), 'apply must succeed: ' . ( is_wp_error( $message ) ? $message->get_error_message() : '' ) );
	$assert( $source === get_post_meta( $page_id, '_wp_page_template', true ), 'the page must name the theme template: ' . wp_json_encode( get_post_meta( $page_id, '_wp_page_template', true ) ) );
	$assert( ! $copy_exists(), 'no copy must be created in the database' );
	$assert( $source === get_page_template_slug( $page_id ), 'get_page_template_slug must return the template' );

	// 3) もう一度適用しても、変わらない
	$method->invoke( null, $source, $spec, true, false, array(), $stylesheet );
	$assert( $source === get_post_meta( $page_id, '_wp_page_template', true ) && ! $copy_exists(), 'a second apply must change nothing' );

	// 4) customTemplates にないテンプレート（コアの page）は、これまでどおり、コピーを作る
	if ( get_block_file_template( $stylesheet . '//page', 'wp_template' ) && ! Itmar_Block_Theme_Migration::is_page_custom_template( 'page' ) ) {
		$fallback = $method->invoke( null, 'page', $spec, true, false, array(), $stylesheet );
		$assert( is_string( $fallback ) && false === strpos( $fallback, 'directly' ), 'a template that is not in customTemplates must keep the old behavior: ' . wp_json_encode( is_wp_error( $fallback ) ? $fallback->get_error_message() : $fallback ) );
		$assert( $copy_exists(), 'the old behavior creates a copy' );
	}
} finally {
	// 後始末
	foreach ( get_posts( array( 'post_type' => 'wp_template', 'name' => 'page-btm-direct-test', 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $p ) {
		wp_delete_post( $p->ID, true );
	}
	wp_delete_post( $page_id, true );
}

echo "m3-mapping-direct-check: OK\n";

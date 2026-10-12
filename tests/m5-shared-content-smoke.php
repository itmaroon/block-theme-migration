<?php
/**
 * 共有コンテンツ（テンプレートパーツ・自動割り当てのテンプレート）の取り込みの確認（プレビュー）。
 * リンクの対応表は廃止（D7）。ここでは、同期パターンの参照の再接続だけを扱う。
 */

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Plugin class was not loaded.' );
}

$pattern_manifest_method  = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_editable_pattern_manifest' );
$template_manifest_method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_template_manifest' );
$manifest = array(
	'theme'            => array( 'slug' => get_stylesheet() ),
	'editablePatterns' => $pattern_manifest_method->invoke( null, array() ),
	'templates'        => $template_manifest_method->invoke( null ),
);

$pattern_method = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'get_theme_pattern_id_map' );
$pattern_refs   = $pattern_method->invoke( null, $manifest['editablePatterns'] ?? array() );
$shared_method  = new ReflectionMethod( 'Itmar_Block_Theme_Migration', 'process_shared_mapping_content' );
$result         = $shared_method->invoke( null, $manifest, array(), false, false, $pattern_refs );

if ( ! empty( $result['hasError'] ) || ! is_array( $result['items'] ) ) {
	throw new RuntimeException( 'Shared theme content did not produce a clean preview.' );
}
// プレビューは、何も保存しない
$managed = Itmar_Block_Theme_Migration::get_managed_templates();
echo wp_json_encode( array( 'items' => count( $result['items'] ), 'managedUnchanged' => true, 'managed' => count( $managed ) ), JSON_PRETTY_PRINT );

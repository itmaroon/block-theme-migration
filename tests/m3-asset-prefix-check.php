<?php
/**
 * 移植先のアドレスに合わせる処理のテスト（M3）。
 * サブディレクトリにある WordPress（/sazanka/wp-content）で、/wp-content/plugins/… が、サイトの外を指さないこと。
 * 本文だけを渡して調べる。データベースは変えない。
 */

if ( ! class_exists( 'Itmar_BTM_Binder' ) ) {
	throw new RuntimeException( 'Itmar_BTM_Binder was not loaded.' );
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$sub = '/sazanka/wp-content';

// 1) ブロックコメント（スラッシュを \/ にした形も）・data-attributes・HTML の img、CSS の url()
$plain   = '/wp-content/plugins/itmaroon-ec-relate-blocks/assets/image/main_sample.png';
$content = '<!-- wp:itmar/pickup-posts {"sampleImage":"' . $plain . '","alt":"x"} -->' . "\n"
	. '<div data-attributes="{&quot;sampleImage&quot;:&quot;' . $plain . '&quot;}"><img src="' . $plain . '" alt=""/>'
	. '<div style="background:url(/wp-content/themes/kiln-theme/a.png)"></div></div>' . "\n"
	. '<!-- /wp:itmar/pickup-posts -->' . "\n"
	. '<!-- wp:html -->{"u":"\/wp-content\/plugins\/query-blocks\/assets\/image\/slide_sample.png"}<!-- /wp:html -->';
$changes = array();
$out     = Itmar_BTM_Binder::prefix_asset_urls( $content, $changes, $sub );
$assert( substr_count( $out, '/sazanka/wp-content/plugins/itmaroon-ec-relate-blocks/assets/image/main_sample.png' ) === 3, 'comment, data-attributes and img must all be prefixed: ' . $out );
$assert( false !== strpos( $out, 'url(/sazanka/wp-content/themes/kiln-theme/a.png)' ), 'css url() must be prefixed' );
$assert( false !== strpos( $out, '\\/sazanka\\/wp-content\\/plugins\\/query-blocks\\/assets\\/image\\/slide_sample.png' ), 'the escaped form must be prefixed' );
$assert( 1 === count( $changes ) && 'prefix' === $changes[0]['action'] && 5 === $changes[0]['count'], 'one change record with the count (5): ' . wp_json_encode( $changes ) );

// 2) もう一度かけても、変わらない（すでに合っているものは触らない）
$again_changes = array();
$again         = Itmar_BTM_Binder::prefix_asset_urls( $out, $again_changes, $sub );
$assert( $again === $out && empty( $again_changes ), 'a second pass must not change anything' );

// 3) 絶対のアドレス・別のホスト・アップロード・外のパスは、触らない
$keep = '<img src="https://example.com/wp-content/plugins/a/b.png"/><img src="/wp-content/uploads/2026/a.png"/><a href="/blog/wp-content-notes/">x</a>';
$kc   = array();
$assert( Itmar_BTM_Binder::prefix_asset_urls( $keep, $kc, $sub ) === $keep && empty( $kc ), 'absolute URLs, uploads and unrelated paths must be left alone' );

// 4) サイトの一番上にある WordPress（/wp-content）は、何もしない
$rc = array();
$assert( Itmar_BTM_Binder::prefix_asset_urls( $content, $rc, '/wp-content' ) === $content && empty( $rc ), 'a site at the root must not change' );

echo "m3-asset-prefix-check: OK\n";

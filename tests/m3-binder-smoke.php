<?php
/**
 * 紐づけの変換エンジン（Itmar_BTM_Binder）のテスト（M3）。
 * WordPress を読み込んだ状態で実行する（他の *-smoke.php と同じ）。
 */

if ( ! class_exists( 'Itmar_BTM_Binder' ) ) {
	throw new RuntimeException( 'Itmar_BTM_Binder was not loaded.' );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$content = <<<'HTML'
<!-- wp:itmar/pickup-posts {"selectedSlug":"works","selectedRest":"works","dispTaxonomies":["creator","kind"],"choiceTerms":[3,4],"choiceFields":["title","acf_gallery","photo"],"blockMap":{"acf_gallery":"itmar/slide-mv","link":"itmar/design-button","photo":"core/image"}} /-->

<!-- wp:itmar/design-title {"linkKind":"archive","selectedSlug":"concept"} -->
<div data-attributes="{&quot;linkKind&quot;:&quot;archive&quot;,&quot;selectedSlug&quot;:&quot;concept&quot;}"><h2>t</h2></div>
<!-- /wp:itmar/design-title -->

<!-- wp:itmar/design-title {"linkKind":"fixed","selectedSlug":"concept"} -->
<div data-attributes="{&quot;linkKind&quot;:&quot;fixed&quot;,&quot;selectedSlug&quot;:&quot;concept&quot;}"><h2>fixed page</h2></div>
<!-- /wp:itmar/design-title -->

<!-- wp:paragraph -->
<p>plain</p>
<!-- /wp:paragraph -->
HTML;

$context = array( 'restMap' => array(), 'labelMap' => array() );

// ---- 紐づけが無ければ、本文を一切変えない ----
$changes = array();
$no_terms = str_replace( '"choiceTerms":[3,4],', '', $content );
$same     = Itmar_BTM_Binder::bind_content( $no_terms, array(), $context, $changes );
$assert( $same === $no_terms && empty( $changes ), 'Without bindings the content must be returned untouched.' );

// 移植元のタームは、移植先に無いので、紐づけが無くても空にする
$changes = array();
$cleared = Itmar_BTM_Binder::bind_content( $content, array(), $context, $changes );
$assert( array() === parse_blocks( $cleared )[0]['attrs']['choiceTerms'], 'Source terms must be cleared even without bindings.' );

// ---- 紐づけ ----
$bindings = array(
	'postTypes'  => array( 'works' => 'post', 'concept' => 'page' ),
	'taxonomies' => array( 'creator' => 'category', 'kind' => '' ),
	'fields'     => array( 'acf_gallery' => 'gallery2', 'photo' => '' ),
);
$changes = array();
$out     = Itmar_BTM_Binder::bind_content( $content, $bindings, $context, $changes );
$assert( $out !== $content, 'Bound content must differ.' );

$blocks = parse_blocks( $out );
$by     = array();
foreach ( $blocks as $block ) {
	if ( $block['blockName'] ) {
		$by[] = $block;
	}
}
$pick = $by[0]['attrs'];
$assert( 'post' === $pick['selectedSlug'], 'pickup selectedSlug was not bound.' );
$assert( 'posts' === $pick['selectedRest'], 'pickup selectedRest should become the rest base of the bound post type.' );
$assert( array( 'category' ) === $pick['dispTaxonomies'], 'Unbound taxonomy must be dropped, bound one renamed.' );
$assert( array() === $pick['choiceTerms'], 'Source terms must be cleared.' );
$assert( array( 'title', 'gallery2' ) === $pick['choiceFields'], 'Fields: core field kept, bound renamed, unbound dropped.' );
$assert( isset( $pick['blockMap']['gallery2'] ) && ! isset( $pick['blockMap']['acf_gallery'] ) && ! isset( $pick['blockMap']['photo'] ) && isset( $pick['blockMap']['link'] ), 'blockMap keys were not renamed correctly.' );

// archive の design-title は post type として、fixed は触らない
$archive = $by[1];
$assert( 'page' === $archive['attrs']['selectedSlug'], 'design-title (archive) selectedSlug comment attr was not bound.' );
$assert( false !== strpos( $archive['innerHTML'], '&quot;selectedSlug&quot;:&quot;page&quot;' ), 'design-title (archive) data-attributes was not bound in step with the comment.' );
$fixed = $by[2];
$assert( 'concept' === $fixed['attrs']['selectedSlug'] && false !== strpos( $fixed['innerHTML'], '&quot;concept&quot;' ), 'design-title (fixed) is a page reference and must not be touched.' );
$assert( false !== strpos( $out, '<p>plain</p>' ), 'Unrelated blocks must survive.' );

// ---- 変更の記録 ----
$kinds = array_count_values( array_column( $changes, 'kind' ) );
foreach ( array( 'post-type', 'post-type-rest', 'taxonomy', 'term', 'field', 'field-keys' ) as $kind ) {
	$assert( ! empty( $kinds[ $kind ] ), 'Change record missing for kind ' . $kind );
}

// ---- 2回目は、何も変わらない（冪等） ----
$again   = array();
$out2    = Itmar_BTM_Binder::bind_content( $out, $bindings, $context, $again );
$assert( $out2 === $out && empty( $again ), 'Binding must be idempotent.' );

// ---- 属性が既定値で、ブロックコメントに無いとき（data-attributes にだけある） ----
$bare = <<<'HTML'
<!-- wp:itmar/design-title -->
<div data-attributes="{&quot;linkKind&quot;:&quot;archive&quot;,&quot;selectedSlug&quot;:&quot;concept&quot;}"><h2>t</h2></div>
<!-- /wp:itmar/design-title -->
HTML;
$c   = array();
$res = Itmar_BTM_Binder::bind_content( $bare, array( 'postTypes' => array( 'concept' => 'page' ) ), $context, $c );
$pb  = parse_blocks( $res )[0];
$assert( 'page' === ( $pb['attrs']['selectedSlug'] ?? null ), 'A changed attribute must be written explicitly to the block comment.' );
$assert( 'archive' === ( $pb['attrs']['linkKind'] ?? 'archive' ), 'linkKind must stay.' );

// ---- 紐づけを外す（post type を ''） ----
$c   = array();
$res = Itmar_BTM_Binder::bind_content( $content, array( 'postTypes' => array( 'works' => '' ) ), $context, $c );
$p0  = parse_blocks( $res )[0]['attrs'];
$assert( '' === ( $p0['selectedSlug'] ?? '' ), 'Unbinding a post type must clear the reference.' );
$assert( ! empty( array_filter( $c, static fn( $x ) => 'clear' === $x['action'] ) ), 'Clear must be recorded.' );

// ---- テンプレート名 ----
$assert( array( 'archive-post', 'renamed' ) === Itmar_BTM_Binder::template_target( 'archive-works', $bindings ), 'archive template rename.' );
$assert( array( 'single-post', 'renamed' ) === Itmar_BTM_Binder::template_target( 'single-works', $bindings ), 'single template rename.' );
$assert( array( 'taxonomy-category', 'renamed' ) === Itmar_BTM_Binder::template_target( 'taxonomy-creator', $bindings ), 'taxonomy template rename.' );
$assert( array( null, 'unbound' ) === Itmar_BTM_Binder::template_target( 'taxonomy-kind', $bindings ), 'unbound taxonomy template is not carried.' );
$assert( array( 'front-page', 'same' ) === Itmar_BTM_Binder::template_target( 'front-page', $bindings ), 'other templates keep their slug.' );
$assert( array( 'archive', 'same' ) === Itmar_BTM_Binder::template_target( 'archive', $bindings ), 'generic archive keeps its slug.' );

// ---- 提案 ----
$dest = array( 'post' => 'Posts', 'page' => 'Pages', 'works' => '作品' );
$assert( 'works' === Itmar_BTM_Binder::suggest( 'works', '', $dest ), 'exact match.' );
$assert( 'works' === Itmar_BTM_Binder::suggest( 'sakuhin', '作品', $dest ), 'label match.' );
$assert( 'works' === Itmar_BTM_Binder::suggest( 'work', '', $dest ), 'similar name.' );
$assert( '' === Itmar_BTM_Binder::suggest( 'zzzz', '', $dest ), 'no suggestion when nothing is close.' );

echo "m3-binder-smoke: OK\n";

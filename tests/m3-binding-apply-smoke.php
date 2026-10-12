<?php
/**
 * 紐づけの計画・適用・元に戻すのテスト（M3）。
 * 一時的なテンプレートをデータベースに作り、確認後にゴミ箱へ入れる。ほかのものは変えない。
 */

foreach ( array( 'Itmar_BTM_Binding', 'Itmar_BTM_Binder' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		throw new RuntimeException( $class . ' was not loaded.' );
	}
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$stylesheet = get_stylesheet();
$slug       = 'btm-test-archive-zzworks';
$part_slug  = 'btm-test-part';
$template_body = '<!-- wp:itmar/pickup-posts {"selectedSlug":"zzworks","selectedRest":"zzworks","dispTaxonomies":["zzcreator"],"choiceTerms":[3],"choiceFields":["title","zz_gallery"],"blockMap":{"zz_gallery":"itmar/slide-mv"}} /-->';
$part_body     = '<!-- wp:itmar/design-title {"linkKind":"archive","selectedSlug":"zzworks"} -->' . "\n" . '<div data-attributes="{&quot;linkKind&quot;:&quot;archive&quot;,&quot;selectedSlug&quot;:&quot;zzworks&quot;}"><h2>t</h2></div>' . "\n" . '<!-- /wp:itmar/design-title -->';

$cleanup = static function () use ( $slug, $part_slug, $stylesheet ): void {
	foreach ( array( 'wp_template' => array( $slug, 'single-zzworks', 'archive-post', 'archive-zzworks' ), 'wp_template_part' => array( $part_slug ) ) as $type => $names ) {
		foreach ( $names as $name ) {
			foreach ( get_posts( array( 'post_type' => $type, 'name' => $name, 'post_status' => 'any', 'posts_per_page' => -1, 'tax_query' => array( array( 'taxonomy' => 'wp_theme', 'field' => 'name', 'terms' => $stylesheet ) ) ) ) as $post ) {
				if ( 'wp_template' === $type && in_array( $name, array( 'archive-post', 'archive-zzworks' ), true ) && 0 !== strpos( (string) $post->post_content, '<!-- wp:itmar/pickup-posts' ) ) {
					continue; // 実在のものは触らない
				}
				wp_delete_post( $post->ID, true );
			}
		}
	}
};
$cleanup();

try {
	// テスト用の下地（データベース上のカスタマイズとして）
	$archive_slug = 'archive-zzworks';
	$t = Itmar_Block_Theme_Migration::save_template_override( $archive_slug, 'Test archive', $template_body, true, array( 'source' => 'seed', 'target' => 'seed', 'package' => 'test' ) );
	$assert( is_int( $t ), 'seed template was not saved.' );
	$p = Itmar_Block_Theme_Migration::save_template_part_override( $part_slug, 'Test part', $part_body, 'uncategorized', true, array( 'source' => 'seed', 'target' => 'seed', 'package' => 'test' ) );
	$assert( is_int( $p ), 'seed part was not saved.' );

	// 取り込み済みの同期パターン（wp_block。印の識別メタあり）と、印の無い利用者のパターン
	$block_id = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'BTM test pattern', 'post_content' => $part_body ) );
	update_post_meta( $block_id, '_itmar_btm_pattern_source', 'btm-test:1' );
	$own_id = wp_insert_post( array( 'post_type' => 'wp_block', 'post_status' => 'publish', 'post_title' => 'BTM test own pattern', 'post_content' => $part_body ) );

	$form     = array( 'postTypes' => array( 'zzworks' => 'post' ), 'taxonomies' => array( 'zzcreator' => 'category' ), 'fields' => array( 'zz_gallery' => '' ) );
	$bindings = Itmar_BTM_Binding::effective( $form );
	$refs     = Itmar_BTM_Binding::references();
	$assert( in_array( 'zzworks', $refs['postTypes'], true ), 'references() must find the post type used in templates.' );
	$assert( in_array( 'zzcreator', $refs['taxonomies'], true ) && in_array( 'zz_gallery', $refs['fields'], true ), 'references() must find taxonomies and fields.' );

	// フィールドの接頭辞: 画面では移植先の名前を選び、属性には acf_ を付けた形で書く
	$prefixed = Itmar_BTM_Binding::effective( array( 'fields' => array( 'acf_gallery' => 'photos', 'plain' => 'other', 'acf_x' => '' ) ) );
	$assert( 'acf_photos' === $prefixed['fields']['acf_gallery'] && 'other' === $prefixed['fields']['plain'] && '' === $prefixed['fields']['acf_x'], 'effective() must restore the acf_ prefix for acf_ references.' );

	// ---- ドライラン: 何も保存しない ----
	$before = get_posts( array( 'post_type' => array( 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	$plan   = Itmar_BTM_Binding::plan( $bindings, $refs, false );
	$after  = get_posts( array( 'post_type' => array( 'wp_template', 'wp_template_part' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
	$assert( $before === $after, 'plan() (dry run) must not save anything.' );
	$rows = array();
	foreach ( $plan['rows'] as $row ) {
		$rows[ $row['kind'] . ':' . $row['slug'] ] = $row;
	}
	$assert( isset( $rows['template:archive-zzworks'] ) && 'renamed' === $rows['template:archive-zzworks']['status'], 'archive template should be planned as renamed.' );
	$assert( 'archive-post' === $rows['template:archive-zzworks']['target'], 'archive template target.' );
	$assert( isset( $rows['part:btm-test-part'] ) && 'updated' === $rows['part:btm-test-part']['status'], 'part should be planned as updated.' );
	$assert( 'unchanged' === ( $rows['template:index']['status'] ?? 'unchanged' ), 'unrelated templates stay unchanged.' );

	// 既存の archive-post があるなら、上書きを許可しない限り衝突（このサイトに無ければ、そのまま）
	$existing_archive_post = (bool) get_block_template( $stylesheet . '//archive-post', 'wp_template' );
	if ( $existing_archive_post ) {
		$assert( 'conflict' === $rows['template:archive-zzworks']['status'], 'existing target template must be reported as conflict.' );
	}

	// ---- 適用（衝突が無い場合に限る。衝突があるサイトでは、部品だけを確かめる） ----
	$run = Itmar_BTM_Binding::apply( $plan, false );
	$assert( empty( $run['errors'] ), 'apply errors: ' . implode( '; ', $run['errors'] ) );
	$assert( ! empty( $run['entries'] ), 'apply() must record entries.' );

	$part_now = get_block_template( $stylesheet . '//' . $part_slug, 'wp_template_part' );
	$assert( $part_now && false !== strpos( $part_now->content, '"selectedSlug":"post"' ), 'part content must be rewritten.' );
	$assert( false !== strpos( $part_now->content, '&quot;selectedSlug&quot;:&quot;post&quot;' ), 'part data-attributes must be rewritten in step.' );
	if ( ! $existing_archive_post ) {
		$renamed = get_block_template( $stylesheet . '//archive-post', 'wp_template' );
		$assert( $renamed && false !== strpos( $renamed->content, '"selectedSlug":"post"' ) && false !== strpos( $renamed->content, '"dispTaxonomies":["category"]' ), 'renamed template content must be rewritten.' );
		$assert( false === strpos( $renamed->content, 'zz_gallery' ), 'unbound field must be removed.' );
	}
	// 元のテンプレートは、触らない
	$orig = get_block_template( $stylesheet . '//' . $archive_slug, 'wp_template' );
	$assert( $orig && false !== strpos( $orig->content, 'zzworks' ), 'the original template must stay as it was.' );

	// 検証: 書き換えた部品が、ブロックとして破綻していないこと（再パースして、属性が一致）
	$blocks = parse_blocks( $part_now->content );
	$assert( 'post' === $blocks[0]['attrs']['selectedSlug'], 're-parse must give the bound value.' );

	$assert( false !== strpos( get_post( $block_id )->post_content, '"selectedSlug":"post"' ) && false !== strpos( get_post( $block_id )->post_content, '&quot;selectedSlug&quot;:&quot;post&quot;' ), 'the imported synced pattern must be rewritten.' );
	$assert( false !== strpos( get_post( $own_id )->post_content, '"selectedSlug":"zzworks"' ), 'a pattern the tool did not import must be left alone.' );

	// ---- 元に戻す ----
	$runs = Itmar_BTM_Binding::runs();
	$assert( ! empty( $runs ), 'runs() must list the run.' );
	$messages = Itmar_BTM_Binding::undo( $run['id'] );
	$assert( ! empty( $messages ), 'undo() must report.' );
	$part_back = get_block_template( $stylesheet . '//' . $part_slug, 'wp_template_part' );
	$assert( $part_back && false !== strpos( $part_back->content, 'zzworks' ) && false === strpos( $part_back->content, '"selectedSlug":"post"' ), 'undo must restore the previous part content.' );
	if ( ! $existing_archive_post ) {
		$assert( false === (bool) get_block_template( $stylesheet . '//archive-post', 'wp_template' ), 'undo must trash the template that apply created.' );
	}
	$assert( empty( array_filter( Itmar_BTM_Binding::runs(), static fn( $r ) => $r['id'] === $run['id'] ) ), 'the undone run must be removed from the list.' );

	$assert( false !== strpos( get_post( $block_id )->post_content, '"selectedSlug":"zzworks"' ), 'undo must restore the synced pattern.' );

	// 管理対象の印（識別メタ）も、適用前の値に戻る
	$assert( 'seed' === get_post_meta( $p, '_itmar_btm_template_source', true ) && 'test' === get_post_meta( $p, '_itmar_btm_template_package', true ), 'undo must restore the managed-template meta as it was before.' );

	echo "m3-binding-apply-smoke: OK\n";
} finally {
	foreach ( array( 'block_id', 'own_id' ) as $name ) {
		if ( ! empty( $$name ) ) {
			wp_delete_post( (int) $$name, true );
		}
	}
	$cleanup();
	delete_option( 'itmar_btm_bind_runs_' . sanitize_key( $stylesheet ) );
}

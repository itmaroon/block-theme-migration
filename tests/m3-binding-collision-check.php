<?php
/**
 * 同じ名前に変わるテンプレートが複数あるときの扱いのテスト（M3）。
 * 計画の行だけを渡して調べる。データベースは変えない。
 */

if ( ! class_exists( 'Itmar_BTM_Binding' ) ) {
	throw new RuntimeException( 'Itmar_BTM_Binding was not loaded.' );
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$row = static function ( string $kind, string $slug, string $target, string $status ): array {
	return array( 'kind' => $kind, 'slug' => $slug, 'target' => $target, 'status' => $status, 'note' => '' );
};

// 1) 2つが同じ名前に変わる: 最初の1件だけ適用、あとは conflict
$out = Itmar_BTM_Binding::resolve_collisions( array(
	$row( 'template', 'archive-concept', 'archive-post', 'renamed' ),
	$row( 'template', 'archive-works', 'archive-post', 'renamed' ),
	$row( 'template', 'single-works', 'single-post', 'renamed' ),
) );
$assert( 'renamed' === $out[0]['status'], 'the first one must stay renamed' );
$assert( 'conflict' === $out[1]['status'], 'the second one must be a conflict' );
$assert( false !== strpos( $out[1]['note'], 'archive-concept' ) && false !== strpos( $out[1]['note'], 'archive-post' ), 'the note must name both' );
$assert( 'renamed' === $out[2]['status'], 'a unique target must be left alone' );

// 2) もとの名前のまま更新するものが、先に場所を取る
$out = Itmar_BTM_Binding::resolve_collisions( array(
	$row( 'template', 'single-works', 'single-post', 'renamed' ),
	$row( 'template', 'single-post', 'single-post', 'updated' ),
) );
$assert( 'conflict' === $out[0]['status'], 'the renamed one must give way to the one that keeps its name' );
$assert( 'updated' === $out[1]['status'], 'the one that keeps its name must be applied' );

// 3) 種類が違えば、同じ名前でも衝突しない（テンプレートとパーツ）
$out = Itmar_BTM_Binding::resolve_collisions( array(
	$row( 'template', 'a', 'same', 'renamed' ),
	$row( 'part', 'b', 'same', 'renamed' ),
) );
$assert( 'renamed' === $out[0]['status'] && 'renamed' === $out[1]['status'], 'a template and a part do not collide' );

// 4) 適用しないもの（unchanged, skipped, conflict）は、場所を取らない
$out = Itmar_BTM_Binding::resolve_collisions( array(
	$row( 'template', 'x', 'same', 'skipped' ),
	$row( 'template', 'y', 'same', 'conflict' ),
	$row( 'template', 'z', 'same', 'renamed' ),
) );
$assert( 'renamed' === $out[2]['status'], 'rows that are not applied must not take the name' );

echo "m3-binding-collision-check: OK\n";

<?php
/**
 * 割り当て（Template Mapping）で、同じ宛先に2つ以上が割り当てられたときの扱いのテスト（M3）。
 * 割り当ての指定だけを渡して調べる。データベースは変えない。
 */

if ( ! class_exists( 'Itmar_Block_Theme_Migration' ) ) {
	throw new RuntimeException( 'Itmar_Block_Theme_Migration was not loaded.' );
}
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

// 1) 宛先と同じ名前の元が、先に場所を取る（single-works の宛先に、single-work-video も割り当てられた）。並びは、後のほうが先でも同じ
$items = array();
$error = false;
$out   = Itmar_Block_Theme_Migration::resolve_mapping_collisions( array( 'single-video' => 'single|post', 'single-post' => 'single|post' ), $items, $error );
$assert( array( 'single-post' => 'single|post' ) === $out, 'the source with the same name must keep the destination: ' . wp_json_encode( $out ) );
$assert( true === $error && 1 === count( $items ) && false !== strpos( $items[0], 'single-video' ) && false !== strpos( $items[0], 'single-post' ), 'the message must name both: ' . wp_json_encode( $items ) );

// 2) 同じ名前の元が無いときは、並びの最初の1件
$items = array();
$error = false;
$out   = Itmar_Block_Theme_Migration::resolve_mapping_collisions( array( 'a' => 'single|post', 'b' => 'single|post', 'c' => 'archive|post' ), $items, $error );
$assert( array( 'a' => 'single|post', 'c' => 'archive|post' ) === $out, 'the first one must win, others stay: ' . wp_json_encode( $out ) );

// 3) 衝突が無ければ、何も変えない
$items = array();
$error = false;
$in    = array( 'single-post' => 'single|post', 'archive-post' => 'archive|post' );
$assert( $in === Itmar_Block_Theme_Migration::resolve_mapping_collisions( $in, $items, $error ) && ! $error && empty( $items ), 'no collision must change nothing' );

echo "m3-mapping-collision-check: OK\n";

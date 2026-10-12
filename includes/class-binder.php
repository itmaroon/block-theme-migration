<?php
/**
 * 紐づけの変換エンジン（M3）。
 *
 * テンプレート・パーツの本文の、投稿タイプ・タクソノミー・カスタムフィールドの参照を、
 * 移植先のものへ書き換える。書き換える属性は、ブロック名ごとの規則（includes/link-rules.json）で決まる。
 *
 * 属性は、ブロックコメントと、保存された HTML の data-attributes の両方に持たれている。
 * この2つは必ず対で直す。ブロックコメントには、既定値のままの属性は書かれていないので、
 * data-attributes の値だけが変わるときは、ブロックコメントにその属性を明示的に書き足す
 * （書き足さないと、エディターは既定値に戻して食い違い、「ブロックが無効」になる）。
 *
 * 要件: docs/requirements-v0.2.md §6.4（BD-1〜BD-5）、§7
 *
 * @package block-theme-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Itmar_BTM_Binder {
	/** この種類の規則だけが、紐づけで書き換わる */
	/** 書き出し時の除去（mode=sanitize）で扱う種類。埋め込みの2種は、どちらのモードでも再帰する */
	private const SANITIZE = array( 'page-url', 'page', 'navigation', 'media', 'content-data', 'content-id', 'content-ref', 'service-setting', 'embedded-attributes', 'embedded-blocks' );

	private const BINDABLE = array( 'post-type', 'post-type-rest', 'post-type-label', 'taxonomy', 'term', 'field', 'field-keys', 'embedded-attributes', 'embedded-blocks' );

	/** 属性名から機械的に決まらない、保存される HTML の data-* の名前（save.tsx 側の名前） */
	private const DATA_NAME_OVERRIDES = array(
		'postTypeName'          => 'post_name',
		'forwardBlockAttributes' => 'forward_attributes',
		'backBlockAttributes'   => 'back_attributes',
		'dummyBlockAttributes'  => 'dummy_attributes',
		'numBlockAttributes'    => 'num_attributes',
		'forwardTitleAttributes' => 'fw_title_attributes',
		'backTitleAttributes'   => 'bk_title_attributes',
		'crumbBlockAttributes'  => 'crumb_attributes',
	);

	private const DATA_ATTRIBUTES_PATTERN = '/(\sdata-attributes\s*=\s*)(["\'])(.*?)\2/is';

	/* ------------------------------------------------------------------ */
	/* 本文の変換                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * 本文の紐づけを書き換える。変更が無ければ、本文を、そのまま返す（再シリアライズによる変形を避ける）。
	 *
	 * @param string $content  テンプレート・パーツの本文
	 * @param array  $bindings ['postTypes'=>[元=>先|''], 'taxonomies'=>[…], 'fields'=>[…]]
	 * @param array  $context  ['restMap'=>[元のREST基底名=>元のslug], 'labelMap'=>[元のラベル=>元のslug]]
	 * @param array  $changes  変更の記録（参照渡し）
	 */
	/**
	 * プラグイン・テーマ同梱のアセットの、サイトの一番上を基準にしたアドレス（/wp-content/plugins/…）を、この移植先のアドレスに合わせる。
	 * 書き出しで、移植元のホストを外して /wp-content/… にしてあるが、サブディレクトリにある WordPress（https://example.com/sazanka/）では、
	 * /wp-content/… が、サイトの外（https://example.com/wp-content/…）を指して、画像などが 404 になる。
	 * ブロックコメント・data-*・HTML の同じ文字列を、すべて同じに置き換えるので、保存内容の整合は保たれる。スラッシュのエスケープ（\\/）の形にも対応する。
	 * すでに合っているもの、移植先がサイトの一番上にあるもの（/wp-content）は、何もしない。
	 *
	 * @param string      $content      本文
	 * @param array       $changes      変更の記録（参照渡し）
	 * @param string|null $content_path このサイトの wp-content のパス。省略すると content_url() から求める（テスト用に指定できる）
	 */
	public static function prefix_asset_urls( string $content, array &$changes, ?string $content_path = null ): string {
		if ( null === $content_path ) {
			$content_path = (string) wp_parse_url( content_url(), PHP_URL_PATH );
		}
		$content_path = untrailingslashit( $content_path );
		if ( '' === $content_path || '/wp-content' === $content_path || false === strpos( $content, 'wp-content' ) ) {
			return $content;
		}
		$count   = 0;
		$plain   = '#(?<![\w:.\-/\\\\])/wp-content/(plugins|themes)/#';
		$escaped = '#(?<![\w:.\-/\\\\])\\\\/wp-content\\\\/(plugins|themes)\\\\/#';
		$out     = (string) preg_replace_callback(
			$plain,
			static function ( array $m ) use ( $content_path, &$count ): string {
				++$count;
				return $content_path . '/' . $m[1] . '/';
			},
			$content
		);
		$out = (string) preg_replace_callback(
			$escaped,
			static function ( array $m ) use ( $content_path, &$count ): string {
				++$count;
				return str_replace( '/', '\\/', $content_path ) . '\\/' . $m[1] . '\\/';
			},
			$out
		);
		if ( $count > 0 ) {
			$changes[] = array(
				'block'  => '(text)',
				'path'   => 'wp-content/plugins|themes',
				'kind'   => 'asset-url',
				'from'   => '/wp-content/',
				'to'     => $content_path . '/',
				'action' => 'prefix',
				'count'  => $count,
			);
		}
		return $out;
	}

	public static function bind_content( string $content, array $bindings, array $context, array &$changes ): string {
		$local  = array();
		$blocks = self::bind_blocks( parse_blocks( $content ), $bindings, $context, $local );
		if ( empty( $local ) ) {
			return $content;
		}
		$changes = array_merge( $changes, $local );
		return Itmar_Block_Theme_Migration::stable_serialize_blocks( $blocks );
	}

	private static function bind_blocks( array $blocks, array $bindings, array $context, array &$changes ): array {
		foreach ( $blocks as &$block ) {
			$block = self::bind_block( $block, $bindings, $context, $changes );
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::bind_blocks( $block['innerBlocks'], $bindings, $context, $changes );
			}
		}
		unset( $block );
		return $blocks;
	}

	public static function bind_block( array $block, array $bindings, array $context, array &$changes ): array {
		$name  = $block['blockName'] ?? null;
		$rules = Itmar_BTM_Inspector::rules();
		if ( ! $name || empty( $rules[ $name ] ) ) {
			return $block;
		}
		$attrs        = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$attrs_before = self::top_level_json( $attrs );
		$comment_before = $attrs;

		// そのブロック自身の data-attributes
		$data           = null;
		$fragment_index = null;
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $index => $fragment ) {
			if ( is_string( $fragment ) && preg_match( self::DATA_ATTRIBUTES_PATTERN, $fragment, $m ) ) {
				$decoded = json_decode( html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' ) );
				if ( is_object( $decoded ) ) {
					$data           = $decoded;
					$fragment_index = $index;
					break;
				}
			}
		}

		$local       = array();
		$keys        = array();
		$data_before = null !== $data ? unserialize( serialize( $data ) ) : null;
		if ( null !== $data ) {
			$before = self::top_level_json( $data );
			self::transform_tree( $data, $name, $bindings, $context, $local, true );
			$after = self::top_level_json( $data );
			foreach ( $after as $key => $json ) {
				if ( ( $before[ $key ] ?? null ) !== $json ) {
					$keys[] = $key;
				}
			}
		}
		// ブロックコメント。data-attributes が無いブロックでは、こちらを変更の記録の基準にする
		$comment_changes = array();
		self::transform_tree( $attrs, $name, $bindings, $context, $comment_changes, true );
		// data-attributes に無い属性は、ブロックコメントの変更だけが記録になる
		if ( null === $data || empty( $local ) ) {
			$local = $comment_changes;
		}
		if ( empty( $local ) ) {
			return $block;
		}

		// data-attributes だけが変わったキーは、ブロックコメントに明示的に書き足す
		if ( null !== $data ) {
			foreach ( $keys as $key ) {
				if ( ! array_key_exists( $key, $attrs ) ) {
					$attrs[ $key ] = json_decode( (string) wp_json_encode( $data->{$key} ), true );
				}
			}
		}
		$block['attrs'] = $attrs;

		// 規則に html の指定がある属性は、保存された HTML の対応する場所も直す
		$mode_allowed = 'sanitize' === ( $bindings['__mode'] ?? '' ) ? self::SANITIZE : self::BINDABLE;
		foreach ( $rules[ $name ] as $rule ) {
			if ( empty( $rule['html'] ) || ! in_array( (string) ( $rule['kind'] ?? '' ), $mode_allowed, true ) || false !== strpos( (string) $rule['path'], '*' ) ) {
				continue;
			}
			$path = explode( '.', (string) $rule['path'] );
			$old  = self::leaf( $data_before, $path );
			$old  = $old[0] ? $old : self::leaf( $comment_before, $path );
			$new  = self::leaf( $data, $path );
			$new  = $new[0] ? $new : self::leaf( $attrs, $path );
			if ( ! $old[0] || wp_json_encode( $old[1] ) === wp_json_encode( $new[1] ) ) {
				continue;
			}
			$apply = static function ( string $html ) use ( $rule, $new ): string {
				list( $type, $arg ) = array_pad( explode( ':', (string) $rule['html'], 2 ), 2, '' );
				if ( 'attr' === $type ) {
					return self::set_html_attribute( $html, $arg, self::html_value( $new[1] ) );
				}
				if ( 'removeattr' === $type && empty( $new[1] ) ) {
					// 属性が空になったので、save() はリンクの href を出さない。移植元のページを指す href を外す
					$strip = '/' . chr( 92 ) . 's' . preg_quote( $arg, '/' ) . '="([^"]*)"/';
					return (string) preg_replace_callback(
						'/<a' . chr( 92 ) . 's[^>]*>/i',
						static fn( array $tag ): string => (string) preg_replace_callback(
							$strip,
							static fn( array $attr ): string => Itmar_BTM_Inspector::is_internal_url( html_entity_decode( $attr[1], ENT_QUOTES, 'UTF-8' ) ) ? '' : $attr[0],
							$tag[0]
						),
						$html
					);
				}
				if ( 'remove' === $type && empty( $new[1] ) ) {
					return (string) preg_replace( '#<' . preg_quote( $arg, '#' ) . '[^>]*>.*?</' . preg_quote( $arg, '#' ) . '>#s', '', $html );
				}
				return $html;
			};
			if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
				$block['innerHTML'] = $apply( $block['innerHTML'] );
			}
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $index => $fragment ) {
				if ( is_string( $fragment ) ) {
					$block['innerContent'][ $index ] = $apply( $fragment );
				}
			}
		}

		// 属性ごとに保存される data-*（data-selected_slug など）を、対で直す
		foreach ( self::top_level_json( $attrs ) as $key => $json ) {
			if ( ( $attrs_before[ $key ] ?? null ) === $json ) {
				continue;
			}
			$old = json_decode( $attrs_before[ $key ] ?? 'null', true );
			$new = $attrs[ $key ];
			foreach ( array( 'innerHTML' ) as $field ) {
				if ( isset( $block[ $field ] ) && is_string( $block[ $field ] ) ) {
					$block[ $field ] = self::replace_data_attribute( $block[ $field ], $key, $old, $new );
				}
			}
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $index => $fragment ) {
				if ( is_string( $fragment ) ) {
					$block['innerContent'][ $index ] = self::replace_data_attribute( $fragment, $key, $old, $new );
				}
			}
		}

		if ( null !== $data ) {
			$encoded = Itmar_Block_Theme_Migration::stable_wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $encoded ) ) {
				$replace = static function ( string $html ) use ( $encoded ): string {
					return (string) preg_replace_callback(
						self::DATA_ATTRIBUTES_PATTERN,
						static fn( array $m ): string => $m[1] . $m[2] . esc_attr( $encoded ) . $m[2],
						$html,
						1
					);
				};
				$block['innerContent'][ $fragment_index ] = $replace( (string) $block['innerContent'][ $fragment_index ] );
				if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
					$block['innerHTML'] = $replace( $block['innerHTML'] );
				}
			}
		}

		foreach ( $local as $change ) {
			$changes[] = $change;
		}
		return $block;
	}

	/** パスの先の値を取り出す。[見つかったか, 値] */
	private static function leaf( $tree, array $path ): array {
		$node = $tree;
		foreach ( $path as $segment ) {
			if ( ! self::has( $node, $segment ) ) {
				return array( false, null );
			}
			$node = self::get( $node, $segment );
		}
		return array( true, $node );
	}

	/** 保存された HTML の data-* を、名前を指定して置き換える（無ければ、そのまま） */
	private static function set_html_attribute( string $html, string $name, string $value ): string {
		$escaped = str_replace( array( '&', '"', '<', '>' ), array( '&amp;', '&quot;', '&lt;', '&gt;' ), $value );
		$part    = '(?:"([^"]*)"|' . chr( 39 ) . '([^' . chr( 39 ) . ']*)' . chr( 39 ) . ')';
		$pattern = '/(' . chr( 92 ) . 's' . preg_quote( $name, '/' ) . chr( 92 ) . 's*=' . chr( 92 ) . 's*)' . $part . '/s';
		return (string) preg_replace_callback( $pattern, static fn( array $m ): string => $m[1] . '"' . $escaped . '"', $html );
	}

	/** HTML に出力される値の形（文字列はそのまま、配列・オブジェクトは JSON.stringify 相当） */
	private static function html_value( $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		$json = Itmar_Block_Theme_Migration::stable_wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	private static function snake( string $key ): string {
		return strtolower( (string) preg_replace( '/(?<!^)[A-Z]/', '_$0', $key ) );
	}

	/**
	 * 属性の値の変更を、保存された HTML の data-* にも反映する。
	 * 名前は、属性名の snake_case（上書き表に例外）。見つからなければ、古い値と一致する data-* を探す。
	 */
	private static function replace_data_attribute( string $html, string $key, $old, $new ): string {
		$name       = self::DATA_NAME_OVERRIDES[ $key ] ?? self::snake( $key );
		$old_html   = self::html_value( $old );
		$new_attr   = str_replace( array( '&', '"', '<', '>' ), array( '&amp;', '&quot;', '&lt;', '&gt;' ), self::html_value( $new ) );
		$value_part = '(?:"([^"]*)"|' . chr(39) . '([^' . chr(39) . ']*)' . chr(39) . ')';
		$by_name    = '/(' . chr(92) . 'sdata-' . preg_quote( $name, '/' ) . chr(92) . 's*=' . chr(92) . 's*)' . $value_part . '/s';
		$quote      = static fn( array $m, string $to ): string => $m[1] . '"' . $to . '"';
		if ( preg_match( $by_name, $html ) ) {
			return (string) preg_replace_callback( $by_name, static fn( array $m ): string => $quote( $m, $new_attr ), $html );
		}
		if ( strlen( $old_html ) < 3 || 'attributes' === $name ) {
			return $html;
		}
		$any = '/(' . chr(92) . 'sdata-(?!attributes' . chr(92) . 'b)[a-z0-9_-]+' . chr(92) . 's*=' . chr(92) . 's*)' . $value_part . '/s';
		return (string) preg_replace_callback(
			$any,
			static function ( array $m ) use ( $old_html, $new_attr, $quote ): string {
				$current = html_entity_decode( ( $m[2] ?? '' ) !== '' ? $m[2] : ( $m[3] ?? '' ), ENT_QUOTES, 'UTF-8' );
				return $current === $old_html ? $quote( $m, $new_attr ) : $m[0];
			},
			$html
		);
	}

	/** @param array|object $tree */
	private static function top_level_json( $tree ): array {
		$out = array();
		foreach ( (array) $tree as $key => $value ) {
			$out[ (string) $key ] = (string) wp_json_encode( $value );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* 属性の木の変換                                                       */
	/* ------------------------------------------------------------------ */

	/** 配列にも、オブジェクトにも使える取得・設定 */
	private static function get( $node, string $key ) {
		if ( is_array( $node ) ) {
			return $node[ $key ] ?? null;
		}
		return is_object( $node ) && property_exists( $node, $key ) ? $node->{$key} : null;
	}

	private static function has( $node, string $key ): bool {
		return is_array( $node ) ? array_key_exists( $key, $node ) : ( is_object( $node ) && property_exists( $node, $key ) );
	}

	private static function set( &$node, string $key, $value ): void {
		if ( is_array( $node ) ) {
			$node[ $key ] = $value;
		} elseif ( is_object( $node ) ) {
			$node->{$key} = $value;
		}
	}

	private static function keys( $node ): array {
		return is_array( $node ) ? array_keys( $node ) : ( is_object( $node ) ? array_keys( get_object_vars( $node ) ) : array() );
	}

	private static function rule_applies( array $rule, $tree ): bool {
		foreach ( (array) ( $rule['when'] ?? array() ) as $key => $expected ) {
			$actual = self::get( $tree, (string) $key );
			if ( is_array( $expected ) ? ! in_array( $actual, $expected, true ) : $actual !== $expected ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * 属性の木（配列またはオブジェクト）に、ブロックの規則を当てる。
	 *
	 * @param array|object $tree
	 */
	private static function transform_tree( &$tree, string $block_name, array $bindings, array $context, array &$changes, bool $record, int $depth = 0 ): void {
		$rules = Itmar_BTM_Inspector::rules();
		if ( empty( $rules[ $block_name ] ) || $depth > 40 || ( ! is_array( $tree ) && ! is_object( $tree ) ) ) {
			return;
		}
		foreach ( $rules[ $block_name ] as $rule ) {
			$kind = (string) ( $rule['kind'] ?? '' );
			$allowed = 'sanitize' === ( $bindings['__mode'] ?? '' ) ? self::SANITIZE : self::BINDABLE;
			if ( ! in_array( $kind, $allowed, true ) || ! self::rule_applies( $rule, $tree ) ) {
				continue;
			}
			self::walk_path(
				$tree,
				explode( '.', (string) $rule['path'] ),
				static function ( &$container, string $key ) use ( $kind, $rule, $block_name, $bindings, $context, &$changes, $record, $depth ): void {
					self::apply_kind( $kind, $rule, $container, $key, $block_name, $bindings, $context, $changes, $record, $depth );
				}
			);
		}
	}

	/** パスをたどって、最後の要素の持ち主とキーでコールバックを呼ぶ（* は、子のすべて） */
	private static function walk_path( &$node, array $segments, callable $callback ): void {
		$segment = array_shift( $segments );
		if ( null === $segment || ( ! is_array( $node ) && ! is_object( $node ) ) ) {
			return;
		}
		$keys = '*' === $segment ? self::keys( $node ) : ( self::has( $node, $segment ) ? array( $segment ) : array() );
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( empty( $segments ) ) {
				$callback( $node, $key );
				continue;
			}
			if ( is_array( $node ) ) {
				self::walk_path( $node[ $key ], $segments, $callback );
			} else {
				self::walk_path( $node->{$key}, $segments, $callback );
			}
		}
	}

	private static function note( array &$changes, bool $record, string $block, string $path, string $kind, $from, $to, string $action = 'replace' ): void {
		if ( ! $record ) {
			return;
		}
		$changes[] = array(
			'block'  => $block,
			'path'   => $path,
			'kind'   => $kind,
			'from'   => is_scalar( $from ) ? (string) $from : (string) wp_json_encode( $from, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'to'     => is_scalar( $to ) ? (string) $to : (string) wp_json_encode( $to, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			'action' => $action,
		);
	}

	/** 値1つを、紐づけの対応表で置き換える。対応表に無ければ null（触らない）。'' は、参照を外す */
	private static function map_name( string $value, array $table ): ?string {
		return array_key_exists( $value, $table ) ? (string) $table[ $value ] : null;
	}

	private static function apply_kind( string $kind, array $rule, &$container, string $key, string $block_name, array $bindings, array $context, array &$changes, bool $record, int $depth ): void {
		$value = self::get( $container, $key );
		if ( null === $value || '' === $value || array() === $value ) {
			return;
		}
		$path  = (string) $rule['path'];
		if ( 'sanitize' === ( $bindings['__mode'] ?? '' ) && ! in_array( $kind, array( 'embedded-attributes', 'embedded-blocks' ), true ) ) {
			self::apply_sanitize_kind( $kind, $rule, $container, $key, $block_name, $changes, $record, ! empty( $bindings['__demo'] ) );
			return;
		}
		$types = (array) ( $bindings['postTypes'] ?? array() );
		$taxes = (array) ( $bindings['taxonomies'] ?? array() );
		$flds  = (array) ( $bindings['fields'] ?? array() );

		switch ( $kind ) {
			case 'post-type':
				if ( is_string( $value ) && null !== ( $to = self::map_name( $value, $types ) ) && $to !== $value ) {
					self::set( $container, $key, $to );
					self::note( $changes, $record, $block_name, $path, $kind, $value, $to, '' === $to ? 'clear' : 'replace' );
				}
				break;

			case 'post-type-rest':
				if ( is_string( $value ) ) {
					$source = $context['restMap'][ $value ] ?? $value;
					$to_slug = self::map_name( $source, $types );
					if ( null !== $to_slug ) {
						$to = '' === $to_slug ? '' : self::rest_base_of( $to_slug );
						if ( $to !== $value ) {
							self::set( $container, $key, $to );
							self::note( $changes, $record, $block_name, $path, $kind, $value, $to, '' === $to ? 'clear' : 'replace' );
						}
					}
				}
				break;

			case 'post-type-label':
				if ( is_string( $value ) ) {
					$source  = $context['labelMap'][ $value ] ?? null;
					$to_slug = null !== $source ? self::map_name( $source, $types ) : null;
					if ( null !== $to_slug ) {
						$object = '' !== $to_slug ? get_post_type_object( $to_slug ) : null;
						$to     = $object ? (string) $object->label : '';
						if ( $to !== $value ) {
							self::set( $container, $key, $to );
							self::note( $changes, $record, $block_name, $path, $kind, $value, $to, '' === $to ? 'clear' : 'replace' );
						}
					}
				}
				break;

			case 'taxonomy':
				$new = self::map_list_or_string( $value, $taxes );
				if ( $new !== $value ) {
					self::set( $container, $key, $new );
					self::note( $changes, $record, $block_name, $path, $kind, $value, $new, 'replace' );
				}
				break;

			case 'term':
				// 絞り込みに選んだターム（移植元の中身）は、移植先に無いので、空にする
				$empty = is_array( $value ) ? array() : '';
				self::set( $container, $key, $empty );
				self::note( $changes, $record, $block_name, $path, $kind, $value, $empty, 'clear' );
				break;

			case 'field':
				$skip = array_merge( Itmar_BTM_Inspector::CORE_FIELDS, (array) ( $rule['skip'] ?? array() ) );
				$new  = self::map_list_or_string( $value, $flds, $skip );
				if ( $new !== $value ) {
					self::set( $container, $key, $new );
					self::note( $changes, $record, $block_name, $path, $kind, $value, $new, 'replace' );
				}
				break;

			case 'field-keys':
				if ( is_array( $value ) || is_object( $value ) ) {
					$skip     = array_merge( Itmar_BTM_Inspector::CORE_FIELDS, (array) ( $rule['skip'] ?? array() ) );
					$is_obj   = is_object( $value );
					$renamed  = array();
					$modified = false;
					foreach ( (array) $value as $field => $block_ref ) {
						$field = (string) $field;
						$to    = in_array( $field, $skip, true ) ? null : self::map_name( $field, $flds );
						if ( null === $to ) {
							$renamed[ $field ] = $block_ref;
						} elseif ( '' !== $to ) {
							$renamed[ $to ] = $block_ref;
							$modified       = true;
						} else {
							$modified = true; // 紐づけ先が無いので、項目ごと外す
						}
					}
					if ( $modified ) {
						$new = $is_obj ? (object) $renamed : $renamed;
						self::set( $container, $key, $new );
						self::note( $changes, $record, $block_name, $path, $kind, array_keys( (array) $value ), array_keys( $renamed ), 'replace' );
					}
				}
				break;

			case 'embedded-attributes':
				if ( ! empty( $rule['block'] ) && ( is_array( $value ) || is_object( $value ) ) ) {
					if ( is_array( $container ) ) {
						self::transform_tree( $container[ $key ], (string) $rule['block'], $bindings, $context, $changes, $record, $depth + 1 );
					} else {
						self::transform_tree( $container->{$key}, (string) $rule['block'], $bindings, $context, $changes, $record, $depth + 1 );
					}
				}
				break;

			case 'embedded-blocks':
				if ( is_array( $value ) || is_object( $value ) ) {
					if ( is_array( $container ) ) {
						self::transform_embedded( $container[ $key ], $bindings, $context, $changes, $record, $depth + 1 );
					} else {
						self::transform_embedded( $container->{$key}, $bindings, $context, $changes, $record, $depth + 1 );
					}
				}
				break;
		}
	}

	/** 既定値（登録されたブロックの属性定義）。無ければ、現在の値の型に応じた空 */
	private static function reset_value( string $block_name, string $path, $current ) {
		if ( false === strpos( $path, '.' ) ) {
			$type = WP_Block_Type_Registry::get_instance()->get_registered( $block_name );
			if ( $type && isset( $type->attributes[ $path ] ) && array_key_exists( 'default', $type->attributes[ $path ] ) ) {
				return $type->attributes[ $path ]['default'];
			}
		}
		if ( is_string( $current ) ) {
			return '';
		}
		if ( is_array( $current ) ) {
			return array();
		}
		if ( is_object( $current ) ) {
			return new stdClass();
		}
		if ( is_bool( $current ) ) {
			return false;
		}
		return 0;
	}

	/** 書き出し時の除去。移植元のサイト固有のものを、既定値・空にする */
	private static function apply_sanitize_kind( string $kind, array $rule, &$container, string $key, string $block_name, array &$changes, bool $record, bool $demo = false ): void {
		// デモ用の書き出しは、ページへのリンクと、メディアを残す（URL は、あとで相対にする）
		// （投稿・メディアの ID と、リソースの参照も残す。デモは、同じ ID で見本の中身を作る）
		if ( $demo && in_array( $kind, array( 'page-url', 'page', 'media', 'content-data', 'content-id', 'content-ref' ), true ) ) {
			return;
		}
		$value = self::get( $container, $key );
		$path  = (string) $rule['path'];
		switch ( $kind ) {
			case 'page-url':
				if ( is_string( $value ) && Itmar_BTM_Inspector::is_internal_url( $value ) ) {
					self::set( $container, $key, '' );
					self::note( $changes, $record, $block_name, $path, 'internal-link', $value, '', 'strip' );
				}
				break;
			case 'page':
				if ( is_string( $value ) ) {
					self::set( $container, $key, '' );
					self::note( $changes, $record, $block_name, $path, 'internal-link', $value, '', 'strip' );
				}
				break;
			case 'navigation':
				if ( is_array( $container ) ) {
					unset( $container[ $key ] );
				} else {
					unset( $container->{$key} );
				}
				self::note( $changes, $record, $block_name, $path, 'navigation-ref', $value, '', 'strip' );
				break;
			case 'media':
				// 移植元のアップロードだけを外す。プラグイン同梱・テーマ同梱・外部の URL は残す
				if ( ! is_numeric( $value ) && false === strpos( (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES ), 'wp-content/uploads' ) ) {
					break;
				}
				// no break
			case 'content-data':
			case 'content-id':
			case 'content-ref':
			case 'service-setting':
				$new = self::reset_value( $block_name, $path, $value );
				if ( wp_json_encode( $new ) !== wp_json_encode( $value ) ) {
					self::set( $container, $key, $new );
					self::note( $changes, $record, $block_name, $path, 'media' === $kind ? 'media' : ( 'service-setting' === $kind ? 'service-setting' : 'content' ), $value, $new, 'reset' );
				}
				break;
		}
	}

	/** 属性に埋め込まれたブロックの木（name・attributes・innerBlocks） */
	private static function transform_embedded( &$node, array $bindings, array $context, array &$changes, bool $record, int $depth ): void {
		if ( $depth > 60 || ( ! is_array( $node ) && ! is_object( $node ) ) ) {
			return;
		}
		// 埋め込まれたブロックは、name（pickup-posts 以外）か blockName（parse_blocks と同じ形）で名前を持つ
		$name = self::get( $node, 'name' );
		$name = is_string( $name ) ? $name : self::get( $node, 'blockName' );
		if ( is_string( $name ) && self::has( $node, 'attributes' ) ) {
			if ( is_array( $node ) ) {
				self::transform_tree( $node['attributes'], $name, $bindings, $context, $changes, $record, $depth );
			} else {
				self::transform_tree( $node->attributes, $name, $bindings, $context, $changes, $record, $depth );
			}
			if ( self::has( $node, 'innerBlocks' ) ) {
				if ( is_array( $node ) ) {
					self::transform_embedded( $node['innerBlocks'], $bindings, $context, $changes, $record, $depth + 1 );
				} else {
					self::transform_embedded( $node->innerBlocks, $bindings, $context, $changes, $record, $depth + 1 );
				}
			}
			return;
		}
		foreach ( self::keys( $node ) as $child_key ) {
			if ( is_array( $node ) ) {
				self::transform_embedded( $node[ $child_key ], $bindings, $context, $changes, $record, $depth + 1 );
			} else {
				self::transform_embedded( $node->{$child_key}, $bindings, $context, $changes, $record, $depth + 1 );
			}
		}
	}

	/**
	 * 文字列、または文字列の配列を、対応表で置き換える。紐づけ先が空の要素は外す。
	 *
	 * @param string|array $value
	 * @param string[]     $skip  触らない値
	 * @return string|array
	 */
	private static function map_list_or_string( $value, array $table, array $skip = array() ) {
		if ( is_string( $value ) ) {
			if ( in_array( $value, $skip, true ) ) {
				return $value;
			}
			$to = self::map_name( $value, $table );
			return null === $to ? $value : $to;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $item ) {
				if ( ! is_string( $item ) || in_array( $item, $skip, true ) ) {
					$out[] = $item;
					continue;
				}
				$to = self::map_name( $item, $table );
				if ( null === $to ) {
					$out[] = $item;
				} elseif ( '' !== $to ) {
					$out[] = $to;
				}
			}
			return array_values( $out );
		}
		return $value;
	}

	private static function rest_base_of( string $post_type ): string {
		$object = get_post_type_object( $post_type );
		return $object ? (string) ( $object->rest_base ?: $post_type ) : $post_type;
	}

	/* ------------------------------------------------------------------ */
	/* テンプレートの名前                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * テンプレートの slug を、紐づけに合わせて変える（archive-product → archive-works）。
	 *
	 * @return array{0:string|null,1:string} [移植先の slug（null は、取り込まない）, 理由]
	 */
	public static function template_target( string $slug, array $bindings ): array {
		$types = (array) ( $bindings['postTypes'] ?? array() );
		$taxes = (array) ( $bindings['taxonomies'] ?? array() );
		if ( preg_match( '/^(single|archive)-(.+)$/', $slug, $m ) && array_key_exists( $m[2], $types ) ) {
			return '' === $types[ $m[2] ] ? array( null, 'unbound' ) : array( $m[1] . '-' . $types[ $m[2] ], 'renamed' );
		}
		if ( preg_match( '/^taxonomy-(.+)$/', $slug, $m ) && array_key_exists( $m[1], $taxes ) ) {
			return '' === $taxes[ $m[1] ] ? array( null, 'unbound' ) : array( 'taxonomy-' . $taxes[ $m[1] ], 'renamed' );
		}
		return array( $slug, 'same' );
	}

	/* ------------------------------------------------------------------ */
	/* 紐づけ先の提案                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * 元のものに近い、移植先のものを提案する。完全に一致、ラベルが一致、名前が似ている、の順。無ければ ''。
	 *
	 * @param string               $source       元の slug（またはフィールド名）
	 * @param string               $source_label 元のラベル（無ければ ''）
	 * @param array<string,string> $destinations slug => ラベル
	 */
	public static function suggest( string $source, string $source_label, array $destinations ): string {
		if ( isset( $destinations[ $source ] ) ) {
			return $source;
		}
		if ( '' !== $source_label ) {
			foreach ( $destinations as $slug => $label ) {
				if ( '' !== $label && $label === $source_label ) {
					return (string) $slug;
				}
			}
		}
		$best      = '';
		$best_rate = 0.0;
		foreach ( array_keys( $destinations ) as $slug ) {
			similar_text( strtolower( $source ), strtolower( (string) $slug ), $percent );
			if ( $percent >= 60 && $percent > $best_rate ) {
				$best      = (string) $slug;
				$best_rate = $percent;
			}
		}
		return $best;
	}
}

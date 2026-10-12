<?php
/**
 * 書き出し時の除去（M4）。移植元のサイト固有のものを、テンプレート・パーツ・パターンの本文から外す。
 *
 * 外すもの（要件 §4.2）:
 *  - 内部リンク（移植元のページ）…空にする。外部リンクは残す
 *  - ナビゲーションの参照（core/navigation の ref）…外す
 *  - メールアドレス…属性では空に、本文の文では [master_mail] に置き換える
 *  - 移植元のメディア（wp-content/uploads）…画像は空のブロックに、属性は既定値に戻す
 *  - 投稿 ID などの ID 参照、外部サービスの設定値…既定値に戻す
 *
 * ブロックごとの属性の規則（includes/link-rules.json）に従う。ブロックコメントと、保存された HTML の
 * data-* は、Itmar_BTM_Binder が対で直す。ここは、規則に載らないもの（コアの画像、メール、本文中のリンク）を扱う。
 *
 * @package block-theme-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Itmar_BTM_Sanitizer {
	/** メールアドレスの置き換え先。属性・タグ・文のどこでも同じにする（保存内容の整合のため）。フォーム送信ブロックの管理者メールのショートコード */
	private const PLACEHOLDER = '[master_mail]';

	private const EMAIL_PATTERN = '/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u';

	/** デモ用の書き出し（リンクとメディアを残し、サイトの URL を相対にする） */
	private static bool $demo = false;

	/** 書き出しで、ブロックのコメントに属性を書き足した数（除去ではないので、除去の記録には入れない） */
	private static int $completed = 0;

	public static function set_demo( bool $demo ): void
	{
		self::$demo = $demo;
	}

	/** 画像の拡張子で終わるものは、メールアドレスではない（logo@2x.png など） */
	private const IMAGE_SUFFIX = '/\.(png|jpe?g|gif|webp|svg|avif)$/i';

	/**
	 * 本文（ブロックのマークアップ）を除去する。変更が無ければ、そのまま返す。
	 *
	 * @param array $report 除去の記録（参照渡し。['changes'=>[…]]）
	 */
	public static function sanitize_content( string $content, array &$report ): string {
		$changes   = array();
		$completed0 = self::$completed;
		$blocks    = self::sanitize_blocks( parse_blocks( $content ), $changes );
		$completed = self::$completed > $completed0;
		$text      = ( empty( $changes ) && ! $completed ) ? $content : Itmar_Block_Theme_Migration::stable_serialize_blocks( $blocks );
		// プラグイン・テーマ同梱のアセットは、移植元のホストを外して、ルート相対にする
		$text = self::relative_asset_urls( $text, $changes );
		if ( empty( $changes ) && ! $completed ) {
			return $content;
		}
		$report = array_merge( $report, $changes );
		return $text;
	}

	/**
	 * https://移植元/wp-content/plugins/… を /wp-content/plugins/… にする。ブロックコメント・data-*・HTML の同じ文字列を、すべて同じに置き換えるので、
	 * 保存内容の整合は保たれる。スラッシュのエスケープ（\/）の形にも対応する。
	 */
	private static function relative_asset_urls( string $text, array &$changes ): string {
		$bs    = chr( 92 );
		$slash = '(?:' . $bs . $bs . '?/)';
		// ホスト名の部分。HTML の実体（&quot; など）の手前で止める
		$host = 'https?:' . $slash . '{2}[^/"' . chr( 39 ) . $bs . 's<>&]+';
		// 通常: プラグイン・テーマ同梱のアセットだけ。デモ用: サイトの、すべての URL（ページ、メディア）。ホストだけを外す
		$after   = self::$demo ? '(' . $slash . ')?' : '(' . $slash . 'wp-content' . $slash . '(?:plugins|themes)' . $slash . ')';
		$pattern = '#(' . $host . ')' . $after . '#';
		$count   = 0;
		$out     = (string) preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( &$count ): string {
				$origin = str_replace( chr( 92 ), '', $m[1] );
				if ( ! Itmar_BTM_Inspector::is_internal_url( $origin ) ) {
					return $m[0];
				}
				++$count;
				$rest = $m[2] ?? '';
				// ホームそのもの（パスが無い）は、/ にする。空にすると、リンクが消える
				return '' === $rest ? '/' : $rest;
			},
			$text
		);
		if ( $count > 0 ) {
			$changes[] = array( 'block' => '(text)', 'path' => self::$demo ? 'site URLs' : 'wp-content/plugins|themes', 'kind' => 'asset-url', 'from' => '', 'to' => '', 'action' => 'relative', 'count' => $count );
		}
		return $out;
	}

	/** @param array $blocks parse_blocks() の結果 */
	public static function sanitize_blocks( array $blocks, array &$changes ): array {
		foreach ( $blocks as &$block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$block = self::sanitize_core_media( $block, $changes );
			$block = self::complete_attributes( $block );
			$block = self::normalize_saved_labels( $block );
			$block = Itmar_BTM_Binder::bind_block( $block, array( '__mode' => 'sanitize', '__demo' => self::$demo ), array(), $changes );
			$block = self::sanitize_emails( $block, $changes );
			$block = self::sanitize_links( $block, $changes );
			if ( ! empty( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = self::sanitize_blocks( $block['innerBlocks'], $changes );
			}
		}
		unset( $block );
		return $blocks;
	}

	/**
	 * 初期値がサイトの言語で変わる属性（初期値が __() の文言のもの）。ブロックごと。
	 * 初期値と同じ値だと、ブロックコメントから省かれ、HTML の data-attributes にだけ、移植元の言語の文言が残る。移植先の言語が違うと、
	 * 初期値が変わって、保存された HTML と食い違う（ブロックが無効になる）。ブロック側の登録（JS）にだけある属性なので、PHP からは調べられず、ここに挙げる。
	 */
	private const LOCALIZED_DEFAULTS = array(
		'itmar/design-select'        => array( 'folder_val' ),
		'itmar/design-text-ctrl'     => array( 'placeFolder' ),
		'itmar/confirm-figure-block' => array( 'stage_info' ),
		'itmar/input-figure-block'   => array( 'stage_info' ),
		'itmar/thanks-figure-block'  => array( 'stage_info' ),
		'itmar/contactmail-sender'   => array( 'subject_info', 'message_info', 'subject_ret', 'message_ret' ),
		'itmar/member-register'      => array( 'subject_provision', 'message_provision', 'subject_ret_pro', 'message_ret_pro', 'subject_register', 'message_register', 'subject_ret_reg', 'message_ret_reg' ),
	);

	/**
	 * 初期値が翻訳される属性（LOCALIZED_DEFAULTS）が、data-attributes にあって、ブロックコメントに無いとき、コメントに書き足す。
	 * コメントに値を明示しておけば、どの言語のサイトでも、保存された HTML と一致する。
	 */
	private static function complete_attributes( array $block ): array {
		$keys = self::LOCALIZED_DEFAULTS[ (string) ( $block['blockName'] ?? '' ) ] ?? null;
		if ( null === $keys ) {
			return $block;
		}
		// design-select の folder_val（選択前の文言）は、data-attributes ではなく、HTML の data-placeholder に出る
		if ( 'itmar/design-select' === ( $block['blockName'] ?? '' ) && ! array_key_exists( 'folder_val', (array) ( $block['attrs'] ?? array() ) ) ) {
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $fragment ) {
				if ( is_string( $fragment ) && preg_match( '/\sdata-placeholder="([^"]*)"/', $fragment, $pm ) ) {
					$block['attrs'] = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
					$block['attrs']['folder_val'] = html_entity_decode( $pm[1], ENT_QUOTES, 'UTF-8' );
					++self::$completed;
					break;
				}
			}
		}
		$data = null;
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $fragment ) {
			if ( is_string( $fragment ) && preg_match( '/(\sdata-attributes\s*=\s*)(["\'])(.*?)\2/is', $fragment, $m ) ) {
				$decoded = json_decode( html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' ), true );
				if ( is_array( $decoded ) ) {
					$data = $decoded;
					break;
				}
			}
		}
		if ( null === $data ) {
			return $block;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $attrs ) && isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$attrs[ $key ] = $data[ $key ];
				++self::$completed;
			}
		}
		$block['attrs'] = $attrs;
		return $block;
	}

	/**
	 * 保存された HTML にある、翻訳済みのラベル（昔の save() が、サイトの言語で訳して保存したもの）を、英語に戻す。
	 * ラベルは、英語で保存し、表示のときにサイトの言語に訳す作りになった（block-collections 2.3 以降）。移植先の言語が違っても、ブロックが無効にならない。
	 * 訳は、移植元のサイトの言語で引く（このサイトの翻訳ファイル）。訳が英語と同じなら、何もしない。
	 */
	private static function normalize_saved_labels( array $block ): array {
		$name = (string) ( $block['blockName'] ?? '' );
		if ( 0 !== strpos( $name, 'itmar/' ) ) {
			return $block;
		}
		// [ ブロック => [ [ 英語, 訳を当てはめる書式 ] ] ]  書式の %s に、文言が入る（保存された HTML での置き場所）
		switch ( $name ) {
			case 'itmar/design-radio':
				$labels = array( array( 'Clear', '>%s<', __( 'Clear', 'block-collections' ) ) ); // phpcs:ignore WordPress.WP.I18n
				break;
			case 'itmar/design-select':
				// 選択前の文言（folder_val、属性の値）が出る場所とは別の、選択肢の先頭の項目だけ
				$labels = array(
					array( 'Please Select.', '<option value="">%s</option>', __( 'Please Select.', 'block-collections' ) ), // phpcs:ignore WordPress.WP.I18n
					array( 'Please Select.', '<li data-value="">%s</li>', __( 'Please Select.', 'block-collections' ) ), // phpcs:ignore WordPress.WP.I18n
				);
				break;
			case 'itmar/design-text-ctrl':
				$labels = array(
					array( 'Address Search', '>%s<', __( 'Address Search', 'block-collections' ) ), // phpcs:ignore WordPress.WP.I18n
					array( 'example', 'placeholder="%s) 1234567"', __( 'example', 'block-collections' ) ), // phpcs:ignore WordPress.WP.I18n
				);
				break;
			case 'itmar/code-highlight':
				$labels = array( array( 'Copy', '>%s<', __( 'Copy', 'block-collections' ) ) ); // phpcs:ignore WordPress.WP.I18n
				break;
			default:
				return $block;
		}
		$pairs = array();
		foreach ( $labels as list( $english, $format, $translated ) ) {
			if ( $translated !== $english ) {
				$pairs[ sprintf( $format, $translated ) ] = sprintf( $format, $english );
			}
		}
		if ( empty( $pairs ) ) {
			return $block;
		}
		$changed = false;
		$inner   = (string) ( $block['innerHTML'] ?? '' );
		$replaced = strtr( $inner, $pairs );
		if ( $replaced !== $inner ) {
			$block['innerHTML'] = $replaced;
			$changed            = true;
		}
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $i => $fragment ) {
			if ( is_string( $fragment ) ) {
				$new = strtr( $fragment, $pairs );
				if ( $new !== $fragment ) {
					$block['innerContent'][ $i ] = $new;
					$changed                     = true;
				}
			}
		}
		if ( $changed ) {
			++self::$completed;
		}
		return $block;
	}

	/* ------------------------------------------------------------------ */
	/* コアのメディア                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * core/image は、移植元のアップロードの画像を外す。ブロックは残し（<figure> の器と、サイズなどの設定）、画像の ID・URL だけを消す。
	 * 保存される HTML は、エディターの save() が id・url の無い状態で出力する形に合わせる（img の src と wp-image-N、画像へのリンクを外す）。
	 */
	private static function sanitize_core_media( array $block, array &$changes ): array {
		if ( 'core/image' !== $block['blockName'] || self::$demo ) {
			return $block;
		}
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$html  = (string) ( $block['innerHTML'] ?? '' );
		$from  = (string) ( $attrs['url'] ?? ( preg_match( '/<img[^>]*' . chr( 92 ) . 'ssrc="([^"]*)"/', $html, $m ) ? $m[1] : '' ) );
		// 移植元のアップロードの画像だけ（プラグイン同梱・テーマ同梱・外部の画像は残す）
		if ( empty( $attrs['id'] ) && false === strpos( $from, 'wp-content/uploads' ) ) {
			return $block;
		}
		foreach ( array( 'id', 'url' ) as $key ) {
			unset( $attrs[ $key ] );
		}
		// メディアやアタッチメントページへのリンクは、移植元のものなので外す
		$unlink = in_array( $attrs['linkDestination'] ?? '', array( 'media', 'attachment' ), true );
		if ( $unlink ) {
			unset( $attrs['linkDestination'], $attrs['href'] );
		}
		$block['attrs'] = $attrs;
		$clean = static function ( string $fragment ) use ( $unlink ): string {
			$fragment = (string) preg_replace_callback(
				'/<img' . chr( 92 ) . 's[^>]*>/i',
				static function ( array $m ): string {
					$tag = (string) preg_replace( '/' . chr( 92 ) . 's(?:src|srcset|sizes)="[^"]*"/i', '', $m[0] );
					$tag = (string) preg_replace_callback(
						'/' . chr( 92 ) . 'sclass="([^"]*)"/i',
						static function ( array $c ): string {
							$kept = trim( (string) preg_replace( '/(?:^|' . chr( 92 ) . 's)wp-image-' . chr( 92 ) . 'd+/', '', $c[1] ) );
							return '' === $kept ? '' : ' class="' . $kept . '"';
						},
						$tag
					);
					return $tag;
				},
				$fragment
			);
			if ( $unlink ) {
				$fragment = (string) preg_replace( '#</?a(?:' . chr( 92 ) . 's[^>]*)?>#i', '', $fragment );
			}
			return $fragment;
		};
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = $clean( $block['innerHTML'] );
		}
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $index => $fragment ) {
			if ( is_string( $fragment ) ) {
				$block['innerContent'][ $index ] = $clean( $fragment );
			}
		}
		$changes[] = array( 'block' => 'core/image', 'path' => 'id,url', 'kind' => 'media', 'from' => $from, 'to' => '', 'action' => 'remove' );
		return $block;
	}

	/* ------------------------------------------------------------------ */
	/* メールアドレス                                                        */
	/* ------------------------------------------------------------------ */

	private static function is_email( string $text ): bool {
		return 1 === preg_match( self::EMAIL_PATTERN, $text ) && ! preg_match( self::IMAGE_SUFFIX, $text );
	}

	/** 文字列に含まれるメールアドレスを置き換える。置き換えた数を返す */
	private static function replace_emails( string $text, string $with, int &$count ): string {
		return (string) preg_replace_callback(
			self::EMAIL_PATTERN,
			static function ( array $m ) use ( $with, &$count ): string {
				if ( preg_match( self::IMAGE_SUFFIX, $m[0] ) ) {
					return $m[0];
				}
				++$count;
				return $with;
			},
			$text
		);
	}

	/** @param mixed $value */
	private static function strip_emails_in_value( &$value, int &$count ): void {
		if ( is_string( $value ) ) {
			$value = self::replace_emails( $value, self::PLACEHOLDER, $count );
		} elseif ( is_array( $value ) ) {
			foreach ( $value as &$child ) {
				self::strip_emails_in_value( $child, $count );
			}
		}
	}

	/**
	 * 保存された HTML のメールアドレスを置き換える。
	 * data-attributes は、JSON として読み、同じ規則で置き換えて、書き戻す（ブロックコメントの属性と一致させるため。
	 * ブロックコメントに無い属性（既定値）が変わるときは、ブロックコメントに明示的に書く）。残りは、文字として置き換える。
	 *
	 * @param array $attrs ブロックコメントの属性（参照渡し）
	 */
	private static function replace_emails_in_html( string $html, array &$attrs, int &$count ): string {
		$pattern = '/(' . chr( 92 ) . 'sdata-attributes' . chr( 92 ) . 's*=' . chr( 92 ) . 's*")([^"]*)(")/s';
		$html    = (string) preg_replace_callback(
			$pattern,
			static function ( array $m ) use ( &$attrs, &$count ): string {
				$data = json_decode( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) );
				if ( ! is_object( $data ) ) {
					return $m[0];
				}
				$before = array();
				foreach ( (array) $data as $key => $value ) {
					$before[ $key ] = wp_json_encode( $value );
				}
				$local = 0;
				self::strip_emails_in_object( $data, $local );
				if ( 0 === $local ) {
					return $m[0];
				}
				foreach ( (array) $data as $key => $value ) {
					if ( wp_json_encode( $value ) !== $before[ $key ] && ! array_key_exists( $key, $attrs ) ) {
						$attrs[ $key ] = json_decode( (string) wp_json_encode( $value ), true );
					}
				}
				$count += $local;
				$encoded = Itmar_Block_Theme_Migration::stable_wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
				return $m[1] . ( is_string( $encoded ) ? esc_attr( $encoded ) : $m[2] ) . $m[3];
			},
			$html
		);
		return self::replace_emails( $html, self::PLACEHOLDER, $count );
	}

	/** @param object $node */
	private static function strip_emails_in_object( &$node, int &$count ): void {
		foreach ( get_object_vars( $node ) as $key => $value ) {
			if ( is_string( $value ) ) {
				$node->{$key} = self::replace_emails( $value, self::PLACEHOLDER, $count );
			} elseif ( is_object( $value ) ) {
				self::strip_emails_in_object( $node->{$key}, $count );
			} elseif ( is_array( $value ) ) {
				foreach ( $value as $index => $child ) {
					if ( is_string( $child ) ) {
						$node->{$key}[ $index ] = self::replace_emails( $child, self::PLACEHOLDER, $count );
					} elseif ( is_object( $child ) ) {
						self::strip_emails_in_object( $node->{$key}[ $index ], $count );
					} elseif ( is_array( $child ) ) {
						$wrapper = (object) array( 'x' => $child );
						self::strip_emails_in_object( $wrapper, $count );
						$node->{$key}[ $index ] = $wrapper->x;
					}
				}
			}
		}
	}

	private static function sanitize_emails( array $block, array &$changes ): array {
		$count = 0;
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		self::strip_emails_in_value( $attrs, $count );
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) && false !== strpos( $block['innerHTML'], '@' ) ) {
			$block['innerHTML'] = self::replace_emails_in_html( $block['innerHTML'], $attrs, $count );
		}
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $index => $fragment ) {
			if ( is_string( $fragment ) && false !== strpos( $fragment, '@' ) ) {
				$ignored                         = 0; // innerHTML と同じ内容。数えない
				$block['innerContent'][ $index ] = self::replace_emails_in_html( $fragment, $attrs, $ignored );
			}
		}
		if ( $count > 0 ) {
			$block['attrs'] = $attrs;
			// 値は記録しない（メールアドレスを、レポートや manifest に残さない）
			$changes[] = array( 'block' => (string) $block['blockName'], 'path' => 'email', 'kind' => 'email', 'from' => '', 'to' => '', 'action' => 'remove', 'count' => $count );
		}
		return $block;
	}

	/* ------------------------------------------------------------------ */
	/* 本文中のリンク                                                        */
	/* ------------------------------------------------------------------ */

	/** HTML の href が、移植元のページを指すなら、# にする（外部リンクは残す） */
	private static function sanitize_links( array $block, array &$changes ): array {
		// itmar のブロックは、リンクを属性から出力する（属性は、規則で外している）。ここでは、本文のリンクを持つコアのブロックだけを扱う
		if ( self::$demo || 0 === strpos( (string) $block['blockName'], 'itmar/' ) ) {
			return $block;
		}
		$replace = static function ( string $html ) use ( $block, &$changes ): string {
			if ( false === strpos( $html, 'href=' ) ) {
				return $html;
			}
			return (string) preg_replace_callback(
				'/(<a\s[^>]*?\bhref=")([^"]*)(")/i',
				static function ( array $m ) use ( $block, &$changes ): string {
					$url = html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' );
					if ( '' === $url || '#' === $url[0] || ! Itmar_BTM_Inspector::is_internal_url( $url ) ) {
						return $m[0];
					}
					$changes[] = array( 'block' => (string) $block['blockName'], 'path' => 'href', 'kind' => 'internal-link', 'from' => $url, 'to' => '#', 'action' => 'strip' );
					return $m[1] . '#' . $m[3];
				},
				$html
			);
		};
		$html = isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $replace( $block['innerHTML'] ) : null;
		if ( null !== $html ) {
			$block['innerHTML'] = $html;
		}
		foreach ( (array) ( $block['innerContent'] ?? array() ) as $index => $fragment ) {
			if ( is_string( $fragment ) ) {
				$before = count( $changes );
				$block['innerContent'][ $index ] = $replace( $fragment );
				// innerHTML と同じ内容を二重に数えない
				array_splice( $changes, $before );
			}
		}
		return $block;
	}

	/* ------------------------------------------------------------------ */
	/* 記録の集計                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * 除去の記録を、種類ごとの件数と、要設定の一覧にまとめる（manifest とレポートに入れる）。
	 *
	 * @param array $changes 除去の記録
	 * @return array{counts:array<string,int>,internalLinks:array}
	 */
	public static function summarize( array $changes ): array {
		$counts = array();
		$links  = array();
		foreach ( $changes as $change ) {
			$kind            = (string) $change['kind'];
			$counts[ $kind ] = ( $counts[ $kind ] ?? 0 ) + (int) ( $change['count'] ?? 1 );
			if ( 'internal-link' === $kind && count( $links ) < 500 ) {
				$links[] = array( 'block' => $change['block'], 'path' => $change['path'], 'was' => mb_strimwidth( (string) $change['from'], 0, 120, '…' ) );
			}
		}
		ksort( $counts );
		return array( 'counts' => $counts, 'internalLinks' => $links );
	}
}

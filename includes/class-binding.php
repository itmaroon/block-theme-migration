<?php
/**
 * 紐づけ（M3）: 移植先の、投稿タイプ・タクソノミー・カスタムフィールドへの対応づけを、画面で決め、
 * ドライランで確かめ、適用し、元に戻す。
 *
 * - 何も作らない。移植先にあるものへ紐づけるだけ（宣言だけ、D3）。
 * - 書き換えは、データベースのテンプレート・パーツ（ユーザーのカスタマイズ層）への保存で行う。テーマのファイルは変えない。
 * - 適用のたびに、元の状態を記録する。元に戻すと、作ったものはゴミ箱へ、更新したものは元の本文へ戻る。
 *
 * 変換そのものは Itmar_BTM_Binder。要件: docs/requirements-v0.2.md §6.4、§14 M3
 *
 * @package block-theme-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Itmar_BTM_Binding {
	public const VIEW = 'bind';

	/** Theme Migration が、管理対象の印として付けるメタ（block-theme-migration.php の TEMPLATE_*_META） */
	private const META_KEYS = array( '_itmar_btm_template_source', '_itmar_btm_template_target', '_itmar_btm_template_theme', '_itmar_btm_template_package' );

	private const KEEP     = '__keep';
	private const MAX_RUNS = 10;

	public static function init(): void {
		add_action( 'admin_post_itmar_btm_bind', array( __CLASS__, 'handle_bind' ) );
		add_action( 'admin_post_itmar_btm_bind_undo', array( __CLASS__, 'handle_undo' ) );
	}

	/* ------------------------------------------------------------------ */
	/* 参照の収集・移植先の候補                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * 有効なテーマのテンプレート・パーツが参照している、投稿タイプ・タクソノミー・フィールド。
	 *
	 * @return array{postTypes:string[],taxonomies:string[],fields:string[],restMap:array,labelMap:array,items:array}
	 */
	public static function references(): array {
		$items = array_values(
			array_filter(
				Itmar_BTM_Inspector::collect_items(),
				static fn( array $item ): bool => in_array( $item['kind'], array( 'template', 'part' ), true )
			)
		);
		$scan  = Itmar_BTM_Inspector::scan( $items );
		$types = array();
		$taxes = array();
		$flds  = array();
		$rest  = array();
		$label = array();
		foreach ( $scan['usage'] as $u ) {
			$value = (string) ( $u['value'] ?? '' );
			if ( '' === $value ) {
				continue;
			}
			switch ( $u['kind'] ) {
				case 'post-type':
					$types[ $value ] = true;
					break;
				case 'post-type-rest':
					$slug = self::post_type_of_rest_base( $value );
					$types[ $slug ]  = true;
					$rest[ $value ] = $slug;
					break;
				case 'post-type-label':
					foreach ( get_post_types( array(), 'objects' ) as $object ) {
						if ( (string) $object->label === $value ) {
							$label[ $value ] = $object->name;
						}
					}
					break;
				case 'taxonomy':
					$taxes[ $value ] = true;
					break;
				case 'field':
					if ( 0 !== strpos( $value, 'meta_' ) ) {
						$flds[ $value ] = true;
					}
					break;
			}
		}
		foreach ( $items as $item ) {
			if ( 'template' !== $item['kind'] ) {
				continue;
			}
			if ( preg_match( '/^(?:single|archive)-(.+)$/', $item['slug'], $m ) ) {
				$types[ $m[1] ] = true;
			} elseif ( preg_match( '/^taxonomy-(.+)$/', $item['slug'], $m ) ) {
				$taxes[ $m[1] ] = true;
			}
		}
		$flds_named = array();
		foreach ( array_keys( $flds ) as $reference ) {
			$flds_named[ $reference ] = true;
		}
		return array(
			'postTypes'  => array_keys( $types ),
			'taxonomies' => array_keys( $taxes ),
			'fields'     => array_keys( $flds_named ),
			'restMap'    => $rest,
			'labelMap'   => $label,
			'items'      => $items,
		);
	}

	private static function post_type_of_rest_base( string $rest_base ): string {
		foreach ( get_post_types( array(), 'objects' ) as $object ) {
			if ( ( $object->rest_base ?: $object->name ) === $rest_base ) {
				return $object->name;
			}
		}
		return $rest_base;
	}

	/**
	 * 紐づけ先にできる投稿タイプ。公開されていないもの（予約のリソースなど）も含める。
	 * ブロックは REST で読むので、管理画面と REST に出ているものを対象にする。WordPress 本体の内部の型と、ACF の定義の型は除く。
	 *
	 * @return array<string,string> slug => ラベル
	 */
	public static function destination_post_types(): array {
		$internal = array( 'attachment', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_global_styles', 'wp_font_family', 'wp_font_face', 'revision', 'nav_menu_item', 'customize_changeset', 'oembed_cache', 'user_request' );
		$out      = array();
		foreach ( get_post_types( array( 'show_ui' => true, 'show_in_rest' => true ), 'objects' ) as $object ) {
			if ( in_array( $object->name, $internal, true ) || 0 === strpos( $object->name, 'acf-' ) || 0 === strpos( $object->name, 'wpforms' ) ) {
				continue;
			}
			$out[ $object->name ] = (string) $object->label;
		}
		return $out;
	}

	/** @return array<string,string> */
	public static function destination_taxonomies(): array {
		$out = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $object ) {
			if ( 'post_format' === $object->name ) {
				continue;
			}
			$out[ $object->name ] = (string) $object->label;
		}
		return $out;
	}

	/** @return array<string,string> フィールド名 => グループ名（読み取り専用。SCF／ACF が無ければ空） */
	public static function destination_fields(): array {
		$out = array();
		if ( ! Itmar_BTM_DataModel::fields_available() ) {
			return $out;
		}
		foreach ( acf_get_field_groups() as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				$name = (string) ( $field['name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$out[ $name ] = (string) ( $group['title'] ?? '' );
				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
					$out[ $name . '.' . (string) ( $sub['name'] ?? '' ) ] = (string) ( $group['title'] ?? '' );
				}
			}
		}
		return $out;
	}

	/**
	 * 画面の初期値。保存した紐づけがあればそれ、無ければ提案（同名・同じラベル・似た名前）。
	 * 提案が無いものは、参照を外す（''）。移植先に同じ名前があるものは、そのまま（KEEP）。
	 *
	 * @return array{postTypes:array,taxonomies:array,fields:array}
	 */
	public static function defaults( array $references ): array {
		$saved = self::saved();
		$dest  = array(
			'postTypes'  => self::destination_post_types(),
			'taxonomies' => self::destination_taxonomies(),
			'fields'     => self::destination_fields(),
		);
		$out = array( 'postTypes' => array(), 'taxonomies' => array(), 'fields' => array() );
		foreach ( array( 'postTypes', 'taxonomies', 'fields' ) as $group ) {
			foreach ( $references[ $group ] as $source ) {
				if ( array_key_exists( $source, $saved[ $group ] ) ) {
					$out[ $group ][ $source ] = $saved[ $group ][ $source ];
					continue;
				}
				$name = 'fields' === $group ? Itmar_BTM_DataModel::field_name_of( $source ) : $source;
				if ( isset( $dest[ $group ][ $name ] ) ) {
					$out[ $group ][ $source ] = self::KEEP;
					continue;
				}
				$label = 'postTypes' === $group && ( $object = get_post_type_object( $source ) ) ? (string) $object->label : '';
				$out[ $group ][ $source ] = Itmar_BTM_Binder::suggest( $name, $label, 'fields' === $group ? array_fill_keys( array_keys( $dest['fields'] ), '' ) : $dest[ $group ] );
			}
		}
		return $out;
	}

	/** 保存された紐づけ（テーマごと） */
	public static function saved(): array {
		$value = get_option( self::bindings_option(), array() );
		$value = is_array( $value ) ? $value : array();
		return array(
			'postTypes'  => is_array( $value['postTypes'] ?? null ) ? $value['postTypes'] : array(),
			'taxonomies' => is_array( $value['taxonomies'] ?? null ) ? $value['taxonomies'] : array(),
			'fields'     => is_array( $value['fields'] ?? null ) ? $value['fields'] : array(),
		);
	}

	private static function bindings_option(): string {
		return 'itmar_btm_bindings_' . sanitize_key( get_stylesheet() );
	}

	private static function runs_option(): string {
		return 'itmar_btm_bind_runs_' . sanitize_key( get_stylesheet() );
	}

	/** 画面の値（KEEP を含む）を、変換に渡す紐づけにする。KEEP は、触らない（キーを持たない） */
	public static function effective( array $form ): array {
		$out = array( 'postTypes' => array(), 'taxonomies' => array(), 'fields' => array() );
		foreach ( $out as $group => $_ ) {
			foreach ( (array) ( $form[ $group ] ?? array() ) as $source => $target ) {
				if ( self::KEEP === $target ) {
					continue;
				}
				// フィールドは、画面では移植先の名前を選ぶ。ブロックの属性には、元の参照と同じ形（acf_ の接頭辞）で書く
				if ( 'fields' === $group && '' !== $target && 0 === strpos( (string) $source, 'acf_' ) ) {
					$target = 'acf_' . $target;
				}
				$out[ $group ][ (string) $source ] = (string) $target;
			}
		}
		return $out;
	}

	/** 画面から来た値を、移植先にあるものか、外す（''）か、そのまま（KEEP）に限る */
	private static function sanitize_form( $raw ): array {
		$raw  = is_array( $raw ) ? $raw : array();
		$dest = array(
			'postTypes'  => self::destination_post_types(),
			'taxonomies' => self::destination_taxonomies(),
			'fields'     => self::destination_fields(),
		);
		$out = array( 'postTypes' => array(), 'taxonomies' => array(), 'fields' => array() );
		foreach ( $out as $group => $_ ) {
			foreach ( (array) ( $raw[ $group ] ?? array() ) as $source => $target ) {
				$source = is_string( $source ) ? sanitize_text_field( $source ) : '';
				$target = is_string( $target ) ? sanitize_text_field( $target ) : '';
				if ( '' === $source ) {
					continue;
				}
				if ( '' === $target || self::KEEP === $target || isset( $dest[ $group ][ $target ] ) ) {
					$out[ $group ][ $source ] = $target;
				}
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* 計画（ドライラン）                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * 何が、どう変わるかを計算する。何も保存しない。
	 *
	 * @param array $bindings effective() の形
	 * @return array{rows:array,patterns:array,summary:array}
	 */
	public static function plan( array $bindings, array $references, bool $overwrite = false ): array {
		$context  = array( 'restMap' => $references['restMap'], 'labelMap' => $references['labelMap'] );
		$rows     = array();
		$patterns = array();
		foreach ( Itmar_BTM_Inspector::collect_items() as $item ) {
			// このツールで取り込んだパターン（wp_block）は、テンプレートと同じく書き換える
			if ( 'synced' === $item['kind'] && self::is_imported_pattern( (int) $item['id'] ) ) {
				$changes = array();
				$bound   = Itmar_BTM_Binder::bind_content( (string) $item['content'], $bindings, $context, $changes );
				$bound   = Itmar_BTM_Binder::prefix_asset_urls( $bound, $changes );
				$rows[]  = array(
					'kind'    => 'synced',
					'id'      => (int) $item['id'],
					'slug'    => $item['title'],
					'target'  => $item['title'],
					'title'   => $item['title'],
					'area'    => '',
					'status'  => empty( $changes ) ? 'unchanged' : 'updated',
					'note'    => '',
					'changes' => $changes,
					'content' => (string) $item['content'],
					'bound'   => $bound,
				);
				continue;
			}
			if ( in_array( $item['kind'], array( 'pattern', 'synced' ), true ) ) {
				$changes = array();
				Itmar_BTM_Binder::bind_content( (string) $item['content'], $bindings, $context, $changes );
				if ( ! empty( $changes ) ) {
					$patterns[] = array( 'kind' => $item['kind'], 'slug' => $item['slug'], 'title' => $item['title'], 'count' => count( $changes ) );
				}
				continue;
			}
			if ( ! in_array( $item['kind'], array( 'template', 'part' ), true ) ) {
				continue;
			}
			$row = array(
				'kind'    => $item['kind'],
				'slug'    => $item['slug'],
				'target'  => $item['slug'],
				'title'   => $item['title'],
				'area'    => $item['area'] ?? '',
				'status'  => 'unchanged',
				'note'    => '',
				'changes' => array(),
				'content' => (string) $item['content'],
				'dbOverride' => ! empty( $item['dbOverride'] ),
			);
			if ( 'template' === $item['kind'] ) {
				list( $target, $reason ) = Itmar_BTM_Binder::template_target( $item['slug'], $bindings );
				if ( null === $target ) {
					$row['status'] = 'skipped';
					$row['note']   = __( 'Its post type or taxonomy is not bound, so this template is not used.', 'block-theme-migration' );
					$rows[]        = $row;
					continue;
				}
				$row['target'] = $target;
			}
			$changes = array();
			$bound   = Itmar_BTM_Binder::bind_content( (string) $item['content'], $bindings, $context, $changes );
			$bound   = Itmar_BTM_Binder::prefix_asset_urls( $bound, $changes );
			if ( $bound !== $row['content'] ) {
				$row['bound'] = $bound;
			}
			$row['changes'] = $changes;
			$renamed        = $row['target'] !== $row['slug'];
			if ( ! empty( $changes ) || $renamed ) {
				$row['status'] = $renamed ? 'renamed' : 'updated';
				if ( $renamed ) {
					$existing = get_block_template( get_stylesheet() . '//' . $row['target'], 'wp_template' );
					if ( $existing && trim( Itmar_BTM_Inspector::raw_content( $existing, 'templates' ) ) === trim( (string) ( $row['bound'] ?? $row['content'] ) ) ) {
						// 前回の適用で、すでに同じ内容で保存されている
						$row['status'] = 'unchanged';
						$row['note']   = __( 'Already applied.', 'block-theme-migration' );
					} elseif ( $existing && ! $overwrite ) {
						$row['status'] = 'conflict';
						$row['note']   = sprintf(
							/* translators: %s: template slug. */
							__( 'A template named "%s" already exists here. It is kept as it is unless you allow overwriting.', 'block-theme-migration' ),
							$row['target']
						);
					}
				}
			}
			$rows[] = $row;
		}

		$rows    = self::resolve_collisions( $rows );
		$summary = array_count_values( array_column( $rows, 'status' ) );
		return array( 'rows' => $rows, 'patterns' => $patterns, 'summary' => $summary );
	}

	/**
	 * 同じ名前に変わるテンプレートやパーツが複数あるとき、最初の1件だけを適用する。
	 * （そのままだと、あとのものが、先のものを黙って上書きして、先のものが消える）
	 * もとの名前のまま更新するものが先に場所を取り、そのあとは、計画の順。
	 */
	public static function resolve_collisions( array $rows ): array {
		$claimed = array();
		foreach ( array( false, true ) as $renamed_pass ) {
			foreach ( $rows as $i => $row ) {
				if ( ! in_array( $row['kind'], array( 'template', 'part' ), true ) || ! in_array( $row['status'], array( 'renamed', 'updated' ), true ) ) {
					continue;
				}
				if ( ( $row['target'] !== $row['slug'] ) !== $renamed_pass ) {
					continue;
				}
				$key = $row['kind'] . ':' . $row['target'];
				if ( ! isset( $claimed[ $key ] ) ) {
					$claimed[ $key ] = $row['slug'];
					continue;
				}
				$rows[ $i ]['status'] = 'conflict';
				$rows[ $i ]['note']   = sprintf(
					/* translators: 1: slug of the template that is applied, 2: the shared new name. */
					__( 'Another one ("%1$s") is also bound to "%2$s". Only the first is applied, and this one is kept as it is. Bind one of them to something else to use both.', 'block-theme-migration' ),
					$claimed[ $key ],
					$row['target']
				);
			}
		}
		return $rows;
	}

	/** このツールの「Editable pattern import」で取り込んだ wp_block か（印は、取り込み元の識別メタ） */
	private static function is_imported_pattern( int $post_id ): bool {
		return $post_id > 0 && '' !== (string) get_post_meta( $post_id, '_itmar_btm_pattern_source', true );
	}

	/* ------------------------------------------------------------------ */
	/* 適用・元に戻す                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * 計画を適用する。作ったもの・更新したものを記録し、実行の記録を返す。
	 *
	 * @return array{id:string,time:int,entries:array,errors:string[]}
	 */
	public static function apply( array $plan, bool $overwrite ): array {
		wp_raise_memory_limit( 'admin' );
		$run = array( 'id' => wp_generate_password( 8, false ), 'time' => time(), 'entries' => array(), 'errors' => array() );
		foreach ( $plan['rows'] as $row ) {
			if ( ! in_array( $row['status'], array( 'updated', 'renamed' ), true ) ) {
				continue;
			}
			$content = $row['bound'] ?? $row['content'];
			if ( 'synced' === $row['kind'] ) {
				$block = get_post( (int) $row['id'] );
				if ( ! $block || 'wp_block' !== $block->post_type ) {
					$run['errors'][] = 'synced ' . $row['slug'] . ': not found';
					continue;
				}
				$result = wp_update_post( wp_slash( array( 'ID' => $block->ID, 'post_content' => $content ) ), true );
				if ( is_wp_error( $result ) ) {
					$run['errors'][] = 'synced ' . $row['slug'] . ': ' . $result->get_error_message();
					continue;
				}
				$run['entries'][] = array(
					'id'      => (int) $block->ID,
					'kind'    => 'synced',
					'slug'    => $row['slug'],
					'source'  => $row['slug'],
					'action'  => 'updated',
					'content' => (string) $block->post_content,
					'title'   => (string) $block->post_title,
					'meta'    => array(),
					'count'   => count( $row['changes'] ),
				);
				self::store_run( $run );
				continue;
			}
			$post_type = 'part' === $row['kind'] ? 'wp_template_part' : 'wp_template';

			$existing    = get_block_template( get_stylesheet() . '//' . $row['target'], $post_type );
			$existing_id = $existing && ! empty( $existing->wp_id ) ? (int) $existing->wp_id : 0;
			$previous    = $existing_id ? get_post( $existing_id ) : null;

			// 管理対象の印（識別メタ）も、元に戻すときに戻せるよう、適用前の値を控える
			$meta_before = array();
			if ( $existing_id ) {
				foreach ( self::META_KEYS as $meta_key ) {
					$meta_before[ $meta_key ] = metadata_exists( 'post', $existing_id, $meta_key ) ? get_post_meta( $existing_id, $meta_key, true ) : null;
				}
			}
			$migration = array( 'source' => $row['slug'], 'target' => $row['target'], 'package' => 'binding' );
			$saved     = 'part' === $row['kind']
				? Itmar_Block_Theme_Migration::save_template_part_override( $row['target'], $row['title'], $content, $row['area'] ?: 'uncategorized', true, $migration )
				: Itmar_Block_Theme_Migration::save_template_override( $row['target'], $row['title'], $content, true, $migration );
			if ( is_wp_error( $saved ) ) {
				$run['errors'][] = $row['kind'] . ' ' . $row['slug'] . ': ' . $saved->get_error_message();
				continue;
			}
			$run['entries'][] = array(
				'id'      => (int) $saved,
				'kind'    => $row['kind'],
				'slug'    => $row['target'],
				'source'  => $row['slug'],
				'action'  => $previous ? 'updated' : 'created',
				'content' => $previous ? (string) $previous->post_content : '',
				'title'   => $previous ? (string) $previous->post_title : '',
				'meta'    => $meta_before,
				'count'   => count( $row['changes'] ),
			);
			// 1件ごとに記録する（途中で止まっても、そこまでを元に戻せるように）
			self::store_run( $run );
		}
		return $run;
	}

	/** 実行の記録を、保存する（同じ ID があれば置き換える） */
	public static function store_run( array $run ): void {
		$runs  = self::runs();
		$found = false;
		foreach ( $runs as $index => $existing ) {
			if ( ( $existing['id'] ?? '' ) === $run['id'] ) {
				$runs[ $index ] = $run;
				$found          = true;
			}
		}
		if ( ! $found ) {
			$runs[] = $run;
		}
		update_option( self::runs_option(), array_slice( $runs, -self::MAX_RUNS ), false );
	}

	/** @return array<int,array> */
	public static function runs(): array {
		$runs = get_option( self::runs_option(), array() );
		return is_array( $runs ) ? array_values( $runs ) : array();
	}

	/**
	 * 実行を元に戻す。作ったものはゴミ箱へ、更新したものは元の本文へ。
	 *
	 * @return string[] 結果のメッセージ
	 */
	public static function undo( string $run_id ): array {
		$runs    = self::runs();
		$messages = array();
		foreach ( $runs as $index => $run ) {
			if ( ( $run['id'] ?? '' ) !== $run_id ) {
				continue;
			}
			foreach ( array_reverse( (array) $run['entries'] ) as $entry ) {
				$post = get_post( (int) $entry['id'] );
				if ( ! $post ) {
					$messages[] = sprintf( '%s: not found (already removed).', $entry['slug'] );
					continue;
				}
				if ( 'created' === $entry['action'] ) {
					wp_trash_post( $post->ID );
					$messages[] = sprintf( '%s: moved to Trash.', $entry['slug'] );
				} else {
					wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_content' => $entry['content'], 'post_title' => $entry['title'] ) ) );
					foreach ( (array) ( $entry['meta'] ?? array() ) as $meta_key => $meta_value ) {
						if ( null === $meta_value ) {
							delete_post_meta( $post->ID, (string) $meta_key );
						} else {
							update_post_meta( $post->ID, (string) $meta_key, $meta_value );
						}
					}
					$messages[] = sprintf( '%s: restored to the previous content.', $entry['slug'] );
				}
				clean_post_cache( $post->ID );
			}
			unset( $runs[ $index ] );
			update_option( self::runs_option(), array_values( $runs ), false );
			WP_Theme_JSON_Resolver::clean_cached_data();
			return $messages;
		}
		return array( 'No such run.' );
	}

	/* ------------------------------------------------------------------ */
	/* 画面                                                                  */
	/* ------------------------------------------------------------------ */

	public static function handle_bind(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'block-theme-migration' ) );
		}
		check_admin_referer( 'itmar_btm_bind' );
		$form      = self::sanitize_form( wp_unslash( $_POST['bind'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitize_form() validates.
		$action    = isset( $_POST['bind_action'] ) ? sanitize_key( wp_unslash( $_POST['bind_action'] ) ) : 'preview';
		$overwrite = ! empty( $_POST['bind_overwrite'] );

		update_option( self::bindings_option(), $form, false );
		$references = self::references();
		$plan       = self::plan( self::effective( $form ), $references, $overwrite );

		$result = array( 'action' => $action, 'summary' => $plan['summary'], 'patterns' => $plan['patterns'], 'rows' => array() );
		foreach ( $plan['rows'] as $row ) {
			$result['rows'][] = array(
				'kind' => $row['kind'], 'slug' => $row['slug'], 'target' => $row['target'], 'status' => $row['status'],
				'note' => $row['note'], 'changes' => array_slice( $row['changes'], 0, 200 ), 'count' => count( $row['changes'] ),
			);
		}
		if ( 'apply' === $action ) {
			$run = self::apply( $plan, $overwrite );
			$result['applied'] = array( 'id' => $run['id'], 'count' => count( $run['entries'] ), 'errors' => $run['errors'] );
		}
		set_transient( 'itmar_btm_bind_result_' . get_current_user_id(), $result, HOUR_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'view', self::VIEW, admin_url( 'tools.php?page=' . Itmar_Block_Theme_Migration::MENU_SLUG ) ) );
		exit;
	}

	public static function handle_undo(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'block-theme-migration' ) );
		}
		check_admin_referer( 'itmar_btm_bind_undo' );
		$messages = self::undo( isset( $_POST['run_id'] ) ? sanitize_text_field( wp_unslash( $_POST['run_id'] ) ) : '' );
		set_transient( 'itmar_btm_bind_result_' . get_current_user_id(), array( 'action' => 'undo', 'messages' => $messages ), HOUR_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'view', self::VIEW, admin_url( 'tools.php?page=' . Itmar_Block_Theme_Migration::MENU_SLUG ) ) );
		exit;
	}

	private static function select( string $name, string $current, array $options, string $empty_label, bool $allow_keep ): void {
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<?php if ( $allow_keep ) : ?>
				<option value="<?php echo esc_attr( self::KEEP ); ?>" <?php selected( $current, self::KEEP ); ?>><?php echo esc_html__( 'Keep as it is (same name here)', 'block-theme-migration' ); ?></option>
			<?php endif; ?>
			<option value="" <?php selected( $current, '' ); ?>><?php echo esc_html( $empty_label ); ?></option>
			<?php foreach ( $options as $value => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $current, (string) $value ); ?>><?php echo esc_html( (string) $value . ( '' !== $label ? ' (' . $label . ')' : '' ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	public static function render(): void {
		$result = get_transient( 'itmar_btm_bind_result_' . get_current_user_id() );
		if ( $result ) {
			delete_transient( 'itmar_btm_bind_result_' . get_current_user_id() );
		}
		$references = self::references();
		$values     = self::defaults( $references );
		$dest_types = self::destination_post_types();
		$dest_taxes = self::destination_taxonomies();
		$dest_flds  = self::destination_fields();
		$base_url   = admin_url( 'admin-post.php' );
		?>
		<div style="max-width:1100px">
			<p class="description"><?php echo esc_html__( 'Bind the post types, taxonomies and custom fields that this theme refers to, to the ones that already exist on this site. Nothing is created. Run a preview first; every apply can be undone.', 'block-theme-migration' ); ?></p>
		<?php if ( is_array( $result ) ) : ?>
			<?php self::render_result( $result ); ?>
		<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $base_url ); ?>">
				<input type="hidden" name="action" value="itmar_btm_bind">
				<?php wp_nonce_field( 'itmar_btm_bind' ); ?>

				<h2><?php echo esc_html__( 'Post types', 'block-theme-migration' ); ?></h2>
				<?php if ( empty( $references['postTypes'] ) ) : ?>
					<p><?php echo esc_html__( 'This theme does not refer to any post type outside the standard ones.', 'block-theme-migration' ); ?></p>
				<?php else : ?>
					<table class="widefat striped"><tbody>
					<?php foreach ( $references['postTypes'] as $source ) : ?>
						<tr><td style="width:14em"><code><?php echo esc_html( $source ); ?></code></td><td>
							<?php self::select( 'bind[postTypes][' . $source . ']', (string) $values['postTypes'][ $source ], $dest_types, __( '(Not bound — remove the reference)', 'block-theme-migration' ), isset( $dest_types[ $source ] ) ); ?>
						</td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>

				<h2><?php echo esc_html__( 'Taxonomies', 'block-theme-migration' ); ?></h2>
				<?php if ( empty( $references['taxonomies'] ) ) : ?>
					<p><?php echo esc_html__( 'This theme does not refer to any taxonomy outside the standard ones.', 'block-theme-migration' ); ?></p>
				<?php else : ?>
					<table class="widefat striped"><tbody>
					<?php foreach ( $references['taxonomies'] as $source ) : ?>
						<tr><td style="width:14em"><code><?php echo esc_html( $source ); ?></code></td><td>
							<?php self::select( 'bind[taxonomies][' . $source . ']', (string) $values['taxonomies'][ $source ], $dest_taxes, __( '(Not bound — remove the reference)', 'block-theme-migration' ), isset( $dest_taxes[ $source ] ) ); ?>
						</td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>

				<h2><?php echo esc_html__( 'Custom fields', 'block-theme-migration' ); ?></h2>
				<?php if ( empty( $references['fields'] ) ) : ?>
					<p><?php echo esc_html__( 'This theme does not refer to any custom field.', 'block-theme-migration' ); ?></p>
				<?php elseif ( ! Itmar_BTM_DataModel::fields_available() ) : ?>
					<p><?php echo esc_html__( 'No custom-fields plugin (Smart Custom Fields / ACF) is active here, so the fields cannot be bound. They will be left as they are.', 'block-theme-migration' ); ?></p>
				<?php else : ?>
					<table class="widefat striped"><tbody>
					<?php foreach ( $references['fields'] as $source ) : ?>
						<tr><td style="width:14em"><code><?php echo esc_html( $source ); ?></code></td><td>
							<?php self::select( 'bind[fields][' . $source . ']', (string) $values['fields'][ $source ], $dest_flds, __( '(Not bound — leave empty)', 'block-theme-migration' ), isset( $dest_flds[ Itmar_BTM_DataModel::field_name_of( $source ) ] ) ); ?>
						</td></tr>
					<?php endforeach; ?>
					</tbody></table>
				<?php endif; ?>

				<p style="margin-top:1.5em">
					<label><input type="checkbox" name="bind_overwrite" value="1"> <?php echo esc_html__( 'Allow overwriting a template that already has the new name', 'block-theme-migration' ); ?></label>
				</p>
				<p>
					<button class="button" name="bind_action" value="preview"><?php echo esc_html__( 'Preview (nothing is changed)', 'block-theme-migration' ); ?></button>
					<button class="button button-primary" name="bind_action" value="apply"><?php echo esc_html__( 'Apply bindings', 'block-theme-migration' ); ?></button>
				</p>
			</form>

			<?php self::render_runs(); ?>
		</div>
		<?php
	}

	private static function render_runs(): void {
		$runs = array_reverse( self::runs() );
		if ( empty( $runs ) ) {
			return;
		}
		?>
		<h2><?php echo esc_html__( 'Applied bindings', 'block-theme-migration' ); ?></h2>
		<table class="widefat striped"><tbody>
		<?php foreach ( $runs as $run ) : ?>
			<tr>
				<td style="width:12em"><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $run['time'] ) ); ?></td>
				<td><?php echo esc_html( implode( ', ', array_map( static fn( $e ) => $e['slug'], (array) $run['entries'] ) ) ); ?></td>
				<td style="width:8em">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="itmar_btm_bind_undo">
						<input type="hidden" name="run_id" value="<?php echo esc_attr( (string) $run['id'] ); ?>">
						<?php wp_nonce_field( 'itmar_btm_bind_undo' ); ?>
						<button class="button"><?php echo esc_html__( 'Undo', 'block-theme-migration' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody></table>
		<?php
	}

	private static function render_result( array $result ): void {
		if ( 'undo' === $result['action'] ) {
			echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Undone.', 'block-theme-migration' ) . '</strong></p><ul>';
			foreach ( (array) $result['messages'] as $message ) {
				echo '<li>' . esc_html( (string) $message ) . '</li>';
			}
			echo '</ul></div>';
			return;
		}
		$applied = $result['applied'] ?? null;
		$heading = $applied
			? sprintf( /* translators: %d: number of templates and parts saved. */ _n( 'Applied: %d template or part was saved.', 'Applied: %d templates and parts were saved.', (int) $applied['count'], 'block-theme-migration' ), (int) $applied['count'] )
			: __( 'Preview (nothing was changed).', 'block-theme-migration' );
		$class = $applied && ! empty( $applied['errors'] ) ? 'notice-warning' : 'notice-info';
		echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p><strong>' . esc_html( $heading ) . '</strong> ';
		echo esc_html( implode( ', ', array_map( static fn( $k, $v ) => $k . ': ' . $v, array_keys( $result['summary'] ), $result['summary'] ) ) );
		echo '</p>';
		foreach ( (array) ( $applied['errors'] ?? array() ) as $error ) {
			echo '<p>' . esc_html( (string) $error ) . '</p>';
		}
		echo '</div>';

		$rows = array_filter( $result['rows'], static fn( $r ) => 'unchanged' !== $r['status'] );
		if ( empty( $rows ) ) {
			echo '<p>' . esc_html__( 'No template or part needs to change.', 'block-theme-migration' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="margin-bottom:1em"><thead><tr><th>' . esc_html__( 'Item', 'block-theme-migration' ) . '</th><th>' . esc_html__( 'Result', 'block-theme-migration' ) . '</th><th>' . esc_html__( 'Changes', 'block-theme-migration' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $row ) {
				echo '<tr><td>' . esc_html( $row['kind'] . ': ' . $row['slug'] . ( $row['target'] !== $row['slug'] ? ' → ' . $row['target'] : '' ) ) . '</td>';
				echo '<td><strong>' . esc_html( strtoupper( $row['status'] ) ) . '</strong> ' . esc_html( $row['note'] ) . '</td><td>';
				if ( ! empty( $row['changes'] ) ) {
					echo '<details><summary>' . esc_html( (string) $row['count'] ) . '</summary><ul style="margin:.5em 0">';
					foreach ( $row['changes'] as $change ) {
						echo '<li><code>' . esc_html( $change['block'] . ' ' . $change['path'] ) . '</code>: ' . esc_html( $change['from'] . ' → ' . ( '' === $change['to'] ? '(removed)' : $change['to'] ) ) . '</li>';
					}
					echo '</ul></details>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( ! empty( $result['patterns'] ) ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Not rewritten automatically (theme pattern files and synced patterns). Review them in the editor:', 'block-theme-migration' ) . '</strong> ';
			echo esc_html( implode( ', ', array_map( static fn( $p ) => ( $p['title'] ?: $p['slug'] ) . ' (' . $p['count'] . ')', $result['patterns'] ) ) );
			echo '</p></div>';
		}
	}
}

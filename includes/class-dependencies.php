<?php
/**
 * 依存の検査（移植先）。マニフェスト v5 の requires と dataModel を、このサイトの実際の状態と突き合わせる。
 *
 * 不足は示すだけで、自動の導入・有効化・作成はしない（要件 IM-2、D3）。
 * 投稿タイプ・タクソノミー・フィールドは、移植先に既にあるものへの紐づけ（M3）の手がかりとして、有無だけを見る。
 *
 * @package block-theme-migration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Itmar_BTM_Dependencies {

	/**
	 * @return array<int, array{group:string,status:string,label:string,detail:string}>
	 */
	public static function check( array $manifest ): array {
		$rows = array();
		$add  = static function ( string $group, string $status, string $label, string $detail = '' ) use ( &$rows ): void {
			$rows[] = compact( 'group', 'status', 'label', 'detail' );
		};

		$requires = is_array( $manifest['requires'] ?? null ) ? $manifest['requires'] : array();
		$model    = is_array( $manifest['dataModel'] ?? null ) ? $manifest['dataModel'] : array();

		// WordPress
		$min = (string) ( $requires['wordpress']['minVersion'] ?? Itmar_BTM_DataModel::MIN_WORDPRESS );
		if ( '' !== $min ) {
			$current = get_bloginfo( 'version' );
			$add( 'WordPress', version_compare( $current, $min, '>=' ) ? 'ok' : 'error', 'WordPress ' . $min . '+', sprintf( /* translators: %s: installed version. */ __( 'Installed: %s', 'block-theme-migration' ), $current ) );
		}

		// プラグイン
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$by_dir = array();
		foreach ( get_plugins() as $file => $data ) {
			$by_dir[ strtok( $file, '/' ) ] = array( 'file' => $file, 'data' => $data );
		}
		foreach ( (array) ( $requires['plugins'] ?? array() ) as $plugin ) {
			$slug     = (string) ( $plugin['slug'] ?? '' );
			$optional = ! empty( $plugin['optional'] );
			$label    = ( $plugin['name'] ?? $slug ) . ( $optional ? ' (' . __( 'optional', 'block-theme-migration' ) . ')' : '' );
			$miss     = $optional ? 'warn' : 'error';
			if ( '' === $slug || ! isset( $by_dir[ $slug ] ) ) {
				$add( __( 'Plugins', 'block-theme-migration' ), $miss, $label, __( 'Not installed.', 'block-theme-migration' ) );
				continue;
			}
			if ( ! is_plugin_active( $by_dir[ $slug ]['file'] ) ) {
				$add( __( 'Plugins', 'block-theme-migration' ), $miss, $label, __( 'Installed but not active.', 'block-theme-migration' ) );
				continue;
			}
			$installed = (string) $by_dir[ $slug ]['data']['Version'];
			$built     = (string) ( $plugin['minVersion'] ?? '' );
			if ( '' !== $built && version_compare( $installed, $built, '<' ) ) {
				$add( __( 'Plugins', 'block-theme-migration' ), 'warn', $label, sprintf( /* translators: 1: installed version, 2: version the theme was built with. */ __( 'Version %1$s is installed; the theme was built with %2$s. Update if blocks look wrong.', 'block-theme-migration' ), $installed, $built ) );
			} else {
				$add( __( 'Plugins', 'block-theme-migration' ), 'ok', $label, sprintf( /* translators: %s: installed version. */ __( 'Active, version %s', 'block-theme-migration' ), $installed ) );
			}
		}

		// ブロック（プラグインが入っていても、登録されていなければ使えない）
		$unregistered = array();
		foreach ( (array) ( $requires['blocks'] ?? array() ) as $block ) {
			if ( ! WP_Block_Type_Registry::get_instance()->is_registered( (string) $block ) ) {
				$unregistered[] = (string) $block;
			}
		}
		if ( ! empty( $requires['blocks'] ) ) {
			$add(
				__( 'Blocks', 'block-theme-migration' ),
				empty( $unregistered ) ? 'ok' : 'error',
				sprintf( /* translators: %d: number of blocks. */ _n( '%d block is used', '%d blocks are used', count( $requires['blocks'] ), 'block-theme-migration' ), count( $requires['blocks'] ) ),
				empty( $unregistered ) ? __( 'All are registered on this site.', 'block-theme-migration' ) : __( 'Not registered here:', 'block-theme-migration' ) . ' ' . implode( ', ', $unregistered )
			);
		}

		// データモデル（有無だけ。無ければ、移植先の別のものに紐づける）
		foreach ( (array) ( $model['postTypes'] ?? array() ) as $type ) {
			$slug = (string) ( $type['slug'] ?? '' );
			$add( __( 'Post types', 'block-theme-migration' ), post_type_exists( $slug ) ? 'ok' : 'warn', $slug . ( ! empty( $type['label'] ) ? ' (' . $type['label'] . ')' : '' ), post_type_exists( $slug ) ? __( 'Registered on this site.', 'block-theme-migration' ) : __( 'Not registered here. It can be bound to another post type; nothing is created.', 'block-theme-migration' ) );
		}
		foreach ( (array) ( $model['taxonomies'] ?? array() ) as $taxonomy ) {
			$slug = (string) ( $taxonomy['slug'] ?? '' );
			$add( __( 'Taxonomies', 'block-theme-migration' ), taxonomy_exists( $slug ) ? 'ok' : 'warn', $slug . ( ! empty( $taxonomy['label'] ) ? ' (' . $taxonomy['label'] . ')' : '' ), taxonomy_exists( $slug ) ? __( 'Registered on this site.', 'block-theme-migration' ) : __( 'Not registered here. It can be bound to another taxonomy; nothing is created.', 'block-theme-migration' ) );
		}

		// 投稿メタ（meta_xxx。連携プラグインなどが登録する）
		foreach ( (array) ( $model['postMeta'] ?? array() ) as $meta ) {
			$key     = (string) ( $meta['key'] ?? '' );
			$types   = array_merge( array( 'post' ), array_column( (array) ( $model['postTypes'] ?? array() ), 'slug' ) );
			$present = false;
			foreach ( $types as $type ) {
				if ( array_key_exists( $key, get_registered_meta_keys( 'post', (string) $type ) ) ) {
					$present = true;
					break;
				}
			}
			$add( __( 'Post meta', 'block-theme-migration' ), $present ? 'ok' : 'warn', (string) ( $meta['reference'] ?? $key ), $present ? __( 'A registered meta key exists here.', 'block-theme-migration' ) : __( 'Not registered here (a plugin may register it when it is active). It can be left empty.', 'block-theme-migration' ) );
		}

		// カスタムフィールドの参照
		$references = array();
		foreach ( (array) ( $model['usage'] ?? array() ) as $u ) {
			// meta_xxx は投稿メタ（上で検査済み）。カスタムフィールドとして数えない
			if ( 'field' === ( $u['kind'] ?? '' ) && 0 !== strpos( (string) $u['value'], 'meta_' ) ) {
				$references[ (string) $u['value'] ] = true;
			}
		}
		if ( ! empty( $references ) ) {
			if ( ! Itmar_BTM_DataModel::fields_available() ) {
				$add( __( 'Custom fields', 'block-theme-migration' ), 'warn', sprintf( /* translators: %d: number of referenced fields. */ _n( '%d custom field is referenced', '%d custom fields are referenced', count( $references ), 'block-theme-migration' ), count( $references ) ), __( 'No custom-fields plugin is active here, so the fields cannot be bound.', 'block-theme-migration' ) );
			} else {
				$names = self::destination_field_names();
				foreach ( array_keys( $references ) as $reference ) {
					$name = Itmar_BTM_DataModel::field_name_of( $reference );
					$add( __( 'Custom fields', 'block-theme-migration' ), in_array( $name, $names, true ) ? 'ok' : 'warn', $reference, in_array( $name, $names, true ) ? __( 'A field with this name exists here.', 'block-theme-migration' ) : __( 'No field with this name here. It can be bound to another field, or left empty.', 'block-theme-migration' ) );
				}
			}
		}

		return $rows;
	}

	/** このサイトの、すべてのフィールドの名前（読み取り専用） */
	private static function destination_field_names(): array {
		$names = array();
		foreach ( acf_get_field_groups() as $group ) {
			foreach ( (array) acf_get_fields( $group ) as $field ) {
				$names[] = (string) ( $field['name'] ?? '' );
				foreach ( (array) ( $field['sub_fields'] ?? array() ) as $sub ) {
					$names[] = (string) ( $field['name'] ?? '' ) . '.' . (string) ( $sub['name'] ?? '' );
				}
			}
		}
		return array_values( array_unique( array_filter( $names ) ) );
	}

	/** 画面（Template Mapping の上部）に、検査の結果を出す */
	public static function render( array $manifest ): void {
		if ( empty( $manifest['requires'] ) && empty( $manifest['dataModel'] ) ) {
			return; // v4 以前のパッケージ
		}
		$rows   = self::check( $manifest );
		$counts = array( 'ok' => 0, 'warn' => 0, 'error' => 0 );
		foreach ( $rows as $row ) {
			$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
		}
		?>
		<div class="itmar-btm-deps" style="max-width:1100px;margin-bottom:1.5em">
			<h3><?php echo esc_html__( 'Requirements of this package', 'block-theme-migration' ); ?></h3>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: ok count, 2: warning count, 3: error count. */
						__( '%1$d satisfied, %2$d to check, %3$d missing. Nothing is installed, activated or created automatically.', 'block-theme-migration' ),
						$counts['ok'],
						$counts['warn'],
						$counts['error']
					)
				);
				?>
			</p>
			<table class="widefat striped">
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td style="width:7em"><?php echo esc_html( $row['group'] ); ?></td>
						<td style="width:5em"><strong><?php echo esc_html( strtoupper( $row['status'] ) ); ?></strong></td>
						<td><?php echo esc_html( $row['label'] ); ?></td>
						<td class="description"><?php echo esc_html( $row['detail'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}

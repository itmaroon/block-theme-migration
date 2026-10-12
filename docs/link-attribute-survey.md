# 内部リンク・参照の属性の洗い出し（M1 の調査結果）

調査日: 2026-10-05／対応する要件: `requirements-v0.2.md` §7（ブロック規則）、D6・D7

## 1. 調査の方法

1. **宣言の調査**: プラグインの `block.json`（`block-collections`、`query-blocks`、`form-send-blocks`、`itmaroon-booking-block`、
   `itmaroon-ec-relate-blocks`、`slide-blocks`、`opening-block`、`markdown-block`、`itmaroon-back-anime`）から、
   リンク・ページ・投稿タイプ・メニューを持ちうる属性を拾った（14ブロック、52属性）。
2. **実データの調査**: 開発サイト（51項目）と sazanka（89項目）の、DB の `wp_template` / `wp_template_part` / `wp_block` と、
   テーマフォルダのテンプレート・パーツ・パターンを走査した。次を、ブロック名 × 属性のパスで集計した。
   - ブロックコメントの属性と、`data-attributes`（HTML エスケープされた JSON）の両方
   - 本文中の `<a href>`
   - 内部／外部／空の判定（移植元のホーム URL、`[home_url]`、`/` で始まるパスを内部とする）

走査用スクリプト: `scratchpad/scan_link_attrs_static.py`、`scan_link_attrs_dynamic.py`（保存先は文書化していない。必要なら M1 で正式なスキャナーにする）。

## 2. 結論（外す内部リンクの属性）

### 2.1 そのまま外してよい属性（内部リンク）

| ブロック | 属性 | 意味 | 処置 |
|---|---|---|---|
| `itmar/design-button` | `selectedPageUrl` | リンク先の URL（`[home_url]/…` またはホーム URL ＋パス） | 内部なら空にする。外部（SNS など）は残す |
| `itmar/design-title` | `selectedPageUrl` | 同上 | 同上 |
| `itmar/design-group` | `selectedPageUrl` | `is_link` のときのリンク先（実データには出現せず、宣言のみ） | 同上 |
| `itmar/coustom-login` | `redirectUrl`、`redirectPath` | ログイン後の移動先 | 内部なら空にする |
| `itmar/thanks-figure-block` | `selectedPageUrl` | 完了後のリンク先 | 内部なら空にする |
| `core/navigation` | `ref` | メニューの投稿 ID | **外す**（D6） |
| 本文の `<a href>` | （コアの段落・リスト等の本文） | 本文中のリンク | 内部なら `href` を外して文字だけ残す。外部は残す |

### 2.2 `linkKind` に依存して、意味が変わる属性（要注意）

`itmar/design-button` と `itmar/design-title` の `selectedSlug` は、**`linkKind` によって意味が違う。**

| `linkKind` | `selectedSlug` の意味 | 処置 |
|---|---|---|
| `fixed` | 固定ページの slug | 空にする（D7）。`selectedPageUrl` も空にする |
| `archive`／`single` | **投稿タイプの slug**（例: `works`、`post`） | 外さない。**投稿タイプの紐づけ（BD-1）の対象** |
| `login`／`open`／`close`／`submenu`／`none`／`forward`／`back`／`stretch`／`free`／`submit` | 使わない（または補助） | 触らない |

特殊値: `selectedSlug` が `__home__`（ホームへのリンク）の場合も、リンクとして外す。

→ 要件 §7 の規則は、「ブロック名 × 属性」だけでなく、**「`linkKind` の値による条件」を持てる必要がある。**
（現行の不具合「`selectedSlug` を無条件にページ slug として書き換える」の、もう一つの原因はここにある）

### 2.3 属性の中に、別のブロックの属性が埋め込まれている箇所（再帰が必要）

次のブロックは、`design-button` / `design-title` / `core/image` などの**属性の組を、自分の属性の中に持つ**。
これらの内側の `selectedPageUrl`・`url`・`linkKind` も、2.1・2.2 の規則で処理しなければならない。

| ブロック | 埋め込みを持つ属性 |
|---|---|
| `itmar/pickup-posts` | `blocksAttributesArray`（`innerBlocks` の木。`design-title`、`design-button`、`core/image` など）、`emptyMessageAttributes` |
| `itmar/product-block` | `blocksAttributesArray`（同上） |
| `itmar/cart-block` | `blocksAttributesArray`（同上） |
| `itmar/post-pagenation` | `forwardBlockAttributes`、`backBlockAttributes`、`dummyBlockAttributes`、`numBlockAttributes`、`forwardTitleAttributes`、`backTitleAttributes` |
| `itmar/post-filter` | `titleAttributes`、`searchButtonAttributes`、`dayAttributes`（さらに `innerBlocks` の入れ子） |
| `itmar/post-crumbs` | `crumbBlockAttributes` |
| `itmar/markdown-block` | `element_style_obj`（`H2`、`H3` など、見出しごとの `design-title` 属性） |

- これらの `selectedPageUrl` の値は、多くが**サイトのホーム URL そのもの**（`https://develope-equip.local`、`http://sazanka.local`）で、
  実行時に投稿ごとのリンクへ置き換わる雛形である。外してよい。
- **開発サイトのホーム URL 以外の、過去の開発サイトの URL**（`http://equip-backup.local`、`https://block-test.local`）も混ざっている。
  → **`.local` ホストは、すべてサイト固有の URL として扱う**（移植元のホームかどうかに関わらず除去の対象）。

### 2.4 リンクの属性ではないが、同じ処理で扱うべきサイト固有の値

| 種類 | ブロック・属性 | 内容 | 処置（D8: 中身は運ばない） |
|---|---|---|---|
| メディア | `itmar/design-button` の `media`（`url`、`link`、`sizes.*.url`、`authorLink`、`editLink`、`icon`）、`itmar/masonry-mv`・`itmar/fade-mv` の `default_val.media[]`・`mobile_val.media[]`、`core/image` の `url` | アップロードされたメディアと、その添付ページ・管理画面の URL | メディアのオブジェクトごと空にする。`wp-content/plugins/` のアセットは、ルート相対にして残す |
| **保存された投稿データ** | **`itmar/pickup-posts` の `posts`** | **取得済みの投稿の一覧（タイトル、リンク、スラッグ、タームのスラッグ、カスタムフィールドの値）がブロックに保存されている** | **空にする（中身は運ばない）。移植元の投稿の内容が、そのまま残る危険がある** |
| 外部サービスの設定 | `itmar/product-block` の `storeUrl`、`callbackUrl` | Shopify のストアの URL など（`xxx.myshopify.com`） | 空にする（移植先の設定は、移植先で行う） |
| 予約のリソース | `itmar/reservation-block` の `resourceId`、`resourceSlug`、`resourceRest`、`selectedSlug`、`selectedRest`、`closedWeekdays` | リソースの投稿 ID と、投稿タイプ | `resourceId`・`resourceSlug`・`closedWeekdays` は既定値に戻す。**`selectedSlug`・`selectedRest` は、投稿タイプの紐づけ（BD-1）の対象** |
| 外部のアバター | `itmar/design-title` の `optionStyle.icon_style.avatar_url` | Gravatar（外部） | 残す（外部リンク） |

### 2.5 紐づけの対象（宣言と使用箇所の索引に載せる属性）

投稿タイプ、タクソノミー、フィールドの参照は、`query-blocks` の `pickup-posts` に集中している。

| 種類 | ブロック・属性 | 例（sazanka の実データ） |
|---|---|---|
| 投稿タイプ（slug） | `pickup-posts.selectedSlug`、`design-title.selectedSlug`（`linkKind` が `archive`／`single`）、`reservation-block.selectedSlug` | `works`、`concept`、`post`、`lesson_resource` |
| 投稿タイプ（REST の基底名） | `pickup-posts.selectedRest`、`reservation-block.selectedRest` | `works`、`concept` |
| 投稿タイプ（ラベルの文字） | `post-crumbs.postTypeName` | `作品` |
| タクソノミー | `pickup-posts.dispTaxonomies`、`pickup-posts.choiceTerms`、`taxRelateType` | `creator`、`kind` |
| フィールド | `pickup-posts.choiceFields`、`pickup-posts.searchFields`、`pickup-posts.blockMap`（キーがフィールド名）、`post-filter.searchFields` | コアの項目（`title`、`date`、`content`、`excerpt`、`featured_media`、`link`）と、カスタムの項目（`acf_gallery`、`acf_relate_url`、`relate_url`） |

- `choiceFields` のうち、`acf_` で始まるもの以外は、WordPress のコアの項目なので、紐づけは不要。
- `blockMap` のキーは、フィールド名であり、紐づけたときに**キーの置き換え**が要る（値のブロック名は変えない）。
- `post-crumbs.postTypeName` は、**移植先の投稿タイプのラベル**に置き換えるのが自然。

### 2.6 触らないもの（リンクのように見えるが、違うもの）

| 属性 | 理由 |
|---|---|
| `linkKind`、`isBlank`、`modalClassName` | 動作の種類・設定であり、リンク先ではない |
| `core/image` の `sizeSlug`、`linkDestination`（`none`） | 画像のサイズ指定・リンク種別。`linkDestination` が `none` 以外のときだけ、`href` を判定する |
| `core/template-part` の `slug`、`core/pattern` の `slug` | テーマ内の部品の参照。パッケージ内で完結する |
| `core/block` の `ref` | 同期パターンの参照。現行の機能（テーマのパターンへの変換）で処理済み |
| `selectedBlockId` | ブロック間の識別子（例: `Default_ID`） |

## 3. 副次的な発見

1. **sazanka に、開発サイトの URL が残っている。** `itmar/design-button` の `media`（`authorLink`、`editLink`、`link`、`sizes.*.url`）に、
   `http://develope-equip.local/…` が、sazanka のテンプレート・パーツの中に3件ずつ残っている。
   → 過去の移植で、メディアの除去（要件 1）をしていなかった分。今回の除去規則（§2.4）で解消できる。
2. **本文のリンクは、`[home_url]` 形式と絶対 URL が混在している。** `[home_url]/archivepage` と
   `https://develope-equip.local/fixed-page-for-main-view/` の両方が出る。内部リンクの判定は、両方の形式を扱う必要がある。
3. **実データのリンクは、`design-title`（メニュー項目）が圧倒的に多い**（開発サイトで内部リンク 148 件）。
   メニューの項目の多くが、`design-title` の `selectedPageUrl` で作られている。D6（メニューの紐づけを運ばない）と D7（内部リンクを外す）は、
   ここで効く。外した後の項目は、リンクの無い文字になる。

## 4. 要件への反映

`requirements-v0.2.md` に、次を反映した。

- §7 ブロック規則に、**`linkKind` による条件**、**埋め込みブロックの再帰**、**本文の `<a href>`**、**保存された投稿データ（`pickup-posts.posts`）の除去**を追加。
- 内部リンクの判定に、**`.local` ホストの扱い**（すべてサイト固有）を追加。
- §6.2 データモデルの宣言の、使用箇所の索引に、本書 §2.5 の属性を採用。

## 5. 規則の種（M1 のスキャナーの初期データ案）

```json
{
  "itmar/design-button": [
    { "path": "selectedPageUrl", "kind": "page-url" },
    { "path": "selectedSlug", "kind": "page", "when": { "linkKind": "fixed" } },
    { "path": "selectedSlug", "kind": "post-type", "when": { "linkKind": ["archive", "single"] } },
    { "path": "media", "kind": "media" }
  ],
  "itmar/design-title": [
    { "path": "selectedPageUrl", "kind": "page-url" },
    { "path": "selectedSlug", "kind": "page", "when": { "linkKind": "fixed" } },
    { "path": "selectedSlug", "kind": "post-type", "when": { "linkKind": ["archive", "single"] } }
  ],
  "itmar/design-group": [ { "path": "selectedPageUrl", "kind": "page-url" } ],
  "itmar/coustom-login": [
    { "path": "redirectUrl", "kind": "page-url" },
    { "path": "redirectPath", "kind": "page-url" },
    { "path": "selectedSlug", "kind": "page" }
  ],
  "itmar/thanks-figure-block": [
    { "path": "selectedPageUrl", "kind": "page-url" },
    { "path": "selectedSlug", "kind": "page" }
  ],
  "itmar/pickup-posts": [
    { "path": "selectedSlug", "kind": "post-type" },
    { "path": "selectedRest", "kind": "post-type-rest" },
    { "path": "dispTaxonomies", "kind": "taxonomy" },
    { "path": "choiceTerms", "kind": "term" },
    { "path": "choiceFields", "kind": "field", "skip": ["title", "date", "content", "excerpt", "featured_media", "link"] },
    { "path": "searchFields", "kind": "field" },
    { "path": "blockMap", "kind": "field-keys" },
    { "path": "posts", "kind": "content-data" },
    { "path": "blocksAttributesArray", "kind": "embedded-blocks" },
    { "path": "emptyMessageAttributes", "kind": "embedded-attributes", "block": "itmar/design-title" }
  ],
  "itmar/product-block":  [ { "path": "storeUrl", "kind": "service-setting" }, { "path": "callbackUrl", "kind": "service-setting" },
                            { "path": "blocksAttributesArray", "kind": "embedded-blocks" } ],
  "itmar/cart-block":     [ { "path": "blocksAttributesArray", "kind": "embedded-blocks" } ],
  "itmar/post-pagenation":[ { "path": "forwardBlockAttributes", "kind": "embedded-attributes", "block": "itmar/design-button" },
                            { "path": "backBlockAttributes", "kind": "embedded-attributes", "block": "itmar/design-button" },
                            { "path": "dummyBlockAttributes", "kind": "embedded-attributes", "block": "itmar/design-button" },
                            { "path": "numBlockAttributes", "kind": "embedded-attributes", "block": "itmar/design-button" },
                            { "path": "forwardTitleAttributes", "kind": "embedded-attributes", "block": "itmar/design-title" },
                            { "path": "backTitleAttributes", "kind": "embedded-attributes", "block": "itmar/design-title" } ],
  "itmar/post-filter":    [ { "path": "titleAttributes", "kind": "embedded-attributes", "block": "itmar/design-title" },
                            { "path": "searchButtonAttributes", "kind": "embedded-attributes", "block": "itmar/design-button" },
                            { "path": "dayAttributes", "kind": "embedded-blocks" },
                            { "path": "searchFields", "kind": "field" } ],
  "itmar/post-crumbs":    [ { "path": "crumbBlockAttributes", "kind": "embedded-attributes", "block": "itmar/design-title" },
                            { "path": "postTypeName", "kind": "post-type-label" } ],
  "itmar/markdown-block": [ { "path": "element_style_obj.*", "kind": "embedded-attributes", "block": "itmar/design-title" } ],
  "itmar/reservation-block": [
    { "path": "resourceId", "kind": "content-id" },
    { "path": "resourceSlug", "kind": "content-ref" },
    { "path": "closedWeekdays", "kind": "content-data" },
    { "path": "selectedSlug", "kind": "post-type" },
    { "path": "selectedRest", "kind": "post-type-rest" }
  ],
  "itmar/masonry-mv": [ { "path": "default_val.media", "kind": "media" }, { "path": "mobile_val.media", "kind": "media" } ],
  "itmar/fade-mv":    [ { "path": "default_val.media", "kind": "media" }, { "path": "mobile_val.media", "kind": "media" } ],
  "core/navigation":  [ { "path": "ref", "kind": "navigation" } ],
  "core/image":       [ { "path": "url", "kind": "media" }, { "path": "href", "kind": "page-url", "when": { "linkDestination": "custom" } } ]
}
```

`kind` の意味: `page`／`page-url`（内部なら外す）、`navigation`（外す）、`media`（空にする）、`content-data`／`content-id`／`content-ref`（空・既定値に戻す）、
`service-setting`（空にする）、`post-type`／`post-type-rest`／`post-type-label`／`taxonomy`／`term`／`field`／`field-keys`（紐づけの対象）、
`embedded-blocks`／`embedded-attributes`（再帰して、埋め込まれたブロックの規則を適用する）。

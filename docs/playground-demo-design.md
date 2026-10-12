# Playground デモの設計（案）

作成: 2026-10-05 / 状態: **キットを作り、通常の WordPress で動作を確認した**（`Local Sites/itmaroon-demo/`。README 参照）。決定: 題材は sazanka、見せるページは 5 つ（トップ、作品、コンセプト、予約、お問い合わせ）。**Playground の中での動作と、公式の版のプラグインでの見た目は、未確認**（プラグインのリリースが先）。

## 1. 目的

itmar のブロックでできあがった**サイトを、インストールする前に、ブラウザーで見せる**。リンクを1つ開くと、WordPress Playground（ブラウザーの中で動く WordPress）が立ち上がり、プラグイン、テーマ、見本の中身がそろった状態で、サイトが開く。

- 成功の基準: ①リンクを開いて 1 分以内に、トップページが表示される。②作品一覧、予約など、見せたいページが開く。③各プラグインのページ（公式ディレクトリ）から、そこへ行ける。
- ねらい: プラグインのダウンロード数を伸ばす。ZIP のテーマ自体は、ディレクトリに載らない。デモが、プラグインのページへ誘導する入り口になる。

## 2. 見せるもの（案）

デモの題材は、**sazanka（陶芸工房のサイト）**を、Distribution Export で書き出した ZIP とする（要確認: §9）。

| ページ | 見せるブロック | デモでの扱い |
|---|---|---|
| トップ | block-collections、slide-blocks（フェード） | 動く |
| 作品一覧／作品詳細 | query-blocks（Pickup Posts、Filter、ページ送り） | 動く（見本の作品の投稿が要る） |
| コンセプト | block-collections | 動く |
| 教室予約 | itmaroon-booking-block | 動くが、見本の枠（予約できる日）を入れる必要がある |
| お問い合わせ | form-send-blocks | フォームは出る。メールは送れない（Playground は外へ送信できない） |
| ショップ | itmaroon-ec-relate-blocks | **外部のストア（Shopify）が必要**。デモでは動かせない。見た目だけにするか、外す |
| 会員登録／ログイン | form-send-blocks、coustom-login | 動作の説明が要る。外すのが無難 |

最初は、**トップ、作品、コンセプト、予約、お問い合わせ**の5つに絞る。

## 3. 仕組み（Blueprint）

Playground は、**Blueprint（JSON）**に書いた手順で、起動のたびに、環境を作る。

```
landingPage: "/"
preferredVersions: WordPress と PHP（最新）
steps:
  1. installPlugin   block-collections（wordpress.org の slug。activate）
  2. installPlugin   query-blocks／slide-blocks／form-send-blocks／itmaroon-booking-block …
  3. installPlugin   secure-custom-fields（カスタムフィールドを使う場合）
  4. writeFile       mu-plugin: 投稿タイプ（works、concept …）とタクソノミー（creator、kind）を登録
  5. installTheme    テーマの ZIP（URL。activate）
  6. importWxr       見本の中身（固定ページ、投稿、画像）
  7. setSiteOptions  サイト名、パーマリンク、フロントページ
  8. runPHP          見本の予約枠の登録 など
  9. login           管理画面を見せる場合
```

- 手順4が必要な理由: 投稿タイプは、テーマに入れられない（ZIP に入らない）。sazanka では、SCF の設定としてデータの中にあるので、デモ用に、同じ名前で登録し直す。
- 同期パターンは、**中身（WXR）に含める**（wp_block の投稿）。デモに、Theme Migration は要らない。
- 紐づけは不要にする（デモ用のサイトは、投稿タイプの名前を、元のサイトと同じにするため）。

## 4. 見本の中身

| 種類 | 方針 |
|---|---|
| 固定ページ | sazanka の実ページのうち、見せる5つ。メールアドレス、内部リンクは、書き出しの除去で外れる。デモ用に、リンクを設定し直す（メニューも） |
| 作品（works） | 数点（6〜10）。タイトルと抜粋は、デモ用に書き直す |
| 画像 | **権利が明確なものだけ**。ユーザー自身の作品写真（再配布を許す）か、CC0 の画像。縮小して容量を抑える（1枚 100〜200KB） |
| 予約の枠 | 今月から先の数週間分。起動の時刻を基準に、runPHP で毎回、作る（古くならない） |

ZIP の方針（メディアを運ばない）と、デモの方針（見本の画像を入れる）は、別。見本の画像は、デモ用の中身として、別に用意する。

## 5. 置き場所

デモの部品は、1つの公開リポジトリ（GitHub）にまとめる。

```
itmaroon-demo/
  blueprint.json
  theme/sazanka-demo.zip      ← Distribution Export の出力（デモ用に、必要最小限に）
  content/demo.xml            ← 見本の中身（WXR）
  content/images/*.jpg
  mu-plugin/demo-setup.php    ← 投稿タイプの登録、予約枠の登録
```

- Playground が ZIP や画像を取りに行く先は、**CORS が許可されている URL**でなければならない。GitHub の raw や jsDelivr は、通常、使える（未確認: 実際に試す）。リリースの添付ファイルの URL は、使えないことが多い（未確認）。
- ZIP は、サイズを抑える。今の sazanka のパーツは大きい（`works-archive` 約 900KB、`shop-main` 約 900KB）。ZIP の全体が、数MB になる。デモの起動が遅くなるので、ショップを外すと、軽くなる。

## 6. 入り口

1. **公式ディレクトリの Live Preview**: 各プラグインの SVN の `assets/blueprints/blueprint.json` に置くと、プラグインのページに、デモのボタンが出る（未確認: 配置とルール）。どのプラグインのページから開いても、同じデモに着く。
2. **直接のリンク**: `https://playground.wordpress.net/?blueprint-url=…`。ブログ、SNS、動画の説明欄に貼る。

## 7. 制約と、確認が要る点

| 点 | 内容 |
|---|---|
| 公開されているか | `installPlugin` は、wordpress.org にあるものだけ。block-collections ほかが、公開済みの版か（デモに要る機能が入っているか）を確認する。ITmaroon Site Patterns と、予約のパターンは、未公開 |
| 保存されない | Playground は、閉じると消える。デモとしては、問題ない |
| 外部との通信 | メール送信、Shopify などの外部 API は使えない。フォームの送信は、成功の表示だけ見せる |
| 速度 | 起動に数十秒かかる。大きなパーツを読み込む |
| WordPress の版 | 今の環境は 7.1。Playground の対応版と合わせる（未確認） |
| 音・動画 | 外部の動画は、CORS と著作権に注意 |

## 8. 作業の分担（案）

| 作業 | 担当 |
|---|---|
| デモ用の ZIP を書き出す（除去、依存の確認） | Claude |
| blueprint.json と、mu-plugin（投稿タイプ、予約枠）を書く | Claude |
| 見本の中身（WXR）を作る。固定ページは sazanka から、作品はデモ用に | Claude（本文）／ユーザー（写真、文章の承認） |
| ローカルで Playground を動かして確認する | Claude（可能な範囲。ブラウザーの内蔵ツール） |
| 公開リポジトリを作る。プラグインのリリース、SVN の blueprint | ユーザー |

## 9. 決めること

1. 題材は、sazanka でよいか（それとも、デモ専用の新しいサイトか）。
2. 見せるページは、§2 の5つでよいか。ショップは外すか。
3. 画像は、ユーザーの写真を使えるか。
4. デモが使うプラグインのうち、公開済みで、必要な機能が入っているものはどれか。

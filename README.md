# TRDB_Sarcher

トランジスタ技術の収録内容データベース（`TR.txt`）を検索するための簡易 Web アプリです。
フロントエンドは Vue、バックエンドは Slim (PHP) の軽量 API で構成されています。

## 構成

- `public/index.html` / `public/app.js` / `public/styles.css`: フロントエンド
- `public/api/index.php`: 検索 API (`/api/search`)
- `public/router.php`: PHP 内蔵サーバのルーター
- `TR.txt`: CSV 形式の収録内容データ

## 必要要件

- PHP 8.1 以上
- Composer

## セットアップ

```bash
composer install
```

## 起動方法

```bash
php -S localhost:8000 -t public public/router.php
```

ブラウザで `http://localhost:8000` を開いてください。

## API 仕様

### GET /api/search

**クエリパラメータ**

- `title`: タイトル検索文字列
- `title_mode`: `keyword` または `regex`
- `author`: 著者検索文字列
- `author_mode`: `keyword` または `regex`
- `from_year`: 開始年 (例: 1998)
- `from_month`: 開始月 (1-12)
- `to_year`: 終了年
- `to_month`: 終了月
- `limit`: 返却件数 (1-1000, 既定 200)
- `offset`: 取得開始位置

**検索文法 (keyword モード)**

- 空白: AND
- `&`: AND
- `|`: OR
- `!`: NOT
- `(` `)`: 優先順位

例:

```
マイコン & ARM | FPGA
山田 | 佐藤 !高橋
```

**レスポンス例**

```json
{
  "meta": {
    "total": 120,
    "returned": 200,
    "offset": 0,
    "limit": 200
  },
  "items": [
    {
      "year": 1998,
      "month": 4,
      "title": "...",
      "subtitle": "...",
      "type": "...",
      "start_page": "...",
      "page_count": "...",
      "author": "..."
    }
  ]
}
```

## TR.txt について

- `TR.txt` は CP932 エンコードの CSV を想定しています。
- API 側で UTF-8 に変換して読み込みます。

## 注意点

- `TR.txt` が存在しない場合、`/api/search` は 500 エラーを返します。
- 検索結果は `limit` で最大 1000 件まで返却します。

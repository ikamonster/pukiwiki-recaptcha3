# PukiWiki用プラグイン<br>スパム対策 recaptcha3.inc.php

Google [reCAPTCHA v3](https://www.google.com/recaptcha/) によりスパムを防ぐ[PukiWiki](https://pukiwiki.osdn.jp/)用プラグイン。  

ページ編集・コメント投稿・ファイル添付など、PukiWiki 標準の編集機能をスパムから守ります。  
reCAPTCHA v3 は不審な送信者を自動判定する不可視の防壁です。煩わしい入力を要求せず、ウィキの使用感に影響しません。

追加ファイルはこのプラグインコードだけ。  
PukiWiki 本体の変更も最小限にし、なるべく簡単に導入できるようにしています。  
ただし、JavaScript を活用する高度な編集系サードパーティ製プラグインとは相性が悪いかもしれません。  
PukiWiki をほぼ素のままで運用し、手軽にスパム対策したいかた向けです。

禁止語句によるスパム判定機能もあります。  
reCAPTCHA を使わず、禁止語句判定のみ用いることも可能です。

<br>

|対象PukiWikiバージョン|対象PHPバージョン|
|:---:|:---:|
|PukiWiki 1.5.3 ~ 1.5.4 (UTF-8)|PHP 7.4 ~ 8.1|

<br>

## インストール

以下の手順に沿って PukiWiki に導入してください。

1. [GitHubページ](https://github.com/ikamonster/pukiwiki-recaptcha3) からダウンロードした recaptcha3.inc.php を PukiWiki の plugin ディレクトリに配置する。
2. Google reCAPTCHA サイトで対象PukiWikiサイトのドメインを「**reCAPTCHA v3**」タイプで登録し、取得したサイトキーとシークレットキーとを本プラグイン内の定数 PLUGIN_RECAPTCHA3_SITE_KEY, PLUGIN_RECAPTCHA3_SECRET_KEY にそれぞれ設定する。
3. PukiWikiスキンファイルのほぼ末尾、\</body> タグの直前に次のコードを挿入する。  
```PHP
<?php if (exist_plugin_convert('recaptcha3')) echo do_plugin_convert('recaptcha3'); // reCAPTCHA v3 plugin ?>
```
4. PukiWikiライブラリファイル lib/plugin.php の「function do_plugin_action($name)」関数内、「```$retvar = call_user_func('plugin_' . $name . '_action');```」行の直前に次のコードを挿入する。  
```PHP
if (exist_plugin_action('recaptcha3') && ($__v = call_user_func_array('plugin_recaptcha3_action', array($name))['body'])) die_message($__v); // reCAPTCHA v3 plugin
```

<br>

## 詳細

### 設定

ソース内の下記の定数で動作を制御することができます。

|定数名|値|既定値|意味|
|:---|:---:|:---:|:---|
|PLUGIN_RECAPTCHA3_SITE_KEY|文字列||Google reCAPTCHA v3 サイトキー。空の場合、reCAPTCHA判定は実施されない|
|PLUGIN_RECAPTCHA3_SECRET_KEY|文字列||Google reCAPTCHA v3 シークレットキー。空の場合、reCAPTCHA判定は実施されない|
|PLUGIN_RECAPTCHA3_SCORE_THRESHOLD|0.0～1.0|0.5|スコア閾値（0.0～1.0）。reCAPTCHAによる判定スコアがこの値より低い送信者は拒否される|
|PLUGIN_RECAPTCHA3_HIDE_BADGE|0 or 1|1|reCAPTCHAバッジを非表示にし、代替文言を出力する。Googleの規約によりバッジか文言どちらかの表示が必須|
|PLUGIN_RECAPTCHA3_API_TIMEOUT|任意の数値|0|reCAPTCHA APIタイムアウト時間（秒）。0なら無指定|
|PLUGIN_RECAPTCHA3_CENSORSHIP|文字列||投稿禁止語句を表す正規表現|
|PLUGIN_RECAPTCHA3_CHECK_REFERER|0 or 1|0|1ならリファラーを参照し自サイト以外からの要求を拒否。信頼性が低いため非推奨|
|PLUGIN_RECAPTCHA3_ERR_STATUS|HTTPステータスコード|403|拒否時に返すHTTPステータスコード|
|PLUGIN_RECAPTCHA3_DISABLED|0 or 1|0|1なら本プラグインを無効化（メンテナンス用）|

<br>

### 動作確認

本プラグインが正しく導入されていれば、ページ末尾に「This site is protected by reCAPTCHA and the Google Privacy Policy and Terms of Service apply.」との文言（定数設定によってはreCAPTCHAバッジ）が表示されます。  
この状態でページ編集やコメント投稿ができていれば正常です。

拒否される場合を確認したければ、シークレットキーの値をわざと不正にしてみてください。  
その状態でページ編集などを試みるとエラーになるはずです（デフォルト設定の場合）。  
スパムに対する正しいテストケースではありませんが、少なくともプラグインが動作し reCAPTCHA API と連絡していることは確かめられます。  
実際のスパム攻撃については、Google reCAPTCHA サイトの管理画面に統計が表示されます。スコア閾値調整の参考にもなります。

なお、本プラグインが正しく導入されていても、レガシーブラウザーでは常に編集に失敗するかもしれませんが、仕様（非対応）としてご了承ください。

<br>

### スパム拒否の仕組み

* ブラウザー側において、JavaScriptによってページ内のすべてのform要素を探しだし、submitボタンがクリックされたらreCAPTCHAトークンを取得して送信パラメーターに含めるよう細工する  
→ 不審者はトークンを得られずに弾かれる  
→ 副作用として、この細工がサードパーティ製プラグインの動作を邪魔する可能性がある
* サーバー側において、受信したリクエストがPOSTメソッドかつ既知のプラグイン呼び出しなら次の判定を行う
  * パラメーターにreCAPTCHAトークンが含まれなければ、不正アクセスとみなしてリクエストを拒否する  
→ フォームを経ず直接プラグインURLにアクセスしてくるロボットは弾かれる
  * reCAPTCHA APIにトークンを送信し、応答スコアが閾値未満ならリクエストを拒否する  
→ 不審な送信元IPアドレスや偽造トークンは弾かれる  
→ もし効果が薄い、または効き過ぎるといった問題があれば閾値 PLUGIN_RECAPTCHA3_SCORE_THRESHOLD 定数を調整すること  
  * 投稿禁止語句が設定されており、かつテキスト投稿を伴うプラグインであればその内容を判定  
→ 特定の宣伝文句などを含むスパムを弾くことができる。URLを禁止するのが最も広範で効果的だが、不便にもなるので注意

<br>

### 高度な設定：対象プラグインの追加

本プラグインはデフォルトで、PukiWikiに標準添付の編集系プラグインのみをスパム判定の対象としています。  
具体的には次の通り。

``article, attach, bugtrack, comment, edit, freeze, insert, loginform, memo, pcomment, rename, template, tracker, unfreeze, vote``

スパムボットは標準プラグインを標的にすると考えられるため、一般的にはこれで十分なはずです。  
しかし、もし特定のサードパーティ製プラグインを標的として攻撃されていたら、コード内の $targetPlugins 配列にそのプラグイン名を他行に倣って追加してください。  
ただし上述した通り、プラグインの編集・投稿機能が POST メソッドの form 要素かつ submit ボタンで送信する仕組みになっていないと効果がなく、処理内容による相性にも左右されます。

<br>

### ご注意

* 標準プラグイン以外の動作確認はしていません。サードパーティ製プラグインによっては機能が妨げられる場合があります。
* JavaScriptが有効でないと動作しません。
* サーバーから reCAPTCHA API へのアクセスに cURL を使用します。
* 閲覧専用（PKWK_READONLY が 1）のウィキにおいては本プラグインは何もしません。

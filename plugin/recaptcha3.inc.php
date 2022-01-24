<?php
/**
PukiWiki - Yet another WikiWikiWeb clone.
recaptcha3.inc.php, v1.1 2020 M.Taniguchi
License: GPL v3 or (at your option) any later version

Google reCAPTCHA v3 によるスパム対策プラグイン。

ページ編集・コメント投稿・ファイル添付など、PukiWiki標準の編集機能をスパムから守ります。
reCAPTCHA v3 は不審な送信者を学習により自動判定する不可視の防壁です。煩わしい文字入力をユーザーに要求せず、ウィキの使用感に影響しません。

追加ファイルはこのプラグインだけ。PukiWiki本体の変更も最小限にし、なるべく簡単に導入できるようにしています。
が、そのための副作用として、JavaScriptを活用する高度な編集系サードパーティ製プラグインとは相性が悪いかもしれません。
PukiWikiをほぼ素のままで運用し、手軽にスパム対策したいかた向けです。

バージョン1.10より、禁止語句によるスパム判定を追加しました。reCAPTCHAを使わず、禁止語句判定のみ用いることも可能です。

【導入手順】
以下の手順に沿ってシステムに導入してください。

1) Google reCAPTCHA サイトでウィキのドメインを「reCAPTCHA v3」タイプで登録し、取得したサイトキー・シークレットキーをこのプラグインの定数 PLUGIN_RECAPTCHA3_SITE_KEY, PLUGIN_RECAPTCHA3_SECRET_KEY に設定する。

2) ファイル skin/pukiwiki.skin.php のほぼ末尾、「</body>」（275行目あたり）の直前に次のコードを挿入する。
   <?php if (exist_plugin_convert('recaptcha3')) echo do_plugin_convert('recaptcha3'); // reCAPTCHA v3 plugin ?>

3) ファイル lib/plugin.php の「function do_plugin_action($name)」関数内、「$retvar = call_user_func('plugin_' . $name . '_action');」の直前（92行目あたり）に次のコードを挿入する。
   if (exist_plugin_action('recaptcha3') && ($__v = call_user_func_array('plugin_recaptcha3_action', array($name))['body'])) die_message($__v); // reCAPTCHA v3 plugin

【ご注意】
・PukiWiki 1.5.3／PHP 7.4／UTF-8／主要モダンブラウザーで動作確認済み。旧バージョンでも動くかもしれませんが非推奨です。
・標準プラグイン以外の動作確認はしていません。サードパーティ製プラグインによっては機能が妨げられる場合があります。
・JavaScriptが有効でないと動作しません。
・サーバーからreCAPTCHA APIへのアクセスにcURLを使用します。
・reCAPTCHA v3 について詳しくはGoogleのreCAPTCHAサイトをご覧ください。https: //www.google.com/recaptcha/
*/

/////////////////////////////////////////////////
// スパム対策プラグイン設定（recaptcha3.inc.php）
if (!defined('PLUGIN_RECAPTCHA3_SITE_KEY'))      define('PLUGIN_RECAPTCHA3_SITE_KEY',       '');   // Google reCAPTCHA v3 サイトキー。空の場合、reCAPTCHA判定は実施されない
if (!defined('PLUGIN_RECAPTCHA3_SECRET_KEY'))    define('PLUGIN_RECAPTCHA3_SECRET_KEY',     '');   // Google reCAPTCHA v3 シークレットキー。空の場合、reCAPTCHA判定は実施されない
if (!defined('PLUGIN_RECAPTCHA3_SITE_KEY'))      define('PLUGIN_RECAPTCHA3_SCORE_THRESHOLD', 0.5); // スコア閾値（0.0～1.0）。reCAPTCHAによる判定スコアがこの値より低い送信者はスパマーとみなして要求を拒否する。なお、直接プラグインURLを叩く種類のロボットはスコアによらず必ず拒否される
if (!defined('PLUGIN_RECAPTCHA3_HIDE_BADGE'))    define('PLUGIN_RECAPTCHA3_HIDE_BADGE',      1);   // reCAPTCHAバッジを非表示にし、代替文言を出力する。Googleの規約によりバッジか文言どちらかの表示が必須
if (!defined('PLUGIN_RECAPTCHA3_API_TIMEOUT'))   define('PLUGIN_RECAPTCHA3_API_TIMEOUT',     0);   // reCAPTCHA APIタイムアウト時間（秒）。0なら無指定
if (!defined('PLUGIN_RECAPTCHA3_CENSORSHIP'))    define('PLUGIN_RECAPTCHA3_CENSORSHIP',     '');   // 投稿禁止語句を表す正規表現（例：'/((https?|ftp)\:\/\/[\w!?\/\+\-_~=;\.,*&@#$%\(\)\'\[\]]+|宣伝文句)/ui'）
if (!defined('PLUGIN_RECAPTCHA3_CHECK_REFERER')) define('PLUGIN_RECAPTCHA3_CHECK_REFERER',   0);   // 1ならリファラーを参照し自サイト以外からの要求を拒否。リファラーは未送や偽装があり得るため頼るべきではないが、一時的な防御には使える局面があるかもしれない
if (!defined('PLUGIN_RECAPTCHA3_ERR_STATUS'))    define('PLUGIN_RECAPTCHA3_ERR_STATUS',      403); // 拒否時に返すHTTPステータスコード
if (!defined('PLUGIN_RECAPTCHA3_DISABLED'))      define('PLUGIN_RECAPTCHA3_DISABLED',        0);   // 1なら本プラグインを無効化


// プラグイン出力
function plugin_recaptcha3_convert() {
	// 本プラグインが無効か書き込み禁止なら何もしない
	if (PLUGIN_RECAPTCHA3_DISABLED || ((!PLUGIN_RECAPTCHA3_SITE_KEY || !PLUGIN_RECAPTCHA3_SECRET_KEY) && !PLUGIN_RECAPTCHA3_CENSORSHIP) || PKWK_READONLY || !PKWK_ALLOW_JAVASCRIPT) return '';

	// 二重起動禁止
	static	$included = false;
	if ($included) return '';
	$included = true;

	$enabled = (PLUGIN_RECAPTCHA3_SITE_KEY && PLUGIN_RECAPTCHA3_SECRET_KEY);	// reCAPTCHA有効フラグ

	// reCAPTCHAバッジ非表示なら代替文言設定
	$protocol = 'https:';
	$badge = (!PLUGIN_RECAPTCHA3_HIDE_BADGE || !$enabled)? '' : '<style>.grecaptcha-badge{visibility:hidden;max-height:0;max-width:0;margin:0;padding:0;border:none} #_p_recaptcha3_terms{font-size:7px}</style><div id="_p_recaptcha3_terms">This site is protected by reCAPTCHA and the Google <a href="' . $protocol . '//policies.google.com/privacy" rel="noopener nofollow external">Privacy Policy</a> and <a href="' . $protocol . '//policies.google.com/terms" rel="noopener nofollow external">Terms of Service</a> apply.</div>';

	// JavaScript
	$siteKey = PLUGIN_RECAPTCHA3_SITE_KEY;
	$libUrl = '//www.google.com/recaptcha/api.js?render=' . $siteKey;
	$enabled = ($enabled)? 'true' : 'false';
	$js = <<<EOT
<script>
'use strict';

window.addEventListener('DOMContentLoaded', function(){
	new __PluginRecaptcha3__();
});

var	__PluginRecaptcha3__ = function() {
	const	self = this;
	this.timer = null;
	this.libLoaded = false;

	// 設定
	this.update();

	// DOMを監視し、もしページ内容が動的に変更されたら再設定する（モダンブラウザーのみ対応）
	const observer = new MutationObserver(function(mutations){ mutations.forEach(function(mutation){ if (mutation.type == 'childList') self.update(); }); });
	if (observer) {
		const target = document.getElementsByTagName('body')[0];
		if (target) observer.observe(target, { childList: true, subtree: true });
	}
};

// reCAPTCHAライブラリーロード
__PluginRecaptcha3__.prototype.loadLib = function() {
	if (!this.libLoaded) {
		this.libLoaded = true;
		var scriptElement = document.createElement('script');
		scriptElement.src = '${libUrl}';
		scriptElement.setAttribute('defer', 'defer');
		document.body.appendChild(scriptElement);
	}
};

// 設定
__PluginRecaptcha3__.prototype.setup = function() {
	const	self = this;

	// 全form要素を走査
	var	elements = document.getElementsByTagName('form');
	for (var i = elements.length - 1; i >= 0; --i) {
		var	form = elements[i];

		// form内全submitボタンを走査しクリックイベントを設定
		var eles = form.querySelectorAll('input[type="submit"]');
		if (eles.length > 0) {
			for (var j = eles.length - 1; j >= 0; --j) eles[j].addEventListener('click', self.submit, false);

			// こちらのタイミングで送信するため、既定の送信イベントを止めておく
			form.addEventListener('submit', self.stopSubmit, false);

			// reCAPTCHAライブラリーロード
			self.loadLib();
		}
	}
};

// 再設定
__PluginRecaptcha3__.prototype.update = function() {
	const	self = this;
	if (this.timer) clearTimeout(this.timer);
	this.timer = setTimeout(function() { self.setup(); self.timer = null; }, 50);
};

// 送信防止
__PluginRecaptcha3__.prototype.stopSubmit = function(e) {
	e.preventDefault();
	e.stopPropagation();
	return false;
};

// クリック時送信処理
__PluginRecaptcha3__.prototype.submit = function(e) {
	var	form;
	if (this.closest) {
		form = this.closest('form');
	} else {
		for (form = this.parentNode; form; form = form.parentNode) if (form.nodeName.toLowerCase() == 'form') break;	// 旧ブラウザー対策
	}

	// クリックされたsubmitボタンのname,value属性をhiddenにコピー（submitボタンが複数ある場合への対処）
	if (form)  {
		var nameEle = form.querySelector('.__plugin_recaptcha3_submit__');
		var	name = this.getAttribute('name');
		if (name) {
			var	value = this.getAttribute('value');
			if (!nameEle) {
				form.insertAdjacentHTML('beforeend', '<input type="hidden" class="__plugin_recaptcha3_submit__" name="' + name + '" value="' + value + '"/>');
			} else {
				nameEle.setAttribute('name', name);
				nameEle.setAttribute('value', value);
			}
		} else
		if (nameEle) {
			if (nameEle.remove) nameEle.remove();
			else nameEle.parentNode.removeChild(nameEle);
		}

		if (${enabled}) {
			// reCAPTCHAトークン取得
			grecaptcha.ready(function() {
				try {
					grecaptcha.execute('${siteKey}').then(function(token) {
						// 送信パラメーターにトークンを追加
						var ele = form.querySelector('input[name="__plugin_recaptcha3__"]');
						if (!ele) {
							form.insertAdjacentHTML('beforeend', '<input type="hidden" name="__plugin_recaptcha3__" value="' + token + '"/>');
						} else {
							ele.setAttribute('value', token);
						}
						// フォーム送信
						form.submit();
					});
				} catch(e) {}
			});
		} else {
			// reCAPTCHA無効なら即フォーム送信
			form.submit();
		}
	}
	return false;
};
</script>
EOT;

	return $badge . $js;
}


// 受信リクエスト確認
function plugin_recaptcha3_action() {
	$result = '';	// 送信者判定結果（許可：空, 拒否：エラーメッセージ）

	// 機能有効かつPOSTメソッド？
	if (!PLUGIN_RECAPTCHA3_DISABLED && ((PLUGIN_RECAPTCHA3_SITE_KEY && PLUGIN_RECAPTCHA3_SECRET_KEY) || PLUGIN_RECAPTCHA3_CENSORSHIP) && !PKWK_READONLY && $_SERVER['REQUEST_METHOD'] == 'POST') {
		/* 【対象プラグイン設定テーブル】
		   reCAPTCHA判定の対象とするプラグインを列挙する配列。
		   name   … プラグイン名
		   censor … 検閲対象パラメーター名
		   vars   … 併送パラメーター名
		*/
		$targetPlugins = array(
			array('name' => 'article',  'censor' => 'msg'),
			array('name' => 'attach'),
			array('name' => 'bugtrack', 'censor' => 'body'),
			array('name' => 'comment',  'censor' => 'msg'),
			array('name' => 'edit',     'censor' => 'msg', 'vars' => 'write'),	// editプラグインはwriteパラメーター併送（ページ更新）時のみ対象
			array('name' => 'freeze'),
			array('name' => 'insert',   'censor' => 'msg'),
			array('name' => 'loginform'),
			array('name' => 'memo',     'censor' => 'msg'),
			array('name' => 'pcomment', 'censor' => 'msg'),
			array('name' => 'rename'),
			array('name' => 'template'),
			array('name' => 'tracker',  'censor' => 'Messages'),
			array('name' => 'unfreeze'),
			array('name' => 'vote'),
		);

		global	$vars;
		list($name) = func_get_args();
		$enabled = (PLUGIN_RECAPTCHA3_SITE_KEY && PLUGIN_RECAPTCHA3_SECRET_KEY);	// reCAPTCHA有効フラグ

		foreach ($targetPlugins as $target) {
			if ($target['name'] != $name) continue;	// プラグイン名一致？
			if (!isset($target['vars']) || isset($vars[$target['vars']])) {	// クエリーパラメーター未指定、または指定名が含まれる？
				if ($enabled && (!isset($vars['__plugin_recaptcha3__']) || $vars['__plugin_recaptcha3__'] == '')) {	// reCAPTCHAトークンあり？
					// トークンのない不正要求なら送信者を拒否
					$result = 'Rejected by Google reCAPTCHA v3';
				} else
				if (PLUGIN_RECAPTCHA3_CHECK_REFERER && strpos($_SERVER['HTTP_REFERER'], get_script_uri()) === false) {
					// 自サイト以外からのアクセスを拒否
					$result = 'Deny access';
				} else {
					// 検閲対象パラメーターあり？
					if (PLUGIN_RECAPTCHA3_CENSORSHIP && isset($target['censor']) && isset($vars[$target['censor']])) {
						// 投稿禁止語句が含まれていたら受信拒否
						if (preg_match(PLUGIN_RECAPTCHA3_CENSORSHIP, $vars[$target['censor']])) {
							$result = 'Forbidden word detected';
							break;
						}
					}

					// reCAPTCHA API呼び出し
					if ($enabled) {
						$ch = curl_init('https:'.'//www.google.com/recaptcha/api/siteverify');
						curl_setopt($ch, CURLOPT_POST, true);
						curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('secret' => PLUGIN_RECAPTCHA3_SECRET_KEY, 'response' => $vars['__plugin_recaptcha3__'])));
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
						if (PLUGIN_RECAPTCHA3_API_TIMEOUT > 0) curl_setopt($ch, CURLOPT_TIMEOUT, PLUGIN_RECAPTCHA3_API_TIMEOUT);
						$data = json_decode(curl_exec($ch));
						curl_close($ch);

						// スコアが閾値未満なら送信者を拒否
						if (!$data->success || $data->score < PLUGIN_RECAPTCHA3_SCORE_THRESHOLD) $result = 'Rejected by Google reCAPTCHA v3';
					}
				}
				break;
			}
		}

		// エラー用のHTTPステータスコードを設定
		if ($result && PLUGIN_RECAPTCHA3_ERR_STATUS) http_response_code(PLUGIN_RECAPTCHA3_ERR_STATUS);
	}

	return array('msg' => 'recaptcha3', 'body' => $result);
}

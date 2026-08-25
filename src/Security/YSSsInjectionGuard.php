<?php
/**
 * 注入/攻擊探測辨識（單一真相源）。
 *
 * 判斷一個搜尋字串是否為攻擊探測（SSTI 模板注入、XSS、路徑穿越、SQLi、SSRF/RCE、
 * 控制字元），而非真實的商品搜尋。用於：
 *   ① 進站攔截（YSSsQueryRepository::log 唯一寫入瓶頸 → 不記錄；YSSsPublicController::query
 *      → 拒絕執行搜尋）。
 *   ② 建議清單過濾（auto_terms / suggest 縱深防禦）。
 *   ③ 後台清理（purge_injection 掃既有紀錄）。
 *
 * 設計原則：只針對「真實商品搜尋永遠不會出現」的結構字元與高訊號 token，避免誤殺
 * 中文（CJK）、英數、空白、連字號等正常商品詞（如「外套」「nova」「3C電子」「tee」）。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Security;

defined( 'ABSPATH' ) || exit;

final class YSSsInjectionGuard {

	/**
	 * 是否為攻擊探測（true = 阻斷/不記錄/不建議/應清理）。
	 */
	public static function is_attack( string $q ): bool {
		if ( '' === $q ) {
			return false;
		}

		// ① 控制字元（正常搜尋不含；normalize 已壓一般空白）。
		if ( preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $q ) ) {
			return true;
		}

		// ② 結構字元：角括號 / 大括號 / 反引號 / 反斜線——真實商品搜尋永遠不會出現。
		if ( preg_match( '/[<>{}`\\\\]/', $q ) ) {
			return true;
		}

		// ③ 危險序列：模板表達式、HTML entity、URL scheme、路徑穿越、超長點序列。
		//    以 ~ 為分隔（樣式含字面 # 如 #{ / #set(，不可用 # 當分隔）。
		if ( preg_match( '~\$\{|#\{|#set\(|<%|%>|&#|&lt;|&gt;|://|\.\.[\\\\/]|\.{4,}~i', $q ) ) {
			return true;
		}

		// ④ 高訊號攻擊 token（SSTI / XSS 事件處理 / SQLi 含經典恆真式 / LFI / SSRF·RCE）。
		//    以 # 為分隔避免與路徑 / 衝突；只收不會誤殺商品名的字樣。
		if ( preg_match(
			'#\b(?:union\s+select|insert\s+into|drop\s+table|information_schema)\b'
			. '|[\'"]\s*(?:or|and)\s+[\'"]?[0-9]|[\'"]\s*=\s*[\'"]'
			. '|__globals__|__import__|lipsum|freemarker|popen|subprocess|nslookup|net::|use\s+net'
			. '|system\.ini|win\.ini|/etc/passwd|/proc/'
			. '|onerror\s*=|onload\s*=|onfocus\s*=|onmouseover\s*=|javascript:|fetch\s*\(#i',
			$q
		) ) {
			return true;
		}

		return false;
	}
}

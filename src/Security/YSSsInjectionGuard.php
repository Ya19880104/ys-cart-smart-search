<?php
/**
 * 高訊號搜尋濫用／攻擊探測分類器。
 *
 * 這是分析污染與非預期搜尋執行的控制，不是 SQL/XSS 的主要安全邊界。商品 SQL 仍須 prepared，
 * 所有輸出仍須依 context 跳脫。判準刻意只攔具結構語意的高訊號模式，允許網址、技術書名、
 * 大括號、角括號、反斜線與一般程式語彙出現在真實商品搜尋中。
 *
 * @package YangSheep\SmartSearch
 */

namespace YangSheep\SmartSearch\Security;

defined( 'ABSPATH' ) || exit;

final class YSSsInjectionGuard {

	/**
	 * 是否為高訊號攻擊／濫用探測。
	 */
	public static function is_attack( string $q ): bool {
		if ( '' === $q ) {
			return false;
		}

		// 無效 UTF-8 與 C0/C1、雙向覆寫等危險 control 不屬於可搜尋商品文字；
		// ZWNJ/ZWJ 保留給語言文字與 emoji，但判斷時另做無 joiner 候選以防 token 拆分。
		if ( 1 !== preg_match( '//u', $q )
			|| preg_match( '/[\p{Cc}\x{200B}\x{202A}-\x{202E}\x{2060}\x{2066}-\x{2069}\x{FEFF}]/u', $q ) ) {
			return true;
		}
		$scan = preg_replace( '/[\x{200C}\x{200D}]/u', '', $q ) ?? $q;

		// XSS／HTML 執行面：攔危險元素與事件／srcdoc 屬性，不攔 C++ <vector> 等一般角括號。
		if ( preg_match( '~<\s*/?\s*(?:script|svg|img|iframe|object|embed|link|meta|style|form|input|video|audio|body)\b~iu', $scan )
			|| preg_match( '/<[^>\r\n]*(?:\s|\/)(?:on[a-z]+|srcdoc)\s*=/iu', $scan ) ) {
			return true;
		}

		// 模板執行語法；一般單層大括號與技術詞仍可搜尋。
		if ( preg_match( '~\{\{[^\r\n]*\}\}|\$\{[^\r\n]*\}|#\{[^\r\n]*\}|\{%[^\r\n]*%\}|<%[^\r\n]*%>|#set\s*\(~iu', $scan )
			|| preg_match( '/\b(?:__globals__|__import__)\b/iu', $scan ) ) {
			return true;
		}

		// 危險 scheme 與 metadata endpoint；https:// 等一般網址明確允許。
		if ( preg_match( '~\b(?:javascript|vbscript|gopher|file|dict|php|expect)\s*:|\bdata\s*:\s*text/html\b~iu', $scan )
			|| preg_match( '/\b(?:169\.254\.169\.254|metadata\.google\.internal)\b/iu', $scan ) ) {
			return true;
		}

		// 路徑穿越與明確的本機敏感檔案探測；一般 Windows path 可搜尋。
		if ( preg_match( '~(?:^|/|\x5C)\.\.(?=/|\x5C|$)|/(?:etc/passwd|proc/)|\b(?:system|win)\.ini\b~iu', $scan ) ) {
			return true;
		}

		// MySQL executable comments carry server-executable text. The opener alone is sufficient;
		// requiring a closing token or bounded body would let truncated/oversized probes through.
		if ( preg_match( '~/\*!~u', $scan ) ) {
			return true;
		}

		// Stacked-query probes require a statement boundary plus an explicit SQL command word.
		// Optional closing parentheses cover probes such as "'); DROP TABLE" while natural product text
		// such as "Drop Table 桌遊" remains searchable because it has no injected statement boundary.
		if ( preg_match(
			'~(?:[\'"`]|\b\d+)\s*\){0,8}\s*;\s*(?:select|insert|replace|update|delete|drop|alter|create|truncate|rename|grant|revoke|call|handler|load|set|show|describe|desc|explain|use|lock|unlock|begin|start|commit|rollback)\b~iu',
			$scan
		) ) {
			return true;
		}

		// SQL comments 先折成空白，以識別 UNION/**/SELECT；不攔自然語言的 "Drop Table" 書名。
		$sql = preg_replace( '~/\*.*?\*/~su', ' ', $scan ) ?? $scan;
		$sql = preg_replace( '/\s+/u', ' ', $sql ) ?? $sql;
		if ( preg_match( '/\bunion\s+(?:all\s+)?select\b|\binformation_schema\b|\b(?:sleep|benchmark|load_file)\s*\(/iu', $sql )
			|| preg_match( '/[\'"\d)]\s+\b(?:or|and)\s+(?:true|false)\b\s*(?:--|#|;|$)/iu', $sql )
			|| preg_match( '/\b(?:or|and)\s+([\p{L}\p{N}_.-]{1,64})\s*=\s*\1\b/iu', $sql )
			|| preg_match( '/\b(?:or|and)\s+\'([^\']{1,64})\'\s*=\s*\'\1(?:\'|$)/iu', $sql )
			|| preg_match( '/\b(?:or|and)\s+"([^"]{1,64})"\s*=\s*"\1(?:"|$)/iu', $sql ) ) {
			return true;
		}

		// Shell substitution 或可直接執行命令的函式形狀；單獨反引號／程式書名不攔。
		if ( preg_match( '/\$\([^\r\n)]*\)|\b(?:exec|system|shell_exec|passthru|popen|proc_open)\s*\(/iu', $scan ) ) {
			return true;
		}

		return false;
	}
}

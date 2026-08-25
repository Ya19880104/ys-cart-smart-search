<?php
declare(strict_types=1);

$ysss_test_root = dirname(__DIR__);
if (!defined('ABSPATH')) {
    define('ABSPATH', $ysss_test_root . DIRECTORY_SEPARATOR);
}

final class YSSsTestState
{
    public static int $pass = 0;
    public static int $fail = 0;

    /** @var list<string> */
    public static array $failures = [];
}

function ysss_test(string $label, callable $test): void
{
    try {
        $test();
        ++YSSsTestState::$pass;
        echo "[PASS] {$label}\n";
    } catch (Throwable $error) {
        ++YSSsTestState::$fail;
        $message = "{$label}: {$error->getMessage()}";
        YSSsTestState::$failures[] = $message;
        echo "[FAIL] {$message}\n";
    }
}

function ysss_assert_true(bool $actual, string $message = 'Expected true'): void
{
    if (!$actual) {
        throw new RuntimeException($message);
    }
}

function ysss_assert_false(bool $actual, string $message = 'Expected false'): void
{
    if ($actual) {
        throw new RuntimeException($message);
    }
}

function ysss_assert_same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        $detail = sprintf(
            'Expected %s, got %s',
            var_export($expected, true),
            var_export($actual, true)
        );
        throw new RuntimeException('' === $message ? $detail : "{$message}: {$detail}");
    }
}

function ysss_assert_contains(string $needle, string $haystack, string $message = ''): void
{
    if (!str_contains($haystack, $needle)) {
        $detail = "Expected output to contain {$needle}";
        throw new RuntimeException('' === $message ? $detail : "{$message}: {$detail}");
    }
}

function ysss_source_path(string $relative): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relative, '/'));
}

function ysss_require_source(string $relative): void
{
    $path = ysss_source_path($relative);
    if (!is_file($path)) {
        throw new RuntimeException("Missing production source: {$relative}");
    }
    require_once $path;
}

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}
if (!defined('DAY_IN_SECONDS')) {
    define('DAY_IN_SECONDS', 86400);
}
if (!defined('YS_ECOMMERCE_VERSION')) {
    define('YS_ECOMMERCE_VERSION', 'test');
}
if (!defined('YS_SMART_SEARCH_VERSION')) {
    define('YS_SMART_SEARCH_VERSION', 'test');
}
if (!defined('YS_SMART_SEARCH_URL')) {
    define('YS_SMART_SEARCH_URL', 'https://example.test/wp-content/plugins/ys-cart-smart-search/');
}

final class YSSsFakeWpdb
{
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var list<string> */
    public array $queries = [];

    /** @var list<array{table:string,data:array<string,mixed>}> */
    public array $inserts = [];

    /** @var list<mixed> */
    public array $varResults = [];

    /** @var list<array<int,mixed>> */
    public array $resultSets = [];

    /** @var list<array<int,mixed>> */
    public array $columnSets = [];

    public function prepare(string $query, mixed ...$args): string
    {
        if (1 === count($args) && is_array($args[0])) {
            $args = array_values($args[0]);
        }

        foreach ($args as $arg) {
            $query = (string) preg_replace_callback(
                '/%[sdf]/',
                static function (array $match) use ($arg): string {
                    if ('%d' === $match[0]) {
                        return (string) (int) $arg;
                    }
                    if ('%f' === $match[0]) {
                        return (string) (float) $arg;
                    }
                    return "'" . str_replace("'", "''", (string) $arg) . "'";
                },
                $query,
                1
            );
        }

        return $query;
    }

    public function esc_like(string $value): string
    {
        return addcslashes($value, '_%\\');
    }

    public function get_results(string $query, mixed $output = null): array
    {
        $this->queries[] = $query;
        return [] === $this->resultSets ? [] : (array) array_shift($this->resultSets);
    }

    public function get_var(string $query): mixed
    {
        $this->queries[] = $query;
        return [] === $this->varResults ? 0 : array_shift($this->varResults);
    }

    public function get_col(string $query): array
    {
        $this->queries[] = $query;
        return [] === $this->columnSets ? [] : (array) array_shift($this->columnSets);
    }

    public function get_row(string $query, mixed $output = null): array
    {
        $this->queries[] = $query;
        return [] === $this->resultSets ? [] : (array) array_shift($this->resultSets);
    }

    public function insert(string $table, array $data): int|false
    {
        $this->inserts[] = ['table' => $table, 'data' => $data];
        return 1;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;
        return 1;
    }
}

final class YSSsWpFake
{
    /** @var array<string,mixed> */
    public static array $transients = [];

    /** @var array<string,mixed> */
    public static array $options = [];

    /** @var array<string,list<array{priority:int,callback:callable,accepted:int}>> */
    public static array $filters = [];

    /** @var array<string,callable> */
    public static array $shortcodes = [];

    /** @var array<string,array<string,mixed>> */
    public static array $routes = [];

    public static function reset(): void
    {
        self::$transients = [];
        self::$filters = [];
        self::$shortcodes = [];
        self::$routes = [];
        self::$options = [
            'ys_ss_settings' => [
                'group_order' => ['products'],
                'suggest_window_days' => 30,
                'products' => [
                    'limit' => 6,
                    'page_limit' => 24,
                    'fields' => ['name', 'sku', 'slug'],
                ],
                'categories' => ['enabled' => false],
                'posts' => ['enabled' => false],
                'results_mode' => 'list',
            ],
        ];
        $_GET = [];
        $_POST = [];
        $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
        $_SERVER['HTTP_USER_AGENT'] = 'ys-smart-search-test';
        $GLOBALS['wpdb'] = new YSSsFakeWpdb();
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(
            private string $code = '',
            private string $message = '',
            private mixed $data = null
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request implements ArrayAccess
    {
        /** @param array<string,mixed> $params */
        public function __construct(private array $params = [])
        {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        public function offsetExists(mixed $offset): bool
        {
            return array_key_exists((string) $offset, $this->params);
        }

        public function offsetGet(mixed $offset): mixed
        {
            return $this->get_param((string) $offset);
        }

        public function offsetSet(mixed $offset, mixed $value): void
        {
            $this->params[(string) $offset] = $value;
        }

        public function offsetUnset(mixed $offset): void
        {
            unset($this->params[(string) $offset]);
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var array<string,string> */
        private array $headers = [];

        public function __construct(private mixed $data)
        {
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function header(string $name, string $value): void
        {
            $this->headers[$name] = $value;
        }

        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server
    {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const DELETABLE = 'DELETE';
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var list<mixed> */
        public array $posts = [];

        public function __construct(array $args = [])
        {
        }
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';
        $text = strip_tags($text);
        $text = (string) preg_replace('/%[a-f0-9]{2}/i', '', $text);
        return trim((string) preg_replace('/[\r\n\t ]+/', ' ', $text));
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(mixed $value): string
    {
        return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
    }
}

if (!function_exists('sanitize_title')) {
    function sanitize_title(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', $value), '-');
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $key): mixed
    {
        return YSSsWpFake::$transients[$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration): bool
    {
        YSSsWpFake::$transients[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        unset(YSSsWpFake::$transients[$key]);
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        return YSSsWpFake::$options[$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, mixed $value, mixed $autoload = null): bool
    {
        YSSsWpFake::$options[$key] = $value;
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $key, mixed $value, string $deprecated = '', mixed $autoload = null): bool
    {
        if (array_key_exists($key, YSSsWpFake::$options)) {
            return false;
        }
        YSSsWpFake::$options[$key] = $value;
        return true;
    }
}

if (!function_exists('add_filter')) {
    function add_filter(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        YSSsWpFake::$filters[$tag][] = [
            'priority' => $priority,
            'callback' => $callback,
            'accepted' => $acceptedArgs,
        ];
        return true;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $tag, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool
    {
        return add_filter($tag, $callback, $priority, $acceptedArgs);
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        $callbacks = YSSsWpFake::$filters[$tag] ?? [];
        usort($callbacks, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);
        foreach ($callbacks as $entry) {
            $callArgs = array_slice([$value, ...$args], 0, $entry['accepted']);
            $value = ($entry['callback'])(...$callArgs);
        }
        return $value;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void
    {
        YSSsWpFake::$shortcodes[$tag] = $callback;
    }
}

if (!function_exists('shortcode_atts')) {
    function shortcode_atts(array $defaults, array $attributes, string $shortcode = ''): array
    {
        return array_merge($defaults, array_intersect_key($attributes, $defaults));
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $definition): bool
    {
        YSSsWpFake::$routes[$namespace . $route] = $definition;
        return true;
    }
}

if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response(mixed $data): WP_REST_Response
    {
        return $data instanceof WP_REST_Response ? $data : new WP_REST_Response($data);
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (!function_exists('esc_html')) {
    function esc_html(mixed $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string
    {
        return esc_html($text);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(mixed $text): string
    {
        return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return esc_attr($text);
    }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = 'default'): void
    {
        echo esc_attr($text);
    }
}

if (!function_exists('esc_url')) {
    function esc_url(mixed $url): string
    {
        return (string) $url;
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(mixed $url): string
    {
        return (string) $url;
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . '/' . ltrim($path, '/');
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(mixed $key, mixed $value = null, mixed $url = null): string
    {
        if (is_array($key)) {
            $args = $key;
            $base = (string) $value;
        } else {
            $args = [(string) $key => $value];
            $base = (string) $url;
        }
        $separator = str_contains($base, '?') ? '&' : '?';
        return $base . $separator . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = ''): string
    {
        return home_url('/wp-json/' . ltrim($path, '/'));
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string
    {
        return 'ys-smart-search-test-salt-' . $scheme;
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
    {
        return json_encode($value, $flags, $depth);
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string
    {
        return '2026-08-25 12:00:00';
    }
}

if (!function_exists('get_post_types')) {
    function get_post_types(array $args = [], string $output = 'names'): array
    {
        return ['post' => 'post', 'page' => 'page'];
    }
}

if (!function_exists('get_post')) {
    function get_post(int $id): mixed
    {
        return null;
    }
}

if (!function_exists('has_shortcode')) {
    function has_shortcode(string $content, string $tag): bool
    {
        return str_contains($content, '[' . $tag . ']');
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink(mixed $post): string
    {
        return home_url('/post/' . (string) $post);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        return true;
    }
}

if (!function_exists('wp_insert_post')) {
    function wp_insert_post(array $post, bool $wpError = false): int
    {
        return 0;
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error(mixed $value): bool
    {
        return $value instanceof WP_Error;
    }
}

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style(string $handle): void
    {
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle): void
    {
    }
}

if (!function_exists('wp_localize_script')) {
    function wp_localize_script(string $handle, string $name, array $data): bool
    {
        return true;
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $text): string
    {
        return strip_tags($text);
    }
}

if (!function_exists('get_the_excerpt')) {
    function get_the_excerpt(mixed $post): string
    {
        return '';
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title(mixed $post): string
    {
        return '';
    }
}

if (!function_exists('get_the_post_thumbnail_url')) {
    function get_the_post_thumbnail_url(mixed $post, string $size = 'post-thumbnail'): string
    {
        return '';
    }
}

YSSsWpFake::reset();

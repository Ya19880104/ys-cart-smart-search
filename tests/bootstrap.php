<?php
declare(strict_types=1);

$ysss_test_root = dirname(__DIR__);
if (!defined('ABSPATH')) {
    define('ABSPATH', $ysss_test_root . DIRECTORY_SEPARATOR);
}

spl_autoload_register(static function (string $class) use ($ysss_test_root): void {
    $prefix = 'YangSheep\\SmartSearch\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
    $path = $ysss_test_root . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

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
    public int $insert_id = 0;

    /** @var list<string> */
    public array $queries = [];

    /** @var list<array{table:string,data:array<string,mixed>}> */
    public array $inserts = [];

    /** @var list<array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
    public array $updates = [];

    /** @var list<array{table:string,where:array<string,mixed>}> */
    public array $deletes = [];

    /** @var null|Closure(string,array<string,mixed>,self):int|false */
    public ?Closure $insertHandler = null;

    /** @var null|Closure(string,array<string,mixed>,array<string,mixed>,self):int|false */
    public ?Closure $updateHandler = null;

    /** @var null|Closure(string,array<string,mixed>,self):int|false */
    public ?Closure $deleteHandler = null;

    /** @var list<mixed> */
    public array $varResults = [];

    /** @var null|Closure(string,self):mixed */
    public ?Closure $getVarHandler = null;

    /** @var null|Closure(string,self):int|false */
    public ?Closure $queryHandler = null;

    /** @var null|Closure(string,mixed,self):array */
    public ?Closure $getResultsHandler = null;

    /** @var null|Closure(string,mixed,self):array */
    public ?Closure $rateGetResultsHandler = null;

    /** @var null|Closure(string,self):int|false */
    public ?Closure $rateQueryHandler = null;

    /** @var null|Closure(string,self):void */
    public ?Closure $rateCleanupBeforeApply = null;

    /** @var list<array<int,mixed>> */
    public array $resultSets = [];

    /** @var list<array<int,mixed>> */
    public array $columnSets = [];

    public bool $respectColumnLimit = false;

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
        $ratePattern = '/\ASELECT\s+option_name\s*,\s*option_value'
            . '\s+FROM\s+`?' . preg_quote($this->prefix . 'options', '/') . '`?'
            . "\s+WHERE\s+option_name\s*=\s*'(ys_ss_rate_v1_[a-z0-9_-]{1,32}_[a-f0-9]{24})'"
            . '\s+LIMIT\s+2\s*\z/iD';
        if (1 === preg_match($ratePattern, trim($query), $rateMatch)) {
            if (null !== $this->rateGetResultsHandler) {
                return (array) ($this->rateGetResultsHandler)($query, $output, $this);
            }
            $name = $rateMatch[1];
            return array_key_exists($name, YSSsWpFake::$options)
                ? [['option_name' => $name, 'option_value' => (string) YSSsWpFake::$options[$name]]]
                : [];
        }
        if (null !== $this->getResultsHandler) {
            return (array) ($this->getResultsHandler)($query, $output, $this);
        }
        if (str_contains($query, 'information_schema.TABLES')) {
            return [
                ['TABLE_NAME' => $this->prefix . 'ys_ss_queries', 'ENGINE' => 'InnoDB'],
                ['TABLE_NAME' => $this->prefix . 'ys_ss_terms_daily', 'ENGINE' => 'InnoDB'],
            ];
        }
        $authorityPattern = '/\ASELECT\s+option_name\s*,\s*option_value'
            . '\s+FROM\s+`?' . preg_quote($this->prefix . 'options', '/') . '`?'
            . "\s+WHERE\s+option_name\s+IN\s*\(\s*'ys_ss_suggest_cache_generation'"
            . "\s*,\s*'(ys_ss_suggest_tombstone_[a-f0-9]{64})'\s*\)\s*\z/iD";
        if (1 === preg_match($authorityPattern, trim($query), $authorityMatch)) {
            $names = ['ys_ss_suggest_cache_generation', $authorityMatch[1]];
            $rows = [];
            foreach ($names as $name) {
                if (array_key_exists($name, YSSsWpFake::$options)) {
                    $rows[] = [
                        'option_name' => $name,
                        'option_value' => is_string(YSSsWpFake::$options[$name])
                            ? YSSsWpFake::$options[$name]
                            : (string) YSSsWpFake::$options[$name],
                    ];
                }
            }
            return $rows;
        }
        return [] === $this->resultSets ? [] : (array) array_shift($this->resultSets);
    }

    public function get_var(string $query): mixed
    {
        $this->queries[] = $query;
        if (null !== $this->getVarHandler) {
            return ($this->getVarHandler)($query, $this);
        }
        if (str_contains($query, 'GET_LOCK') || str_contains($query, 'RELEASE_LOCK')) {
            return 1;
        }
        return [] === $this->varResults ? 0 : array_shift($this->varResults);
    }

    public function get_col(string $query): array
    {
        $this->queries[] = $query;
        $rows = [] === $this->columnSets ? [] : (array) array_shift($this->columnSets);
        if ($this->respectColumnLimit && preg_match('/\bLIMIT\s+(\d+)/i', $query, $match)) {
            $rows = array_slice($rows, 0, (int) $match[1]);
        }
        return $rows;
    }

    public function get_row(string $query, mixed $output = null): array
    {
        $this->queries[] = $query;
        return [] === $this->resultSets ? [] : (array) array_shift($this->resultSets);
    }

    public function insert(string $table, array $data): int|false
    {
        $this->insert_id = 0;
        $this->inserts[] = ['table' => $table, 'data' => $data];
        if (null !== $this->insertHandler) {
            $result = ($this->insertHandler)($table, $data, $this);
            if (false === $result) {
                $this->insert_id = 0;
            }
            return $result;
        }
        $this->insert_id = count($this->inserts);
        return 1;
    }

    public function update(string $table, array $data, array $where): int|false
    {
        $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
        if (null !== $this->updateHandler) {
            return ($this->updateHandler)($table, $data, $where, $this);
        }
        return 1;
    }

    public function delete(string $table, array $where): int|false
    {
        $this->deletes[] = ['table' => $table, 'where' => $where];
        if (null !== $this->deleteHandler) {
            return ($this->deleteHandler)($table, $where, $this);
        }
        return 1;
    }

    public function query(string $query): int|false
    {
        $this->queries[] = $query;
        $rateUpsertPattern = '/\AINSERT\s+INTO\s+`?'
            . preg_quote($this->prefix . 'options', '/') . '`?'
            . '\s*\(\s*option_name\s*,\s*option_value\s*,\s*autoload\s*\)'
            . "\s*VALUES\s*\(\s*'(ys_ss_rate_v1_[a-z0-9_-]{1,32}_[a-f0-9]{24})'"
            . "\s*,\s*'(v1:[0-9]+:[0-9]+)'\s*,\s*'no'\s*\)"
            . '\s*ON\s+DUPLICATE\s+KEY\s+UPDATE\s+option_value\s*=\s*VALUES\(option_value\)'
            . '\s*,\s*autoload\s*=\s*VALUES\(autoload\)\s*\z/isD';
        if (1 === preg_match($rateUpsertPattern, trim($query), $rateMatch)) {
            if (null !== $this->rateQueryHandler) {
                return ($this->rateQueryHandler)($query, $this);
            }
            YSSsWpFake::$options[$rateMatch[1]] = $rateMatch[2];
            return 1;
        }
        $trimmed = trim($query);
        $rateCleanupPattern = '/\ADELETE\s+FROM\s+`?'
            . preg_quote($this->prefix . 'options', '/') . '`?'
            . '\s+WHERE\s+option_name\s+LIKE\s+'
            . preg_quote("'ys\\_ss\\_rate\\_v1\\_%'", '/')
            . '\s+AND\s+option_name\s+REGEXP\s+'
            . preg_quote("'^ys_ss_rate_v1_[a-z0-9_-]{1,32}_[a-f0-9]{24}$'", '/')
            . '\s+AND\s+option_value\s+REGEXP\s+'
            . preg_quote("'^v1:[1-9][0-9]*:[1-9][0-9]*$'", '/')
            . "\s+AND\s+CAST\(SUBSTRING_INDEX\(SUBSTRING_INDEX\(option_value\s*,\s*':'\s*,\s*2\)"
            . "\s*,\s*':'\s*,\s*-1\)\s+AS\s+UNSIGNED\)\s*<=\s*([0-9]+)"
            . '\s+LIMIT\s+5000\s*\z/isD';
        if (1 === preg_match($rateCleanupPattern, $trimmed, $cleanupMatch)) {
            // Real wpdb clears a prior query's last_error before executing this new statement.
            $this->last_error = '';
            if (null !== $this->rateQueryHandler) {
                return ($this->rateQueryHandler)($query, $this);
            }
            if (null !== $this->rateCleanupBeforeApply) {
                ($this->rateCleanupBeforeApply)($query, $this);
            }
            $now = (int) ($cleanupMatch[1] ?? 0);
            $deleted = 0;
            foreach (array_keys(YSSsWpFake::$options) as $name) {
                $value = YSSsWpFake::$options[$name];
                if (1 !== preg_match('/\Ays_ss_rate_v1_[a-z0-9_-]{1,32}_[a-f0-9]{24}\z/iD', $name)
                    || !is_string($value)
                    || 1 !== preg_match('/\Av1:([1-9][0-9]*):([1-9][0-9]*)\z/D', $value, $state)
                    || (int) $state[1] > $now) {
                    continue;
                }
                unset(YSSsWpFake::$options[$name]);
                ++$deleted;
                if (5000 === $deleted) {
                    break;
                }
            }
            return $deleted;
        }
        if (null !== $this->queryHandler) {
            return ($this->queryHandler)($query, $this);
        }
        return 1;
    }
}

final class YSSsWpFake
{
    /** @var array<int,WP_Post> */
    public static array $posts = [];

    /** @var list<array<string,mixed>> */
    public static array $wpQueryArgs = [];

    /** @var list<mixed> */
    public static array $wpQueryPosts = [];

    /** @var array<string,mixed> */
    public static array $transients = [];

    /** @var array<string,mixed> */
    public static array $options = [];

    /** @var list<array{key:string,value:mixed}> */
    public static array $optionUpdates = [];

    /** @var list<array{key:string,default:mixed}> */
    public static array $optionGets = [];

    /** @var list<array{key:string,value:mixed,deprecated:string,autoload:mixed}> */
    public static array $optionAdds = [];

    /** @var list<array{key:string,value:mixed,autoload:mixed}> */
    public static array $optionUpdateCalls = [];

    /** @var list<array{key:string}> */
    public static array $optionDeletes = [];

    /** @var list<array{operation:string,key:string}> */
    public static array $optionAccesses = [];

    /** @var null|Closure(string,mixed):mixed */
    public static ?Closure $getOptionHandler = null;

    /** @var null|Closure(string,mixed,string,mixed):bool */
    public static ?Closure $addOptionHandler = null;

    /** @var null|Closure(string,mixed,mixed):bool */
    public static ?Closure $updateOptionHandler = null;

    /** @var null|Closure(string):bool */
    public static ?Closure $deleteOptionHandler = null;

    /** @var null|Closure(string,mixed):void */
    public static ?Closure $updateOptionBeforeWrite = null;

    /** @var list<array{key:string}> */
    public static array $transientGets = [];

    /** @var list<array{key:string,value:mixed,expiration:int}> */
    public static array $transientSets = [];

    /** @var list<array{key:string}> */
    public static array $transientDeletes = [];

    /** @var null|Closure(string):mixed */
    public static ?Closure $getTransientHandler = null;

    /** @var null|Closure(string,mixed,int):bool */
    public static ?Closure $setTransientHandler = null;

    /** @var null|Closure(string):bool */
    public static ?Closure $deleteTransientHandler = null;

    /** @var null|Closure(int):string */
    public static ?Closure $randomBytesHandler = null;

    /** @var list<string> */
    public static array $errorLogs = [];

    /** @var array<string,list<array{priority:int,callback:callable,accepted:int}>> */
    public static array $filters = [];

    /** @var array<string,callable> */
    public static array $shortcodes = [];

    /** @var array<string,array<string,mixed>> */
    public static array $routes = [];

    public static function reset(): void
    {
        self::$posts = [];
        self::$wpQueryArgs = [];
        self::$wpQueryPosts = [];
        self::$transients = [];
        self::$optionUpdates = [];
        self::$optionGets = [];
        self::$optionAdds = [];
        self::$optionUpdateCalls = [];
        self::$optionDeletes = [];
        self::$optionAccesses = [];
        self::$getOptionHandler = null;
        self::$addOptionHandler = null;
        self::$updateOptionHandler = null;
        self::$deleteOptionHandler = null;
        self::$updateOptionBeforeWrite = null;
        self::$transientGets = [];
        self::$transientSets = [];
        self::$transientDeletes = [];
        self::$getTransientHandler = null;
        self::$setTransientHandler = null;
        self::$deleteTransientHandler = null;
        self::$randomBytesHandler = null;
        self::$errorLogs = [];
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

        public function has_param(string $key): bool
        {
            return array_key_exists($key, $this->params);
        }

        /** @return array<string,mixed> */
        public function get_json_params(): array
        {
            return $this->params;
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

if (!class_exists('WP_Post')) {
    class WP_Post
    {
        public function __construct(
            public int $ID,
            public string $post_type = 'page',
            public string $post_status = 'publish',
            public string $post_content = '',
            public string $post_password = ''
        ) {
        }
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query
    {
        /** @var list<mixed> */
        public array $posts = [];

        public function __construct(array $args = [])
        {
            YSSsWpFake::$wpQueryArgs[] = $args;
            $this->posts = YSSsWpFake::$wpQueryPosts;
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
        $filtered = strip_tags($text);
        $filtered = (string) preg_replace('/%[a-f0-9]{2}/i', '', $filtered);
        $filtered = trim((string) preg_replace('/[\r\n\t ]+/', ' ', $filtered));
        return (string) apply_filters('sanitize_text_field', $filtered, $text);
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
        YSSsWpFake::$transientGets[] = ['key' => $key];
        if (null !== YSSsWpFake::$getTransientHandler) {
            return (YSSsWpFake::$getTransientHandler)($key);
        }
        return YSSsWpFake::$transients[$key] ?? false;
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $key, mixed $value, int $expiration): bool
    {
        YSSsWpFake::$transientSets[] = ['key' => $key, 'value' => $value, 'expiration' => $expiration];
        if (null !== YSSsWpFake::$setTransientHandler) {
            return (YSSsWpFake::$setTransientHandler)($key, $value, $expiration);
        }
        YSSsWpFake::$transients[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $key): bool
    {
        YSSsWpFake::$transientDeletes[] = ['key' => $key];
        if (null !== YSSsWpFake::$deleteTransientHandler) {
            return (YSSsWpFake::$deleteTransientHandler)($key);
        }
        unset(YSSsWpFake::$transients[$key]);
        return true;
    }
}

if (!function_exists('get_option')) {
    function get_option(string $key, mixed $default = false): mixed
    {
        YSSsWpFake::$optionGets[] = ['key' => $key, 'default' => $default];
        YSSsWpFake::$optionAccesses[] = ['operation' => 'get', 'key' => $key];
        if (null !== YSSsWpFake::$getOptionHandler) {
            return (YSSsWpFake::$getOptionHandler)($key, $default);
        }
        return YSSsWpFake::$options[$key] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $key, mixed $value, mixed $autoload = null): bool
    {
        YSSsWpFake::$optionUpdates[] = ['key' => $key, 'value' => $value];
        YSSsWpFake::$optionUpdateCalls[] = ['key' => $key, 'value' => $value, 'autoload' => $autoload];
        YSSsWpFake::$optionAccesses[] = ['operation' => 'update', 'key' => $key];
        if (null !== YSSsWpFake::$updateOptionHandler) {
            return (YSSsWpFake::$updateOptionHandler)($key, $value, $autoload);
        }
        if (null !== YSSsWpFake::$updateOptionBeforeWrite) {
            (YSSsWpFake::$updateOptionBeforeWrite)($key, $value);
        }
        YSSsWpFake::$options[$key] = $value;
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $key, mixed $value, string $deprecated = '', mixed $autoload = null): bool
    {
        YSSsWpFake::$optionAdds[] = [
            'key' => $key,
            'value' => $value,
            'deprecated' => $deprecated,
            'autoload' => $autoload,
        ];
        YSSsWpFake::$optionAccesses[] = ['operation' => 'add', 'key' => $key];
        if (null !== YSSsWpFake::$addOptionHandler) {
            return (YSSsWpFake::$addOptionHandler)($key, $value, $deprecated, $autoload);
        }
        if (array_key_exists($key, YSSsWpFake::$options)) {
            return false;
        }
        YSSsWpFake::$options[$key] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $key): bool
    {
        YSSsWpFake::$optionDeletes[] = ['key' => $key];
        YSSsWpFake::$optionAccesses[] = ['operation' => 'delete', 'key' => $key];
        if (null !== YSSsWpFake::$deleteOptionHandler) {
            return (YSSsWpFake::$deleteOptionHandler)($key);
        }
        $existed = array_key_exists($key, YSSsWpFake::$options);
        unset(YSSsWpFake::$options[$key]);
        return $existed;
    }
}

if (!function_exists('rest_sanitize_boolean')) {
    function rest_sanitize_boolean(mixed $value): bool
    {
        if (is_string($value)) {
            $value = strtolower($value);
            if (in_array($value, ['false', '0'], true)) {
                return false;
            }
        }
        return (bool) $value;
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
        return YSSsWpFake::$posts[$id] ?? null;
    }
}

if (!function_exists('has_shortcode')) {
    function has_shortcode(string $content, string $tag): bool
    {
        return str_contains($content, '[' . $tag . ']');
    }
}

if (!function_exists('get_shortcode_regex')) {
    /**
     * Core-compatible shortcode matcher for the focused test runtime.
     * Capture groups intentionally match WordPress get_shortcode_regex().
     *
     * @param list<string>|null $tagnames
     */
    function get_shortcode_regex(?array $tagnames = null): string
    {
        $tagnames ??= array_keys(YSSsWpFake::$shortcodes);
        $tagregexp = implode('|', array_map('preg_quote', $tagnames));

        return '\\['
            . '(\\[?)'
            . "({$tagregexp})"
            . '(?![\\w-])'
            . '('
            .     '[^\\]\\/]*'
            .     '(?:'
            .         '\\/(?!\\])'
            .         '[^\\]\\/]*'
            .     ')*?'
            . ')'
            . '(?:'
            .     '(\\/)'
            .     '\\]'
            . '|'
            .     '\\]'
            .     '(?:'
            .         '('
            .             '[^\\[]*+'
            .             '(?:'
            .                 '\\[(?!\\/\\2\\])'
            .                 '[^\\[]*+'
            .             ')*+'
            .         ')'
            .         '\\[\\/\\2\\]'
            .     ')?'
            . ')'
            . '(\\]?)';
    }
}

if (!function_exists('wp_html_split')) {
    /**
     * Focused HTML-token seam: preserve text while returning ordinary tags,
     * comments and CDATA as distinct tokens, matching the core contract consumed here.
     *
     * @return list<string>
     */
    function wp_html_split(string $input): array
    {
        $parts = preg_split(
            '/(<!--[\s\S]*?-->|<!\[CDATA\[[\s\S]*?\]\]>|<\/?[A-Za-z][^>]*>)/',
            $input,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );
        return false === $parts ? [$input] : array_values($parts);
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

// Install receipt clock seams before any behavior file can load a Security or API
// call site. Defining these later is order-dependent because PHP may cache the
// global time() fallback. Counters stay completely inert unless a receipt test
// explicitly creates its tracking global.
if (!function_exists('YangSheep\\SmartSearch\\Api\\time')) {
    eval(<<<'PHP'
namespace YangSheep\SmartSearch\Api {
    function time(): int {
        if (array_key_exists('ysss_receipt_api_time_calls', $GLOBALS)) {
            $GLOBALS['ysss_receipt_api_time_calls'] = (int) $GLOBALS['ysss_receipt_api_time_calls'] + 1;
        }
        return array_key_exists('ysss_receipt_api_now', $GLOBALS)
            ? (int) $GLOBALS['ysss_receipt_api_now']
            : \time();
    }
}
PHP);
}

if (!function_exists('YangSheep\\SmartSearch\\Security\\time')) {
    eval(<<<'PHP'
namespace YangSheep\SmartSearch\Security {
    function time(): int {
        if (array_key_exists('ysss_receipt_security_time_calls', $GLOBALS)) {
            $GLOBALS['ysss_receipt_security_time_calls'] = (int) $GLOBALS['ysss_receipt_security_time_calls'] + 1;
        }
        return array_key_exists('ysss_receipt_security_now', $GLOBALS)
            ? (int) $GLOBALS['ysss_receipt_security_now']
            : \time();
    }
}
PHP);
}

// Install the entropy seam before any behavior file can invoke SuggestService.
// Defining this lazily inside suggestion-cache-behavior.php is order-dependent:
// PHP may already have cached the global random_bytes() fallback at the call site.
if (!function_exists('YangSheep\\SmartSearch\\Services\\random_bytes')) {
    eval(<<<'PHP'
namespace YangSheep\SmartSearch\Services {
    function random_bytes(int $length): string {
        if (null !== \YSSsWpFake::$randomBytesHandler) {
            return (\YSSsWpFake::$randomBytesHandler)($length);
        }
        return \random_bytes($length);
    }
}
PHP);
}

YSSsWpFake::reset();

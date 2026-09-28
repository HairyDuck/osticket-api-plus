<?php
/**
 * Offline smoke checks (no osTicket install required).
 * Run: php tests/smoke.php
 */

$root = dirname(__DIR__);
$failures = 0;

function fail($msg) {
    global $failures;
    $failures++;
    fwrite(STDERR, "FAIL: $msg\n");
}

function ok($msg) {
    fwrite(STDOUT, "OK: $msg\n");
}

$files = array(
    'plugin.php',
    'config.php',
    'osticket-api-plus.php',
    'api.php',
);

foreach ($files as $file) {
    $path = $root . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        fail("missing $file");
        continue;
    }
    $out = array();
    $code = 0;
    exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        fail("$file syntax: " . implode(' ', $out));
    } else {
        ok("$file syntax");
    }
}

$plugin = include $root . '/plugin.php';
if (!is_array($plugin) || empty($plugin['plugin'])) {
    fail('plugin.php metadata');
} else {
    ok('plugin.php metadata');
}
if (stripos(json_encode($plugin), 'cursor') !== false) {
    fail('plugin metadata must not mention Cursor');
} else {
    ok('no Cursor mention in plugin metadata');
}

// Route regexes must match real dispatcher paths (named groups become positional args)
$routes = array(
    array('^/api-plus/staff/tickets/(?P<id>\d+)/reply\.json$', '/api-plus/staff/tickets/42/reply.json', array('42')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)/status\.json$', '/api-plus/staff/tickets/42/status.json', array('42')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)/note\.json$', '/api-plus/staff/tickets/7/note.json', array('7')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)\.json$', '/api-plus/staff/tickets/99.json', array('99')),
    array('^/api-plus/staff/tickets\.json$', '/api-plus/staff/tickets.json', array()),
    array('^/api-plus/tickets/(?P<number>[^/]+)\.json$', '/api-plus/tickets/23832.json', array('23832')),
    array('^/api-plus/tickets\.json$', '/api-plus/tickets.json', array()),
);

foreach ($routes as $row) {
    list($regex, $path, $expectedArgs) = $row;
    if (!preg_match('@' . $regex . '@', $path, $m)) {
        fail("no match for $path via $regex");
        continue;
    }
    // Mimic osTicket UrlMatcher: drop named keys and full match
    $numeric = array();
    foreach ($m as $k => $v) {
        if (is_int($k)) {
            $numeric[$k] = $v;
        }
    }
    unset($numeric[0]);
    $args = array_values($numeric);
    if ($args !== $expectedArgs) {
        fail("args for $path expected " . json_encode($expectedArgs) . ' got ' . json_encode($args));
    } else {
        ok("route $path");
    }
}

// Negative: must not steal stock create URL
if (preg_match('@^/api-plus/tickets\.json$@', '/tickets.json')) {
    fail('stock /tickets.json incorrectly matched');
} else {
    ok('stock /tickets.json not captured by api-plus list route');
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "\nAll smoke checks passed.\n");
exit(0);

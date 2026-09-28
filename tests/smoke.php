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
if (empty($plugin['version']) || $plugin['version'] !== '1.2.0') {
    fail('plugin version expected 1.2.0');
} else {
    ok('plugin version 1.2.0');
}
if (stripos(json_encode($plugin), 'synthetix') !== false) {
    fail('plugin metadata must not mention Synthetix');
} else {
    ok('no Synthetix mention in plugin metadata');
}

$readme = file_get_contents($root . '/README.md');
if ($readme === false) {
    fail('README.md missing');
} else {
    if (stripos($readme, 'Features we will consider if requested') === false) {
        fail('README missing consider-if-requested section');
    } else {
        ok('README consider-if-requested section');
    }
    if (stripos($readme, '## Changelog') === false || stripos($readme, '### 1.2.0') === false) {
        fail('README missing Changelog 1.2.0');
    } else {
        ok('README Changelog 1.2.0');
    }
    if (strpos($readme, 'Metadata (version 1.2.0)') === false) {
        fail('README layout version expected 1.2.0');
    } else {
        ok('README layout version 1.2.0');
    }
    if (stripos($readme, 'attachments/') === false) {
        fail('README missing attachment download docs');
    } else {
        ok('README attachments docs');
    }
    if (stripos($readme, 'synthetix') !== false) {
        fail('README must not mention Synthetix');
    } else {
        ok('README brand-neutral');
    }
}

// Route regexes must match real dispatcher paths (named groups become positional args)
$routes = array(
    array('^/api-plus/health\.json$', '/api-plus/health.json', array()),
    array('^/api-plus/staff/statuses\.json$', '/api-plus/staff/statuses.json', array()),
    array('^/api-plus/staff/depts\.json$', '/api-plus/staff/depts.json', array()),
    array('^/api-plus/staff/staff\.json$', '/api-plus/staff/staff.json', array()),
    array('^/api-plus/staff/canned\.json$', '/api-plus/staff/canned.json', array()),
    array('^/api-plus/staff/priorities\.json$', '/api-plus/staff/priorities.json', array()),
    array('^/api-plus/staff/topics\.json$', '/api-plus/staff/topics.json', array()),
    array('^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/attachments/(?P<file_id>\d+)\.json$', '/api-plus/staff/tickets/by-number/23833/attachments/29.json', array('23833', '29')),
    array('^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/reply\.json$', '/api-plus/staff/tickets/by-number/23833/reply.json', array('23833')),
    array('^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/status\.json$', '/api-plus/staff/tickets/by-number/23833/status.json', array('23833')),
    array('^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/note\.json$', '/api-plus/staff/tickets/by-number/23833/note.json', array('23833')),
    array('^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/assign\.json$', '/api-plus/staff/tickets/by-number/23833/assign.json', array('23833')),
    array('^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/priority\.json$', '/api-plus/staff/tickets/by-number/23833/priority.json', array('23833')),
    array('^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)/topic\.json$', '/api-plus/staff/tickets/by-number/23833/topic.json', array('23833')),
    array('^/api-plus/staff/tickets/by-number/(?P<number>[^/]+)\.json$', '/api-plus/staff/tickets/by-number/23833.json', array('23833')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)/attachments/(?P<file_id>\d+)\.json$', '/api-plus/staff/tickets/42/attachments/29.json', array('42', '29')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)/reply\.json$', '/api-plus/staff/tickets/42/reply.json', array('42')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)/status\.json$', '/api-plus/staff/tickets/42/status.json', array('42')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)/note\.json$', '/api-plus/staff/tickets/7/note.json', array('7')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)/assign\.json$', '/api-plus/staff/tickets/42/assign.json', array('42')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)/priority\.json$', '/api-plus/staff/tickets/42/priority.json', array('42')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)/topic\.json$', '/api-plus/staff/tickets/42/topic.json', array('42')),
    array('^/api-plus/staff/tickets/(?P<id>\d+)\.json$', '/api-plus/staff/tickets/99.json', array('99')),
    array('^/api-plus/staff/tickets\.json$', '/api-plus/staff/tickets.json', array()),
    array('^/api-plus/tickets/(?P<number>[^/]+)/attachments/(?P<file_id>\d+)\.json$', '/api-plus/tickets/23832/attachments/29.json', array('23832', '29')),
    array('^/api-plus/tickets/(?P<number>[^/]+)\.json$', '/api-plus/tickets/23832.json', array('23832')),
    array('^/api-plus/tickets\.json$', '/api-plus/tickets.json', array()),
);

foreach ($routes as $row) {
    list($regex, $path, $expectedArgs) = $row;
    if (!preg_match('@' . $regex . '@', $path, $m)) {
        fail("no match for $path via $regex");
        continue;
    }
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

<?php
/**
 * Offline smoke checks for Domain Gate (no osTicket install required).
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
    'osticket-domain-gate.php',
    'include/class.domain_gate.php',
);

foreach ($files as $file) {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
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
if (empty($plugin['version']) || $plugin['version'] !== '1.0.3') {
    fail('plugin version expected 1.0.3');
} else {
    ok('plugin version 1.0.3');
}
foreach (array('cursor', 'synthetix') as $brand) {
    if (stripos(json_encode($plugin), $brand) !== false) {
        fail("plugin metadata must not mention $brand");
    }
}
ok('plugin metadata brand-neutral');

// Offline domain list validation / policy checks (no osTicket bootstrap)
function dg_validate_domain_list($text) {
    foreach (preg_split("/\r\n|\n|\r/", (string) $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $line = ltrim($line, '@');
        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $line)) {
            return $line;
        }
    }
    return null;
}

function dg_parse_domains($text) {
    $out = array();
    foreach (preg_split("/\r\n|\n|\r/", (string) $text) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $line = strtolower(ltrim($line, '@'));
        if ($line !== '') {
            $out[$line] = true;
        }
    }
    return $out;
}

function dg_email_domain($email) {
    $email = strtolower(trim((string) $email));
    if ($email === '' || strpos($email, '@') === false) {
        return '';
    }
    $parts = explode('@', $email, 2);
    return isset($parts[1]) ? strtolower(trim($parts[1])) : '';
}

function dg_decide($email, $mode, $allowText, $blockText, $allowEmails = '', $denyEmails = '', $orgHit = false, $ticketRef = false) {
    $email = strtolower(trim($email));
    $domain = dg_email_domain($email);
    foreach (preg_split("/\r\n|\n|\r/", $denyEmails) as $line) {
        $line = strtolower(trim($line));
        if ($line !== '' && $line[0] !== '#' && $line === $email) {
            return array(false, 'deny-email');
        }
    }
    foreach (preg_split("/\r\n|\n|\r/", $allowEmails) as $line) {
        $line = strtolower(trim($line));
        if ($line !== '' && $line[0] !== '#' && $line === $email) {
            return array(true, 'allow-email');
        }
    }
    if ($ticketRef) {
        return array(true, 'ticket-ref');
    }
    if ($orgHit) {
        return array(true, 'organisation-domain');
    }
    $allow = dg_parse_domains($allowText);
    $block = dg_parse_domains($blockText);
    $onAllow = ($domain !== '' && isset($allow[$domain]));
    $onBlock = ($domain !== '' && isset($block[$domain]));
    if ($mode === 'blocklist') {
        return $onBlock ? array(false, 'blocklist-domain') : array(true, 'not-blocklisted');
    }
    if ($mode === 'both') {
        if (!$onAllow) {
            return array(false, 'not-allowlisted');
        }
        if ($onBlock) {
            return array(false, 'blocklist-domain');
        }
        return array(true, 'allowlist-and-not-blocklisted');
    }
    return $onAllow ? array(true, 'allowlist-domain') : array(false, 'not-allowlisted');
}

if (dg_validate_domain_list("acme.com\n# comment\nbad_domain") !== 'bad_domain') {
    fail('validateDomainList should reject bad_domain');
} else {
    ok('validateDomainList rejects bad tokens');
}
if (dg_validate_domain_list("acme.com\nkarcher.co.uk") !== null) {
    fail('validateDomainList should accept good domains');
} else {
    ok('validateDomainList accepts good domains');
}

list($a, $r) = dg_decide('user@gmail.com', 'allowlist', "acme.com\n", "gmail.com\n");
if ($a !== false || $r !== 'not-allowlisted') {
    fail("allowlist gmail expected blocked, got allow=" . var_export($a, true) . " reason=$r");
} else {
    ok('allowlist blocks free-mail not listed');
}

list($a, $r) = dg_decide('ops@acme.com', 'allowlist', "acme.com\n", "gmail.com\n");
if ($a !== true) {
    fail('allowlist should allow acme.com');
} else {
    ok('allowlist allows listed domain');
}

list($a, $r) = dg_decide('user@gmail.com', 'blocklist', "acme.com\n", "gmail.com\n");
if ($a !== false || $r !== 'blocklist-domain') {
    fail('blocklist should block gmail.com');
} else {
    ok('blocklist blocks free-mail');
}

list($a, $r) = dg_decide('ops@acme.com', 'blocklist', '', "gmail.com\n");
if ($a !== true) {
    fail('blocklist should allow non-listed domains');
} else {
    ok('blocklist allows other domains');
}

list($a, $r) = dg_decide('user@gmail.com', 'allowlist', '', "gmail.com\n", '', '', true, false);
if ($a !== true || $r !== 'organisation-domain') {
    fail('organisation domain should allow');
} else {
    ok('organisation domain override');
}

list($a, $r) = dg_decide('user@gmail.com', 'allowlist', '', "gmail.com\n", '', '', false, true);
if ($a !== true || $r !== 'ticket-ref') {
    fail('ticket ref should allow');
} else {
    ok('ticket reference override');
}

list($a, $r) = dg_decide('vip@gmail.com', 'allowlist', '', "gmail.com\n", "vip@gmail.com\n", '');
if ($a !== true || $r !== 'allow-email') {
    fail('allow email override failed');
} else {
    ok('allow email override');
}

$readme = file_get_contents($root . '/README.md');
if ($readme === false) {
    fail('README missing');
} else {
    if (stripos($readme, 'DOMAIN-GATE-ALLOW') === false) {
        fail('README should document DOMAIN-GATE-ALLOW');
    } else {
        ok('README documents allow command');
    }
    if (stripos($readme, 'organisation') === false && stripos($readme, 'organization') === false) {
        fail('README should mention organisation domains');
    } else {
        ok('README mentions organisation domains');
    }
    if (stripos($readme, 'ticket') === false || stripos($readme, '#') === false) {
        fail('README should mention ticket-number bypass');
    } else {
        ok('README mentions ticket-number bypass');
    }
    foreach (array('cursor', 'synthetix') as $brand) {
        if (stripos($readme, $brand) !== false) {
            fail("README must not mention $brand");
        }
    }
    ok('README brand-neutral');
}

if ($failures > 0) {
    fwrite(STDERR, "\n$failures failure(s)\n");
    exit(1);
}

fwrite(STDOUT, "\nAll smoke checks passed.\n");
exit(0);

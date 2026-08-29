<?php
/**
 * Adds (or removes) the two edits FrenzyEx needs inside the bot's own source.
 *
 * Only two files are touched — index.php for the buyer's branch and
 * keyboard.php for the button. Everything else FrenzyEx needs lives in files of
 * its own, and the settings go straight into the database, so admin.php,
 * function.php and the lang files are left alone entirely.
 *
 * Every edit sits between markers. Re-running strips the old block before
 * inserting the new one, which makes this safe to run repeatedly and makes an
 * upgrade the same operation as a first install.
 *
 * A patched file is written to a temporary path and run through `php -l`
 * first. Anything that would not parse is thrown away and the original is left
 * exactly as it was — a bot that will not boot is worse than one without this
 * gateway.
 *
 * Usage:
 *   php patcher.php <mirza-dir> apply
 *   php patcher.php <mirza-dir> remove
 *   php patcher.php <mirza-dir> status
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

const MARK_OPEN = '// >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers';
const MARK_CLOSE = '// <<< FRENZYEX GATEWAY';

$dir = rtrim($argv[1] ?? '', '/');
$cmd = $argv[2] ?? 'status';

if ($dir === '' || !is_file($dir . '/index.php')) {
    fwrite(STDERR, "index.php not found in: $dir\n");
    exit(1);
}

/**
 * The buyer's branch. Inserted immediately before the zarinpal branch, so it
 * joins the same elseif chain: the block opens with `}` and closes without
 * one, letting the anchor line continue the chain underneath it.
 */
$INDEX_BLOCK = <<<'PHPBLOCK'
        // >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
    } elseif ($datain == "frenzyex") {
        require_once __DIR__ . '/frenzyex_lib.php';
        $fx_min = frenzyex_setting('minbalancefrenzyex', '20000');
        $fx_max = frenzyex_setting('maxbalancefrenzyex', '1000000');
        if ($user['Processing_value'] < $fx_min || $user['Processing_value'] > $fx_max) {
            sendmessage($from_id, strtr($textbotlang['users']['Balance']['depositRange'], [
                '{mainbalance}' => number_format($fx_min),
                '{maxbalance}' => number_format($fx_max),
            ]), null, 'HTML');
            return;
        }
        deletemessage($from_id, $message_id);
        sendmessage($from_id, $textbotlang['users']['Balance']['linkpayments'], $keyboard, 'HTML');
        $fx_order = bin2hex(random_bytes(5));
        $fx_pay = createPayFrenzyEx($user['Processing_value'], $fx_order);
        if (empty($fx_pay['pay_url'])) {
            sendmessage($from_id, $textbotlang['users']['Balance']['errorLinkPayment'], $keyboard, 'HTML');
            step('home', $from_id);
            error_log('FrenzyEx: no pay_url — ' . json_encode($fx_pay));
            return;
        }
        // Written only after the link exists. An invoice row for a payment the
        // buyer was never shown is a row that can never be settled.
        $fx_invoice = "{$user['Processing_value_tow']}|{$user['Processing_value_one']}";
        $fx_stmt = $pdo->prepare("INSERT INTO Payment_report (id_user,id_order,time,price,payment_Status,Payment_Method,id_invoice,dec_not_confirmed) VALUES (?,?,?,?,?,?,?,?)");
        $fx_stmt->execute([
            $from_id, $fx_order, date('Y/m/d H:i:s'), $user['Processing_value'],
            "Unpaid", "frenzyex", $fx_invoice, (string) ($fx_pay['request_id'] ?? ''),
        ]);
        sendmessage($from_id, sprintf(
            $textbotlang['users']['Balance']['invoiceCreated2'],
            $fx_order,
            number_format($user['Processing_value'], 0)
        ), json_encode([
            'inline_keyboard' => [[
                ['text' => $textbotlang['users']['Balance']['payments'], 'url' => $fx_pay['pay_url']],
            ]]
        ]), 'HTML');
        // <<< FRENZYEX GATEWAY
PHPBLOCK;

/**
 * The button. frenzyex_enabled() checks the switch and both credentials, so a
 * half-configured gateway is never offered to a buyer.
 */
$KEYBOARD_BLOCK = <<<'PHPBLOCK'
// >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
require_once __DIR__ . '/frenzyex_lib.php';
if (frenzyex_enabled()) {
    $step_payment['inline_keyboard'][] = [
        ['text' => $textbotlang['textbot']['frenzyEx'] ?? '🌹 پرداخت با FrenzyEx', 'callback_data' => "frenzyex"]
    ];
}
// <<< FRENZYEX GATEWAY
PHPBLOCK;

$TARGETS = [
    'index.php' => [
        'anchor' => "    } elseif (\$datain == \"zarinpal\") {",
        'block' => $INDEX_BLOCK,
    ],
    'keyboard.php' => [
        'anchor' => "if (\$paymentstatussnotverify == \"onverifypay\") {",
        'block' => $KEYBOARD_BLOCK,
    ],
];

/** Drop any previously installed block, markers included. */
function frenzyex_strip($source)
{
    $pattern = '/[ \t]*' . preg_quote(MARK_OPEN, '/') . '.*?' . preg_quote(MARK_CLOSE, '/') . '[ \t]*\r?\n?/s';
    return preg_replace($pattern, '', $source);
}

function frenzyex_is_patched($source)
{
    return strpos($source, MARK_OPEN) !== false;
}

/** Reject a patch that would not parse, before it can reach the live file. */
function frenzyex_lints($contents, $hint)
{
    $tmp = sys_get_temp_dir() . '/frenzyex_lint_' . getmypid() . '_' . $hint;
    file_put_contents($tmp, $contents);
    $php = PHP_BINARY ?: 'php';
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    unlink($tmp);
    return [$code === 0, implode("\n", $out)];
}

if ($cmd === 'status') {
    $any = false;
    foreach ($TARGETS as $file => $_spec) {
        $path = "$dir/$file";
        $patched = is_file($path) && frenzyex_is_patched(file_get_contents($path));
        $any = $any || $patched;
        printf("  %-14s %s\n", $file, $patched ? 'patched' : 'not patched');
    }
    exit($any ? 0 : 2);
}

if ($cmd !== 'apply' && $cmd !== 'remove') {
    fwrite(STDERR, "usage: patcher.php <mirza-dir> apply|remove|status\n");
    exit(1);
}

$stamp = date('Ymd_His');
$backupDir = "$dir/frenzyex_backup/$stamp";

// Plan every file before writing any of them: a half-applied patch across two
// files leaves the bot in a state neither version knows how to run.
$planned = [];
foreach ($TARGETS as $file => $spec) {
    $path = "$dir/$file";
    if (!is_file($path)) {
        fwrite(STDERR, "missing: $file\n");
        exit(1);
    }

    $original = file_get_contents($path);
    $updated = frenzyex_strip($original);

    if ($cmd === 'apply') {
        $anchor = $spec['anchor'];
        $count = substr_count($updated, $anchor);
        if ($count !== 1) {
            fwrite(STDERR, "cannot patch $file: expected exactly one anchor, found $count.\n");
            fwrite(STDERR, "This Mirza version is not supported by this installer.\n");
            exit(3);
        }
        $updated = str_replace($anchor, $spec['block'] . "\n" . $anchor, $updated);
    }

    if ($updated === $original) {
        $planned[$file] = null; // nothing to do
        continue;
    }

    list($ok, $error) = frenzyex_lints($updated, str_replace('.php', '', $file) . '.php');
    if (!$ok) {
        fwrite(STDERR, "refusing to write $file — the result would not parse:\n$error\n");
        exit(4);
    }

    $planned[$file] = $updated;
}

$changed = 0;
foreach ($planned as $file => $contents) {
    if ($contents === null) {
        continue;
    }
    $path = "$dir/$file";

    if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
        fwrite(STDERR, "could not create the backup directory\n");
        exit(1);
    }
    copy($path, "$backupDir/$file");

    // Preserve whatever the web server expects to own and read.
    $perms = fileperms($path) & 0777;
    $owner = fileowner($path);
    $group = filegroup($path);

    file_put_contents($path, $contents);
    @chmod($path, $perms);
    @chown($path, $owner);
    @chgrp($path, $group);

    echo "  $file " . ($cmd === 'apply' ? 'patched' : 'restored') . "\n";
    $changed++;
}

if ($changed === 0) {
    echo "  already up to date\n";
} else {
    echo "  backup: $backupDir\n";
}
exit(0);

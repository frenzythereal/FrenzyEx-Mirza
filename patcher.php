<?php
/**
 * Adds (or removes) the FrenzyEx edits inside the bot's own source.
 *
 * Four files are touched: index.php for the buyer's branch, keyboard.php for
 * the buyer's button, admin.php for the Financial-menu and statistics rows,
 * and cronbot/payment_expire.php for the expired-payment gateway label.
 * Function and language files remain untouched.
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
            // 0.4 moved these two strings; 0.3 keeps them elsewhere. Both are
            // read, so one patch serves every supported version.
            $fx_range_tpl = $textbotlang['users']['Balance']['depositRange']
                ?? $textbotlang['extracted']['index_php']['depositAmountRange']
                ?? 'مبلغ باید بین {mainbalance} تا {maxbalance} تومان باشد.';
            sendmessage($from_id, strtr($fx_range_tpl, [
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
        $fx_created_tpl = $textbotlang['users']['Balance']['invoiceCreated2']
            ?? $textbotlang['hardcoded']['paymentInvoiceCreated2']
            ?? "شماره سفارش: %s\nمبلغ: %s تومان";
        sendmessage($from_id, sprintf(
            $fx_created_tpl,
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

/** Show the gateway in Mirza's administrator Financial menu. This row is
 * inserted in both copies of the menu (initial open and post-toggle redraw). */
$ADMIN_MENU_BLOCK = <<<'PHPBLOCK'
            // >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
            [
                ['text' => $textbotlang['keyboard']['settings'], 'callback_data' => "frenzyexsetting"],
                [
                    'text' => in_array(
                        strtolower(trim((string) getPaySettingValue('statusfrenzyex', '0'))),
                        ['1', 'onfrenzyex'],
                        true
                    )
                        ? $textbotlang['Admin']['Status']['statuson']
                        : $textbotlang['Admin']['Status']['statusoff'],
                    // Always emit the canonical 0/1 value. The compatibility
                    // reader above still understands stale on/off string rows.
                    'callback_data' => "editpayment-frenzyex-" . (
                        in_array(
                            strtolower(trim((string) getPaySettingValue('statusfrenzyex', '0'))),
                            ['1', 'onfrenzyex'],
                            true
                        ) ? '1' : '0'
                    ),
                ],
                ['text' => "🌹 FrenzyEx", 'callback_data' => "frenzyex"],
            ],
            // <<< FRENZYEX GATEWAY
PHPBLOCK;

/** Handle the Financial-menu on/off button. Refuse to enable a gateway that
 * cannot create and authenticate payments. */
$ADMIN_TOGGLE_BLOCK = <<<'PHPBLOCK'
    // >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
    } elseif ($type == "frenzyex") {
        $fx_api_ready = !in_array(trim((string) getPaySettingValue('apifrenzyex', '')), ['', '0'], true);
        $fx_secret_ready = !in_array(trim((string) getPaySettingValue('secretfrenzyex', '')), ['', '0'], true);

        // Mirza-compatible canonical status is 0/1. Accept stale
        // onfrenzyex/offfrenzyex callback payloads from old Telegram messages.
        $fx_current_on = in_array(
            strtolower(trim((string) $value)),
            ['1', 'onfrenzyex'],
            true
        );

        if (!$fx_current_on && (!$fx_api_ready || !$fx_secret_ready)) {
            update("PaySetting", "ValuePay", "0", "NamePay", "statusfrenzyex");
            sendmessage(
                $from_id,
                "ابتدا کلید API و کلید امضای IPN فرنزی‌اکس را با گزینه ۲ نصب‌کننده ثبت کنید.",
                $backadmin,
                'HTML'
            );
        } else {
            $valuenew = $fx_current_on ? "0" : "1";
            update("PaySetting", "ValuePay", $valuenew, "NamePay", "statusfrenzyex");
        }
    // <<< FRENZYEX GATEWAY
PHPBLOCK;

/** The gear button intentionally reports credential presence without exposing
 * either secret in Telegram. Keys are still written by installer option 2. */
$ADMIN_SETTINGS_BLOCK = <<<'PHPBLOCK'
// >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
} elseif ($datain == "frenzyexsetting" && $adminrulecheck['rule'] == "administrator") {
    $fx_api_ready = !in_array(trim((string) getPaySettingValue('apifrenzyex', '')), ['', '0'], true);
    $fx_secret_ready = !in_array(trim((string) getPaySettingValue('secretfrenzyex', '')), ['', '0'], true);
    $fx_status_value = strtolower(trim((string) getPaySettingValue('statusfrenzyex', '0')));
    $fx_status = in_array($fx_status_value, ['1', 'onfrenzyex'], true) ? 'روشن ✅' : 'خاموش ❌';
    $fx_domain = trim((string) ($domainhosts ?? ''));
    $fx_callback = $fx_domain === '' ? 'تنظیم نشده' : 'https://' . $fx_domain . '/payment/frenzyex.php';
    $fx_settings_text = "🌹 <b>تنظیمات FrenzyEx</b>\n\n"
        . "وضعیت: {$fx_status}\n"
        . "کلید API: " . ($fx_api_ready ? 'ثبت شده ✅' : 'ثبت نشده ❌') . "\n"
        . "کلید امضای IPN: " . ($fx_secret_ready ? 'ثبت شده ✅' : 'ثبت نشده ❌') . "\n"
        . "حداقل شارژ: " . number_format((float) getPaySettingValue('minbalancefrenzyex', '20000')) . " تومان\n"
        . "حداکثر شارژ: " . number_format((float) getPaySettingValue('maxbalancefrenzyex', '1000000')) . " تومان\n"
        . "Callback: <code>{$fx_callback}</code>\n\n"
        . "برای ثبت یا تغییر کلیدها، گزینه ۲ نصب‌کننده FrenzyEx را اجرا کنید.";
    sendmessage($from_id, $fx_settings_text, $backadmin, 'HTML');
// <<< FRENZYEX GATEWAY
PHPBLOCK;

/** Make Mirza's aggregate payment statistics recognize the stored method. */
$ADMIN_REPORT_MAP_BLOCK = <<<'PHPBLOCK'
                // >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
                'frenzyex' => '🌹 FrenzyEx',
                // <<< FRENZYEX GATEWAY
PHPBLOCK;

/** Give expired FrenzyEx invoices a readable gateway name instead of an
 * undefined-array-key warning. */
$EXPIRE_REPORT_MAP_BLOCK = <<<'PHPBLOCK'
        // >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
        'frenzyex' => '🌹 FrenzyEx',
        // <<< FRENZYEX GATEWAY
PHPBLOCK;

$TARGETS = [
    'index.php' => [
        'patches' => [[
            'anchor' => "    } elseif (\$datain == \"zarinpal\") {",
            'block' => $INDEX_BLOCK,
            'expected' => 1,
        ]],
    ],
    'keyboard.php' => [
        'patches' => [[
            'anchor' => "if (\$paymentstatussnotverify == \"onverifypay\") {",
            'block' => $KEYBOARD_BLOCK,
            'expected' => 1,
        ]],
    ],
    'admin.php' => [
        'patches' => [
            [
                'anchor' => "} elseif (\$text == \$textbotlang['keyboard']['financial'] && \$adminrulecheck['rule'] == \"administrator\") {",
                'block' => $ADMIN_SETTINGS_BLOCK,
                'expected' => 1,
            ],
            [
                'anchor' => "    } elseif (\$type == \"nowpayment\") {",
                'block' => $ADMIN_TOGGLE_BLOCK,
                'expected' => 1,
            ],
            [
                // Anchor the whole Star Telegram row, not its first button.
                // Inserting a full row before only the first button creates an invalid
                // nested Telegram keyboard row even though PHP syntax still lints.
                'anchor' => "            [\n                ['text' => \$textbotlang['keyboard']['settings'], 'callback_data' => \"startelegram\"],",
                'block' => $ADMIN_MENU_BLOCK,
                'expected' => 2,
            ],
            [
                'anchor' => "                'Star Telegram' => \$textbotlang['textbot']['starTelegram']",
                'block' => $ADMIN_REPORT_MAP_BLOCK,
                'expected' => 1,
            ],
        ],
    ],
    'cronbot/payment_expire.php' => [
        'patches' => [[
            'anchor' => "        'Star Telegram' => \$textbotlang['textbot']['starTelegram'],",
            'block' => $EXPIRE_REPORT_MAP_BLOCK,
            'expected' => 1,
        ]],
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
    $safeHint = preg_replace('/[^A-Za-z0-9_.-]/', '_', $hint);
    $tmp = sys_get_temp_dir() . '/frenzyex_lint_' . getmypid() . '_' . $safeHint;
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

// Plan every file before writing any of them: a half-applied patch across the
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
        foreach ($spec['patches'] as $patch) {
            $anchor = $patch['anchor'];
            $expected = $patch['expected'] ?? 1;
            $count = substr_count($updated, $anchor);
            if ($count !== $expected) {
                fwrite(STDERR, "cannot patch $file: expected $expected anchor(s), found $count.\n");
                fwrite(STDERR, "This Mirza version is not supported by this installer.\n");
                exit(3);
            }
            $updated = str_replace($anchor, $patch['block'] . "\n" . $anchor, $updated);
        }
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
    $backupPath = "$backupDir/$file";
    $backupParent = dirname($backupPath);
    if (!is_dir($backupParent) && !mkdir($backupParent, 0750, true) && !is_dir($backupParent)) {
        fwrite(STDERR, "could not create backup directory for $file\n");
        exit(1);
    }
    copy($path, $backupPath);

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

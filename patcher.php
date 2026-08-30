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
 * FrenzyEx management keyboard. It mirrors Mirza's gateway-management layout,
 * but intentionally has no wallet-address row because FrenzyEx does not need one.
 */
$FRENZY_MANAGE_KEYBOARD_BLOCK = <<<'PHPBLOCK'
// >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
$FrenzyExManage = json_encode([
    'keyboard' => [
        [
            ['text' => '🔑 کلید API فرنزی‌اکس'],
            ['text' => '🖋 کلید امضای IPN فرنزی‌اکس'],
        ],
        [
            ['text' => '⬇️ حداقل مبلغ فرنزی‌اکس'],
            ['text' => '⬆️ حداکثر مبلغ فرنزی‌اکس'],
        ],
        [
            ['text' => '🎁 کش‌بک فرنزی‌اکس'],
            ['text' => '📚 آموزش فرنزی‌اکس'],
        ],
        [
            ['text' => $textbotlang['Admin']['backAdminBtn']],
            ['text' => $textbotlang['Admin']['backMenuBtn']],
        ],
    ],
    'resize_keyboard' => true,
]);
// <<< FRENZYEX GATEWAY
PHPBLOCK;

/** The buyer payment button itself. */
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

/**
 * Put the three requested methods in an exact order for buyers:
 *   1) FrenzyEx, 2) Tronado, 3) NowPayments.
 * Other enabled payment methods keep their relative order afterwards.
 */
$PAYMENT_ORDER_BLOCK = <<<'PHPBLOCK'
// >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
if (!empty($step_payment['inline_keyboard']) && is_array($step_payment['inline_keyboard'])) {
    $fx_priority = ['frenzyex' => 0, 'iranpay2' => 1, 'nowpayment' => 2];
    $fx_selected = [];
    $fx_remaining = [];

    foreach ($step_payment['inline_keyboard'] as $fx_row) {
        $fx_callback = '';
        if (is_array($fx_row) && isset($fx_row[0]) && is_array($fx_row[0])) {
            $fx_callback = (string) ($fx_row[0]['callback_data'] ?? '');
        }
        if (array_key_exists($fx_callback, $fx_priority)) {
            $fx_selected[$fx_callback] = $fx_row;
        } else {
            $fx_remaining[] = $fx_row;
        }
    }

    $fx_ordered = [];
    foreach (['frenzyex', 'iranpay2', 'nowpayment'] as $fx_callback) {
        if (isset($fx_selected[$fx_callback])) {
            $fx_ordered[] = $fx_selected[$fx_callback];
        }
    }
    $step_payment['inline_keyboard'] = array_merge($fx_ordered, $fx_remaining);
}
// <<< FRENZYEX GATEWAY
PHPBLOCK;

/** Show the gateway in Mirza's administrator Financial menu. */
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

/** Handle the Financial-menu on/off button. */
$ADMIN_TOGGLE_BLOCK = <<<'PHPBLOCK'
    // >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
    } elseif ($type == "frenzyex") {
        $fx_api_ready = !in_array(trim((string) getPaySettingValue('apifrenzyex', '')), ['', '0'], true);
        $fx_secret_ready = !in_array(trim((string) getPaySettingValue('secretfrenzyex', '')), ['', '0'], true);
        $fx_current_on = in_array(strtolower(trim((string) $value)), ['1', 'onfrenzyex'], true);

        if (!$fx_current_on && (!$fx_api_ready || !$fx_secret_ready)) {
            update("PaySetting", "ValuePay", "0", "NamePay", "statusfrenzyex");
            sendmessage($from_id, "ابتدا کلید API و کلید امضای IPN فرنزی‌اکس را ثبت کنید.", $backadmin, 'HTML');
        } else {
            $valuenew = $fx_current_on ? "0" : "1";
            update("PaySetting", "ValuePay", $valuenew, "NamePay", "statusfrenzyex");
        }
    // <<< FRENZYEX GATEWAY
PHPBLOCK;

/**
 * Full native-looking FrenzyEx settings flow. It intentionally uses hardcoded
 * Persian labels so no Mirza language files need to be modified.
 */
$ADMIN_SETTINGS_BLOCK = <<<'PHPBLOCK'
// >>> FRENZYEX GATEWAY — installed by install.sh, do not edit inside these markers
} elseif ($datain == "frenzyexsetting" && $adminrulecheck['rule'] == "administrator") {
    sendmessage($from_id, 'یک گزینه از تنظیمات FrenzyEx را انتخاب کنید:', $FrenzyExManage, 'HTML');
    step('home', $from_id);

} elseif ($text == '🔑 کلید API فرنزی‌اکس' && $adminrulecheck['rule'] == "administrator") {
    $fx_current = trim((string) getPaySettingValue('apifrenzyex', '0'));
    $fx_hint = ($fx_current === '' || $fx_current === '0') ? 'ثبت نشده' : 'ثبت شده ✅';
    sendmessage($from_id, "کلید API جدید FrenzyEx را ارسال کنید.\nوضعیت فعلی: {$fx_hint}", $backadmin, 'HTML');
    step('frenzyex_set_api', $from_id);
} elseif ($user['step'] == 'frenzyex_set_api') {
    $fx_value = trim((string) $text);
    if ($fx_value === '') {
        sendmessage($from_id, 'کلید API نمی‌تواند خالی باشد.', $backadmin, 'HTML');
        return;
    }
    update("PaySetting", "ValuePay", $fx_value, "NamePay", "apifrenzyex");
    step('home', $from_id);
    sendmessage($from_id, 'کلید API FrenzyEx ذخیره شد ✅', $FrenzyExManage, 'HTML');

} elseif ($text == '🖋 کلید امضای IPN فرنزی‌اکس' && $adminrulecheck['rule'] == "administrator") {
    $fx_current = trim((string) getPaySettingValue('secretfrenzyex', '0'));
    $fx_hint = ($fx_current === '' || $fx_current === '0') ? 'ثبت نشده' : 'ثبت شده ✅';
    sendmessage($from_id, "کلید امضای IPN جدید FrenzyEx را ارسال کنید.\nوضعیت فعلی: {$fx_hint}", $backadmin, 'HTML');
    step('frenzyex_set_secret', $from_id);
} elseif ($user['step'] == 'frenzyex_set_secret') {
    $fx_value = trim((string) $text);
    if ($fx_value === '') {
        sendmessage($from_id, 'کلید امضای IPN نمی‌تواند خالی باشد.', $backadmin, 'HTML');
        return;
    }
    update("PaySetting", "ValuePay", $fx_value, "NamePay", "secretfrenzyex");
    step('home', $from_id);
    sendmessage($from_id, 'کلید امضای IPN FrenzyEx ذخیره شد ✅', $FrenzyExManage, 'HTML');

} elseif ($text == '⬇️ حداقل مبلغ فرنزی‌اکس' && $adminrulecheck['rule'] == "administrator") {
    $fx_current = (int) getPaySettingValue('minbalancefrenzyex', '20000');
    sendmessage($from_id, 'حداقل مبلغ شارژ FrenzyEx را به تومان ارسال کنید. مقدار فعلی: ' . number_format($fx_current), $backadmin, 'HTML');
    step('frenzyex_set_min', $from_id);
} elseif ($user['step'] == 'frenzyex_set_min') {
    $fx_value = str_replace([',', ' '], '', (string) $text);
    if (!ctype_digit($fx_value) || (int) $fx_value <= 0) {
        sendmessage($from_id, 'فقط یک مبلغ معتبر و بزرگ‌تر از صفر ارسال کنید.', $backadmin, 'HTML');
        return;
    }
    $fx_max = (int) getPaySettingValue('maxbalancefrenzyex', '1000000');
    if ((int) $fx_value > $fx_max) {
        sendmessage($from_id, 'حداقل مبلغ نمی‌تواند از حداکثر مبلغ بیشتر باشد.', $backadmin, 'HTML');
        return;
    }
    update("PaySetting", "ValuePay", (string) ((int) $fx_value), "NamePay", "minbalancefrenzyex");
    step('home', $from_id);
    sendmessage($from_id, 'حداقل مبلغ FrenzyEx ذخیره شد ✅', $FrenzyExManage, 'HTML');

} elseif ($text == '⬆️ حداکثر مبلغ فرنزی‌اکس' && $adminrulecheck['rule'] == "administrator") {
    $fx_current = (int) getPaySettingValue('maxbalancefrenzyex', '1000000');
    sendmessage($from_id, 'حداکثر مبلغ شارژ FrenzyEx را به تومان ارسال کنید. مقدار فعلی: ' . number_format($fx_current), $backadmin, 'HTML');
    step('frenzyex_set_max', $from_id);
} elseif ($user['step'] == 'frenzyex_set_max') {
    $fx_value = str_replace([',', ' '], '', (string) $text);
    if (!ctype_digit($fx_value) || (int) $fx_value <= 0) {
        sendmessage($from_id, 'فقط یک مبلغ معتبر و بزرگ‌تر از صفر ارسال کنید.', $backadmin, 'HTML');
        return;
    }
    $fx_min = (int) getPaySettingValue('minbalancefrenzyex', '20000');
    if ((int) $fx_value < $fx_min) {
        sendmessage($from_id, 'حداکثر مبلغ نمی‌تواند از حداقل مبلغ کمتر باشد.', $backadmin, 'HTML');
        return;
    }
    update("PaySetting", "ValuePay", (string) ((int) $fx_value), "NamePay", "maxbalancefrenzyex");
    step('home', $from_id);
    sendmessage($from_id, 'حداکثر مبلغ FrenzyEx ذخیره شد ✅', $FrenzyExManage, 'HTML');

} elseif ($text == '🎁 کش‌بک فرنزی‌اکس' && $adminrulecheck['rule'] == "administrator") {
    $fx_current = (int) getPaySettingValue('chashbackfrenzyex', '0');
    sendmessage($from_id, "درصد کش‌بک FrenzyEx را از 0 تا 100 ارسال کنید.\nمقدار فعلی: {$fx_current}%", $backadmin, 'HTML');
    step('frenzyex_set_cashback', $from_id);
} elseif ($user['step'] == 'frenzyex_set_cashback') {
    $fx_value = trim((string) $text);
    if (!ctype_digit($fx_value) || (int) $fx_value < 0 || (int) $fx_value > 100) {
        sendmessage($from_id, 'درصد کش‌بک باید عددی بین 0 تا 100 باشد.', $backadmin, 'HTML');
        return;
    }
    update("PaySetting", "ValuePay", (string) ((int) $fx_value), "NamePay", "chashbackfrenzyex");
    step('home', $from_id);
    sendmessage($from_id, 'کش‌بک FrenzyEx ذخیره شد ✅', $FrenzyExManage, 'HTML');

} elseif ($text == '📚 آموزش فرنزی‌اکس' && $adminrulecheck['rule'] == "administrator") {
    sendmessage($from_id, "متن، عکس یا ویدیوی آموزش FrenzyEx را ارسال کنید.\nبرای غیرفعال کردن آموزش عدد 2 را بفرستید.", $backadmin, 'HTML');
    step('frenzyex_set_help', $from_id);
} elseif ($user['step'] == 'frenzyex_set_help') {
    if ($text) {
        if ((string) intval($text) === '2' && trim((string) $text) === '2') {
            update("PaySetting", "ValuePay", "0", "NamePay", "helpfrenzyex");
        } else {
            $fx_data = json_encode(['type' => 'text', 'text' => $text], JSON_UNESCAPED_UNICODE);
            update("PaySetting", "ValuePay", $fx_data, "NamePay", "helpfrenzyex");
        }
    } elseif ($photo) {
        $fx_data = json_encode(['type' => 'photo', 'text' => $caption, 'photoid' => $photoid], JSON_UNESCAPED_UNICODE);
        update("PaySetting", "ValuePay", $fx_data, "NamePay", "helpfrenzyex");
    } elseif ($video) {
        $fx_data = json_encode(['type' => 'video', 'text' => $caption, 'videoid' => $videoid], JSON_UNESCAPED_UNICODE);
        update("PaySetting", "ValuePay", $fx_data, "NamePay", "helpfrenzyex");
    } else {
        sendmessage($from_id, 'محتوای آموزش معتبر نیست.', $backadmin, 'HTML');
        return;
    }
    step('home', $from_id);
    sendmessage($from_id, 'آموزش FrenzyEx ذخیره شد ✅', $FrenzyExManage, 'HTML');
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
        'patches' => [
            [
                'anchor' => "\$setting_panel = json_encode([",
                'block' => $FRENZY_MANAGE_KEYBOARD_BLOCK,
                'expected' => 1,
            ],
            [
                'anchor' => "if (\$paymentstatussnotverify == \"onverifypay\") {",
                'block' => $KEYBOARD_BLOCK,
                'expected' => 1,
            ],
            [
                'anchor' => "\$step_payment['inline_keyboard'][] = [\n    ['text' => \$textbotlang['keyboard']['closeList'], 'callback_data' => \"colselist\"]",
                'block' => $PAYMENT_ORDER_BLOCK,
                'expected' => 1,
            ],
        ],
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

<?php
/**
 * FrenzyEx settings writer — the reason install.sh does not patch admin.php.
 *
 * admin.php is thousands of lines of chained elseif conversation steps. Adding
 * a settings panel to it from the outside is the single most fragile edit in
 * this integration, and it would break on any upstream reshuffle. Writing the
 * rows straight into PaySetting reaches the same place by a door that does not
 * move: every gateway reads its configuration from that table.
 *
 * Usage:
 *   php settings.php <mirza-dir> show
 *   php settings.php <mirza-dir> init
 *   php settings.php <mirza-dir> set <name> <value>
 *
 * Run by install.sh; not installed into the bot.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$dir = $argv[1] ?? '';
$cmd = $argv[2] ?? 'show';

if ($dir === '' || !is_file($dir . '/config.php')) {
    fwrite(STDERR, "config.php not found in: $dir\n");
    exit(1);
}

// config.php defines $pdo and prints nothing on success — but it does `die()`
// with a message when the database is unreachable, and that message must not
// end up mixed into this tool's output.
ob_start();
require $dir . '/config.php';
ob_end_clean();

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "could not open the bot's database\n");
    exit(1);
}

$KEYS = [
    'apifrenzyex' => '0',
    'secretfrenzyex' => '0',
    'endpointfrenzyex' => 'https://frenzy.fastsnap.info',
    'statusfrenzyex' => '0',
    'minbalancefrenzyex' => '20000',
    'maxbalancefrenzyex' => '1000000',
    'chashbackfrenzyex' => '0',
    'helpfrenzyex' => '2',
];

function frenzyex_mask($value)
{
    $value = (string) $value;
    if ($value === '' || $value === '0') {
        return '(not set)';
    }
    if (strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }
    return substr($value, 0, 4) . str_repeat('*', 6) . substr($value, -4);
}

function frenzyex_normalize_status($value)
{
    $value = strtolower(trim((string) $value));
    return ($value === '1' || $value === 'onfrenzyex') ? '1' : '0';
}

try {
    if ($cmd === 'init') {
        // INSERT IGNORE, so re-running never overwrites what an admin set.
        $stmt = $pdo->prepare('INSERT IGNORE INTO PaySetting (NamePay, ValuePay) VALUES (:name, :value)');
        foreach ($KEYS as $name => $value) {
            $stmt->execute([':name' => $name, ':value' => $value]);
        }

        // Compatibility repair: a short-lived package used
        // onfrenzyex/offfrenzyex. Mirza Financial-menu integrations commonly
        // index numeric status switches by 0 and 1, so persist only that form.
        $normalize = $pdo->prepare(
            "UPDATE PaySetting
             SET ValuePay = CASE
                 WHEN LOWER(TRIM(ValuePay)) IN ('1', 'onfrenzyex') THEN '1'
                 ELSE '0'
             END
             WHERE NamePay = 'statusfrenzyex'"
        );
        $normalize->execute();

        echo "settings rows ready\n";
        exit(0);
    }

    if ($cmd === 'set') {
        $name = $argv[3] ?? '';
        $value = $argv[4] ?? '';
        if (!array_key_exists($name, $KEYS)) {
            fwrite(STDERR, "unknown setting: $name\n");
            exit(1);
        }

        if ($name === 'statusfrenzyex') {
            $value = frenzyex_normalize_status($value);
        }

        // UPDATE alone would silently match zero rows on a bot that predates
        // this gateway — the failure this whole file exists to avoid.
        $stmt = $pdo->prepare(
            'INSERT INTO PaySetting (NamePay, ValuePay) VALUES (:name, :value)
             ON DUPLICATE KEY UPDATE ValuePay = VALUES(ValuePay)'
        );
        $stmt->execute([':name' => $name, ':value' => $value]);

        // NamePay may carry no unique index on older installs, in which case
        // the upsert above inserts a second row instead of updating. Verify.
        $check = $pdo->prepare('SELECT COUNT(*) FROM PaySetting WHERE NamePay = :name');
        $check->execute([':name' => $name]);
        if ((int) $check->fetchColumn() > 1) {
            $fix = $pdo->prepare('UPDATE PaySetting SET ValuePay = :value WHERE NamePay = :name');
            $fix->execute([':name' => $name, ':value' => $value]);
        }

        echo "$name saved\n";
        exit(0);
    }

    $stmt = $pdo->prepare('SELECT NamePay, ValuePay FROM PaySetting WHERE NamePay LIKE :like');
    $stmt->execute([':like' => '%frenzyex%']);
    $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!$rows) {
        echo "no FrenzyEx settings rows yet — run the installer's option 1 first\n";
        exit(2);
    }

    $secret = ['apifrenzyex' => true, 'secretfrenzyex' => true];
    foreach ($KEYS as $name => $_default) {
        $value = $rows[$name] ?? '(missing)';
        if ($name === 'statusfrenzyex' && $value !== '(missing)') {
            $value = frenzyex_normalize_status($value);
        }
        printf("  %-20s %s\n", $name, isset($secret[$name]) ? frenzyex_mask($value) : $value);
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'database error: ' . $e->getMessage() . "\n");
    exit(1);
}

<?php
/**
 * Guards against version_compare() ordering inversions across a scheme change.
 *
 * WordPress core's own plugin-update mechanism (and any Freemius/WP.org
 * update-checker) decides whether to offer an update by running
 * version_compare($installed, $stable_tag, '<'). If a past version-scheme
 * change (e.g. semver -> CalVer, or a 4-digit-year -> 2-digit-year CalVer
 * format) ever produces a segment that PHP's numeric comparison ranks out
 * of chronological order, sites on an old version silently stop being
 * offered updates. See XNT-148/XNT-147: this plugin's own 2026.09.08 ->
 * 26.9.19.0 jump inverts under version_compare(), since 2026 > 26 numerically.
 *
 * That inversion is real and cannot be undone — released version strings are
 * history. XNT-148 accepted it as a documented landmine rather than an
 * incident, on the explicit grounds that this plugin has no update channel:
 * no Update URI header, no update-checker library, no WordPress.org presence
 * (re-confirmed 2026-09-23 under XNT-171). So pre-CalVer versions are skipped
 * in the ordering pass below, and the moment an update channel does appear
 * they stop being skipped — because from that point the landmine is live, and
 * the release needs a custom comparator or a version floor before it ships.
 *
 * It also enforces three release hygiene rules. Two come from XNT-156: the
 * three version strings (plugin header, XENIOS_KB_BOT_VERSION, readme Stable
 * tag) must agree, and the current Stable tag must have its own changelog
 * entry. A fix merged without a version bump is never offered to any installed
 * site, so an unbumped release is silently a non-release.
 *
 * The third comes from XNT-171, which happened because those two are not
 * enough: both XNT-156 and XNT-171 were consistently-versioned trees with a
 * changelog entry for the current tag, and shipped code merged after it. So
 * this also fails when shipped files have changed since the Stable tag last
 * moved. That is the check that actually catches the failure both tickets are
 * about. It needs git history, and is skipped outside a checkout (the release
 * archive has no .git).
 *
 * Run before every release: php bin/check-version-ordering.php
 */

$readme = file_get_contents(__DIR__ . '/../readme.txt');
if ($readme === false) {
    fwrite(STDERR, "Could not read readme.txt\n");
    exit(1);
}

preg_match('/^Stable tag:\s*(\S+)/mi', $readme, $stableMatch);
if (empty($stableMatch[1])) {
    fwrite(STDERR, "Could not find 'Stable tag' in readme.txt\n");
    exit(1);
}
$current = trim($stableMatch[1]);

preg_match_all('/^= ([0-9][^\s=]*) =$/m', $readme, $changelogMatches);
$history = array_unique($changelogMatches[1]);

if (empty($history)) {
    fwrite(STDERR, "Could not find any changelog version headers in readme.txt\n");
    exit(1);
}

$plugin = file_get_contents(__DIR__ . '/../xenios-kb-bot.php');
if ($plugin === false) {
    fwrite(STDERR, "Could not read xenios-kb-bot.php\n");
    exit(1);
}

preg_match('/^\s*\*\s*Version:\s*(\S+)/mi', $plugin, $headerMatch);
if (empty($headerMatch[1])) {
    fwrite(STDERR, "Could not find the 'Version:' plugin header in xenios-kb-bot.php\n");
    exit(1);
}
$headerVersion = trim($headerMatch[1]);

preg_match("/define\(\s*'XENIOS_KB_BOT_VERSION'\s*,\s*'([^']+)'\s*\)/", $plugin, $constMatch);
if (empty($constMatch[1])) {
    fwrite(STDERR, "Could not find the XENIOS_KB_BOT_VERSION constant in xenios-kb-bot.php\n");
    exit(1);
}
$constVersion = trim($constMatch[1]);

if ($headerVersion !== $current || $constVersion !== $current) {
    fwrite(STDERR, "Version strings disagree:\n");
    fwrite(STDERR, "  - readme.txt 'Stable tag':            $current\n");
    fwrite(STDERR, "  - xenios-kb-bot.php 'Version:':      $headerVersion\n");
    fwrite(STDERR, "  - XENIOS_KB_BOT_VERSION:             $constVersion\n");
    fwrite(STDERR, "\nAll three must match the version being released.\n");
    exit(1);
}

if (!in_array($current, $history, true)) {
    fwrite(STDERR, "No changelog entry for the current Stable tag ($current).\n");
    fwrite(STDERR, "\nEvery release needs its own '= $current =' section in readme.txt's\n");
    fwrite(STDERR, "changelog. A missing entry usually means the release was never bumped,\n");
    fwrite(STDERR, "in which case version_compare() offers the update to nobody.\n");
    exit(1);
}

/**
 * True for a version string from before the YY.M.D.ID conversion, identified
 * by a 4-digit leading segment (a full year). Those are the only strings that
 * can invert against the current scheme; 1.x semver orders correctly.
 */
$isPreCalVer = static function (string $version): bool {
    return (bool) preg_match('/^\d{4}\./', $version);
};

/**
 * Whether anything in the plugin actually consults version_compare() to offer
 * an update. While nothing does, the pre-CalVer inversion is inert.
 */
$hasUpdateChannel = (bool) preg_match('/^\s*\*\s*Update URI:\s*\S/mi', $plugin);
if (!$hasUpdateChannel) {
    $sources = array_merge(
        glob(__DIR__ . '/../includes/*.php') ?: [],
        glob(__DIR__ . '/../admin/*.php') ?: []
    );
    foreach ($sources as $file) {
        $body = file_get_contents($file);
        if ($body !== false && preg_match('/site_transient_update_plugins|YahnisElsts|plugin_updater/i', $body)) {
            $hasUpdateChannel = true;
            break;
        }
    }
}

$failures  = [];
$landmines = [];
foreach ($history as $old) {
    if ($old === $current) {
        continue;
    }
    if (!version_compare($old, $current, '>')) {
        continue;
    }
    if ($isPreCalVer($old) && !$hasUpdateChannel) {
        $landmines[] = $old;
        continue;
    }
    $failures[] = $old;
}

if ($landmines) {
    echo "NOTE: " . count($landmines) . " pre-CalVer version(s) invert against $current: "
        . implode(', ', $landmines) . "\n";
    echo "      Inert while this plugin has no update channel (XNT-148). Wiring one up\n";
    echo "      without a custom comparator or a version floor makes this an incident,\n";
    echo "      and this guard fails at that point.\n";
}

/**
 * Shipped-code-since-last-bump check. Everything the release archive contains
 * counts; the repo-only files listed in .gitattributes as export-ignore, and
 * this script's own directory, do not.
 */
$repoRoot = dirname(__DIR__);
// A linked worktree's .git is a FILE, not a directory, so is_dir() alone
// made this whole check skip silently and exit 0 there -- the worst
// failure mode for a guard. Verified: before this, a worktree with an
// unbumped shipped change passed. (XNT-161)
if (file_exists($repoRoot . '/.git') && trim((string) shell_exec('command -v git'))) {
    $tagCommit = trim((string) shell_exec(
        'git -C ' . escapeshellarg($repoRoot)
        . ' log -1 --format=%H -S' . escapeshellarg('Stable tag: ' . $current) . ' -- readme.txt 2>/dev/null'
    ));

    if ($tagCommit !== '') {
        $since = trim((string) shell_exec(
            'git -C ' . escapeshellarg($repoRoot)
            . ' log --format=%h\ %s ' . escapeshellarg($tagCommit) . '..HEAD --no-merges --'
            . ' includes admin assets languages xenios-kb-bot.php uninstall.php readme.txt 2>/dev/null'
        ));
        // readme.txt is in that path list so a changelog-only edit counts as a
        // release commit; drop the bump commit itself from the report.
        $lines = array_values(array_filter(
            explode("\n", $since),
            static fn(string $line): bool => trim($line) !== ''
        ));

        if ($lines) {
            fwrite(STDERR, "Shipped files have changed since Stable tag $current was set:\n");
            foreach ($lines as $line) {
                fwrite(STDERR, "  - $line\n");
            }
            fwrite(STDERR, "\nversion_compare() offers an update only when the version string rises,\n");
            fwrite(STDERR, "so merged-but-unbumped work reaches nobody. Bump to the next YY.M.D.ID\n");
            fwrite(STDERR, "version and give it a changelog entry before releasing.\n");
            exit(1);
        }
    }
}

if ($failures) {
    fwrite(STDERR, "Version-ordering inversion detected against current Stable tag ($current):\n");
    foreach ($failures as $old) {
        fwrite(STDERR, "  - version_compare('$old', '$current', '>') === true (should be false)\n");
    }
    fwrite(STDERR, "\nA plain version_compare()-based updater (WordPress core / Freemius / WP.org)\n");
    fwrite(STDERR, "would never offer '$current' as an update to a site still on one of the\n");
    fwrite(STDERR, "versions above. Add a custom comparator or a one-time forced-update bridge\n");
    fwrite(STDERR, "before releasing.\n");
    exit(1);
}

echo "OK: " . (count($history) - count($landmines) - 1) . " comparable historical changelog version(s) "
    . "order correctly before $current.\n";
exit(0);

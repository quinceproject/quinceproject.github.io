<?php
/**
 * Quince
 *
 * @author    Quince Project <quinceproject@proton.me>
 * @copyright Since 2026 Marcin Gajewski
 * @license   https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

declare(strict_types=1);

/*
 * Writes the "modules" and "themes" sections of manifest.json from the latest
 * GitHub release of every repository in the organisation.
 *
 * Usage: GH_TOKEN=... php scripts/build-releases.php <manifest.json> [<organisation>] [<packages.json>]
 *
 * A repository counts when its latest release carries a ZIP named after the
 * repository (quince_search.zip in quince_search). Repositories with the topic
 * "prestashop-theme" go to "themes", the rest to "modules". The package is
 * downloaded once to record its SHA-256, which modules check before they install
 * an update. Other sections of the manifest are left as they are.
 *
 * With a third argument the script also writes a Composer repository: every
 * release ZIP of every repository as a version of "quinceproject/<name>". The
 * ZIP is the same one the back office installs, with the module's own vendor/,
 * because a PrestaShop shop has no composer.json of its own to add packages to.
 */

[$script, $manifestFile, $org, $packagesFile] = array_pad($argv, 4, null);
$org ??= 'quinceproject';

if ($manifestFile === null) {
    fwrite(STDERR, "Usage: php build-releases.php <manifest.json> [<organisation>] [<packages.json>]\n");
    exit(1);
}

$token = (string) getenv('GH_TOKEN');
$modules = [];
$themes = [];
$composer = [];

foreach (api("/orgs/{$org}/repos?per_page=100&type=public", $token) as $repo) {
    $name = (string) $repo['name'];

    if ($name === "{$org}.github.io" || !empty($repo['archived'])) {
        continue;
    }

    $release = api("/repos/{$org}/{$name}/releases/latest", $token, true);

    if ($release === null || !empty($release['draft']) || !empty($release['prerelease'])) {
        continue;
    }

    $asset = null;

    foreach ((array) ($release['assets'] ?? []) as $candidate) {
        if (($candidate['name'] ?? '') === "{$name}.zip") {
            $asset = $candidate;
        }
    }

    if ($asset === null) {
        fwrite(STDERR, "{$name}: latest release has no {$name}.zip, skipped\n");

        continue;
    }

    $package = (string) $asset['browser_download_url'];
    $body = download($package);

    $entry = [
        'repository' => (string) $repo['full_name'],
        'version' => ltrim((string) $release['tag_name'], 'vV'),
        'package' => $package,
        'sha256' => hash('sha256', $body),
        'notes' => (string) $release['html_url'],
    ];

    $isTheme = in_array('prestashop-theme', (array) ($repo['topics'] ?? []), true);

    if ($isTheme) {
        $themes[$name] = $entry;
    } else {
        $modules[$name] = $entry;
    }

    if ($packagesFile !== null) {
        $composer += composerVersions($org, $name, $isTheme, (string) ($repo['license']['spdx_id'] ?? ''), $token);
    }

    echo "{$name} {$entry['version']}\n";
}

// An empty result means the API call went wrong, not that every release vanished.
if ($modules === []) {
    fwrite(STDERR, "No module releases found. Nothing was written.\n");
    exit(1);
}

ksort($modules);
ksort($themes);

$manifest = is_file($manifestFile) ? (array) json_decode((string) file_get_contents($manifestFile), true) : [];
$manifest['modules'] = $modules;
$manifest['themes'] = $themes;

file_put_contents($manifestFile, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo 'Modules: ' . count($modules) . ', themes: ' . count($themes) . "\n";

if ($packagesFile !== null) {
    ksort($composer);
    file_put_contents($packagesFile, json_encode(['packages' => $composer], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo 'Composer packages: ' . count($composer) . "\n";
}

// ---------------------------------------------------------------- helpers

/**
 * Every published release of one repository as Composer versions.
 *
 * The package name follows the repository: quince_search becomes
 * quinceproject/search, the theme repository becomes quinceproject/theme.
 * `installer-name` keeps the folder name PrestaShop expects (modules/quince_search),
 * since composer/installers would otherwise use the package name.
 *
 * @return array<string,array<string,array<string,mixed>>>
 */
function composerVersions(string $org, string $repo, bool $isTheme, string $license, string $token): array
{
    $package = $org . '/' . ($isTheme ? 'theme' : str_replace('_', '-', (string) preg_replace('/^quince_/', '', $repo)));
    $versions = [];

    foreach (api("/repos/{$org}/{$repo}/releases?per_page=100", $token) as $release) {
        if (!empty($release['draft']) || !empty($release['prerelease'])) {
            continue;
        }

        foreach ((array) ($release['assets'] ?? []) as $asset) {
            if (($asset['name'] ?? '') !== "{$repo}.zip") {
                continue;
            }

            $version = ltrim((string) $release['tag_name'], 'vV');
            $versions[$version] = [
                'name' => $package,
                'version' => $version,
                'type' => $isTheme ? 'prestashop-theme' : 'prestashop-module',
                'license' => $license !== '' && $license !== 'NOASSERTION' ? [$license] : [],
                'homepage' => "https://github.com/{$org}/{$repo}",
                'require' => ['composer/installers' => '^2.0'],
                'extra' => ['installer-name' => $repo],
                'dist' => ['type' => 'zip', 'url' => (string) $asset['browser_download_url']],
                'time' => (string) ($release['published_at'] ?? ''),
            ];
        }
    }

    return $versions === [] ? [] : [$package => $versions];
}

/** @return array<mixed>|null */
function api(string $path, string $token, bool $allowMissing = false): ?array
{
    $headers = ['Accept: application/vnd.github+json', 'User-Agent: quince-manifest'];

    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    [$code, $body] = request('https://api.github.com' . $path, $headers);

    if ($code === 404 && $allowMissing) {
        return null;
    }

    if ($code !== 200) {
        fwrite(STDERR, "GitHub API {$path} answered {$code}\n");
        exit(1);
    }

    return (array) json_decode($body, true);
}

function download(string $url): string
{
    [$code, $body] = request($url, ['User-Agent: quince-manifest']);

    if ($code !== 200 || $body === '') {
        fwrite(STDERR, "Download of {$url} answered {$code}\n");
        exit(1);
    }

    return $body;
}

/** @return array{0:int,1:string} */
function request(string $url, array $headers): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, $body === false ? '' : (string) $body];
}

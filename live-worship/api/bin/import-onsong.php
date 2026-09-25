<?php
declare(strict_types=1);

namespace LiveWorship;

require dirname(__DIR__) . '/src/bootstrap.php';

const SOURCE_REPOSITORY = 'https://github.com/mattgraham/worship.git';

function usage(): never
{
    fwrite(STDERR, <<<'TEXT'
Usage:
  php api/bin/import-onsong.php <repo-directory> --member-id=<leader-id> [options]

Options:
  --member-id=N       Existing live_worship.members leader who owns imports (required)
  --commit            Write songs to PostgreSQL. Without this flag, only preview.
  --update            Update songs previously imported from the same source path.
  --allow-duplicates  Import even when title and writer already exist in the catalog.
  --limit=N           Preview/import at most N files.
  --help              Show this help.

The directory should be a local clone of mattgraham/worship. The importer does
not execute repository files and does not download anything itself.
TEXT);
    exit(2);
}

$arguments = array_slice($argv, 1);
$directory = '';
$options = [];
$valueOptions = ['member-id', 'limit'];
for ($index = 0; $index < count($arguments); $index++) {
    $argument = $arguments[$index];
    if ($argument === '--help') { $options['help'] = true; continue; }
    if (in_array($argument, ['--commit', '--update', '--allow-duplicates'], true)) {
        $options[ltrim($argument, '-')] = true;
        continue;
    }
    if (preg_match('/^--(member-id|limit)=(.*)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
        continue;
    }
    if (in_array($argument, ['--member-id', '--limit'], true)) {
        $name = ltrim($argument, '-');
        $options[$name] = $arguments[++$index] ?? '';
        continue;
    }
    if ($directory === '' && !str_starts_with($argument, '--')) $directory = $argument;
}
if (isset($options['help']) || $directory === '') usage();

$root = realpath($directory);
$memberId = (int) ($options['member-id'] ?? 0);
$limit = isset($options['limit']) ? max(1, (int) $options['limit']) : null;
$commit = isset($options['commit']);
$update = isset($options['update']);
$allowDuplicates = isset($options['allow-duplicates']);
if ($root === false || !is_dir($root)) { fwrite(STDERR, "Repository directory not found: {$directory}\n"); exit(1); }
if ($memberId < 1) { fwrite(STDERR, "--member-id must identify an existing leader.\n"); exit(1); }

$files = [];
$iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'onsong') continue;
    $realFile = realpath($file->getPathname());
    if ($realFile === false || !str_starts_with($realFile, $root . DIRECTORY_SEPARATOR)) continue;
    $files[] = $realFile;
}
sort($files, SORT_NATURAL | SORT_FLAG_CASE);
if ($limit !== null) $files = array_slice($files, 0, $limit);

$db = Database::connection();
$member = $db->prepare("SELECT id FROM live_worship.members WHERE id = :id AND role = 'leader' AND active = TRUE");
$member->execute(['id' => $memberId]);
if (!$member->fetchColumn()) { fwrite(STDERR, "Member {$memberId} is not an active Live Worship leader.\n"); exit(1); }

$existingSource = $db->prepare('SELECT id FROM live_worship.songs WHERE import_source = :source AND import_path = :path');
$existingTitle = $db->prepare("SELECT id FROM live_worship.songs WHERE lower(title) = lower(:title) AND coalesce(lower(writer), '') = coalesce(lower(:writer), '') LIMIT 1");
$insert = $db->prepare(
    'INSERT INTO live_worship.songs
        (title, writer, default_key, original_key, lyrics, sections, ocr_text, created_by, import_source, import_path, import_sha256)
     VALUES (:title, :writer, :song_key, :original_key, :lyrics, CAST(:sections AS jsonb), :ocr_text, :created_by, :source, :path, :sha256)'
);
$updateSong = $db->prepare(
    'UPDATE live_worship.songs SET title=:title, writer=:writer, default_key=:song_key, original_key=:original_key,
        lyrics=:lyrics, sections=CAST(:sections AS jsonb), ocr_text=:ocr_text, import_sha256=:sha256, updated_at=now()
     WHERE id=:id'
);

$counts = ['ready' => 0, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
foreach ($files as $file) {
    $relativePath = ltrim(str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root))), '/');
    try {
        $contents = file_get_contents($file);
        if ($contents === false) throw new RuntimeException('file could not be read');
        $song = OnsongParser::parse($contents, $relativePath);
        $hash = hash_file('sha256', $file);
        if ($hash === false) throw new RuntimeException('file hash could not be calculated');

        $existingSource->execute(['source' => SOURCE_REPOSITORY, 'path' => $relativePath]);
        $sourceId = $existingSource->fetchColumn();
        if ($sourceId !== false && !$update) {
            $counts['skipped']++;
            echo "SKIP existing source: {$relativePath}\n";
            continue;
        }
        if ($sourceId === false && !$allowDuplicates) {
            $existingTitle->execute(['title' => $song['title'], 'writer' => $song['writer']]);
            if ($existingTitle->fetchColumn() !== false) {
                $counts['skipped']++;
                echo "SKIP duplicate title: {$song['title']} ({$relativePath})\n";
                continue;
            }
        }

        $counts['ready']++;
        if (!$commit) {
            echo "READY {$song['title']}" . ($song['key'] ? " [{$song['key']}]" : '') . " — {$relativePath}\n";
            continue;
        }

        $params = [
            'title' => $song['title'], 'writer' => $song['writer'], 'song_key' => $song['key'],
            'original_key' => $song['key'], 'lyrics' => $song['lyrics'],
            'sections' => json_encode($song['sections'], JSON_THROW_ON_ERROR), 'ocr_text' => '',
            'created_by' => $memberId, 'source' => SOURCE_REPOSITORY, 'path' => $relativePath, 'sha256' => $hash,
        ];
        if ($sourceId !== false) {
            $updateSong->execute($params + ['id' => (int) $sourceId]);
            $counts['updated']++;
            echo "UPDATE {$song['title']} — {$relativePath}\n";
        } else {
            $insert->execute($params);
            $counts['imported']++;
            echo "IMPORT {$song['title']} — {$relativePath}\n";
        }
    } catch (Throwable $error) {
        $counts['errors']++;
        fwrite(STDERR, "ERROR {$relativePath}: {$error->getMessage()}\n");
    }
}

echo sprintf(
    "Summary: %d files, %d ready, %d imported, %d updated, %d skipped, %d errors%s\n",
    count($files), $counts['ready'], $counts['imported'], $counts['updated'], $counts['skipped'], $counts['errors'],
    $commit ? '' : ' (preview only; pass --commit to write)'
);
exit($counts['errors'] > 0 ? 1 : 0);

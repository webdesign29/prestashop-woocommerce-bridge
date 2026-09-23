<?php
/** In-place update of the PrestaShop module from plugins.inklura.fr. GPL-2.0-or-later. */
namespace WD29\Bridge;

/**
 * Run only on an administrator's click. The archive is downloaded with the licence key,
 * bounded to 20 MiB, checked against the SHA-256 announced by the server, and every entry
 * is validated before a single file of the installed module is replaced. Settings,
 * mappings and history live in the database and are not touched.
 */
final class ModuleUpdater
{
    const MODULE = 'wd29woobridge';
    const MAX_BYTES = 20971520;

    /** @return string new version */
    public static function run(Engine $engine, string $moduleDir, string $installed): string
    {
        if (!class_exists('ZipArchive')) { throw new \RuntimeException('Extension PHP zip absente : mettez à jour depuis le Gestionnaire de modules.'); }
        $headers = $engine->licence()->downloadHeaders();
        if (!$headers) { throw new \RuntimeException('Enregistrez d\'abord votre clé de licence.'); }
        $tmp = tempnam(sys_get_temp_dir(), 'wd29upd');
        $work = $tmp . '.d';
        try {
            [$version, $sha] = self::download(Licence::server() . '/api/licences/v1/download?product=' . Licence::PRODUCT . '&side=prestashop&version=latest', $headers, $tmp);
            if (!version_compare($version, $installed, '>')) { throw new \RuntimeException('Aucune version plus récente que ' . $installed . '.'); }
            if (!hash_equals($sha, hash_file('sha256', $tmp))) { throw new \RuntimeException('Empreinte SHA-256 de l\'archive incorrecte : mise à jour annulée.'); }
            $zip = new \ZipArchive();
            if ($zip->open($tmp) !== true) { throw new \RuntimeException('Archive illisible.'); }
            $prefix = self::MODULE . '/';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (strpos($name, $prefix) !== 0 || strpos($name, '..') !== false || strpos($name, '\\') !== false || preg_match('#(^|/)\.#', substr($name, strlen($prefix)))) {
                    $zip->close();
                    throw new \RuntimeException('Archive refusée : chemin inattendu.');
                }
            }
            if ($zip->locateName($prefix . self::MODULE . '.php') === false) { $zip->close(); throw new \RuntimeException('Archive refusée : module absent.'); }
            if (!mkdir($work, 0700) || !$zip->extractTo($work)) { $zip->close(); throw new \RuntimeException('Extraction impossible.'); }
            $zip->close();
            self::copyTree($work . '/' . self::MODULE, rtrim($moduleDir, '/'));
            return $version;
        } finally {
            @unlink($tmp);
            if (is_dir($work)) { self::removeTree($work); }
        }
    }

    /** @return array{0:string,1:string} [version, sha256] */
    private static function download(string $url, array $headers, string $target): array
    {
        $out = fopen($target, 'wb'); $size = 0; $meta = [];
        $curl = curl_init($url);
        $lines = [];
        foreach ($headers as $k => $v) { $lines[] = $k . ': ' . $v; }
        curl_setopt_array($curl, [
            CURLOPT_HTTPHEADER => $lines, CURLOPT_USERAGENT => 'WD29-Bridge-Updater/1',
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HEADERFUNCTION => function ($h, $line) use (&$meta) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) { $meta[strtolower(trim($parts[0]))] = trim($parts[1]); }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($h, $chunk) use ($out, &$size) {
                $size += strlen($chunk);
                return $size > self::MAX_BYTES ? 0 : fwrite($out, $chunk);
            },
        ]);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        fclose($out);
        if ($ok === false || $status !== 200) {
            throw new \RuntimeException($status === 403 ? 'Téléchargement refusé : licence inactive pour cette boutique.' : 'Téléchargement impossible (HTTP ' . $status . ').');
        }
        $version = (string) ($meta['x-release-version'] ?? '');
        $sha = strtolower((string) ($meta['x-content-sha256'] ?? ''));
        if (!preg_match('/^[0-9][0-9A-Za-z.+-]{0,39}$/D', $version) || !preg_match('/^[a-f0-9]{64}$/D', $sha)) { throw new \RuntimeException('Réponse de téléchargement incomplète.'); }
        return [$version, $sha];
    }

    private static function copyTree(string $from, string $to): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $dest = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
            if ($item->isDir()) { if (!is_dir($dest) && !mkdir($dest, 0755, true)) { throw new \RuntimeException('Dossier non inscriptible : ' . basename($dest)); } continue; }
            if (!copy($item->getPathname(), $dest . '.wd29new') || !rename($dest . '.wd29new', $dest)) { throw new \RuntimeException('Fichier non inscriptible : ' . basename($dest)); }
        }
        if (function_exists('opcache_reset')) { @opcache_reset(); }
    }

    private static function removeTree(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) { $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname()); }
        @rmdir($dir);
    }
}

<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Export\ExporterFactory;
use Ldb\Export\ExporterInterface;
use Ldb\Http\ApiException;
use Ldb\Http\Response;
use Ldb\Sql\ReadOnlyGuard;

final class ExportController extends AbstractController
{
    /** @return array{formats:array,compression:string[]} */
    public function formats(): array
    {
        return [
            'formats' => ExporterFactory::formats(),
            'compression' => $this->compressions(),
        ];
    }

    public function download(): Response
    {
        $db = $this->db();
        $database = $this->request->optString('db');
        $options = $this->request->arr('options');
        $compression = (string) ($options['compression'] ?? $this->request->param('compression', 'none'));
        if (!in_array($compression, $this->compressions(), true)) {
            throw ApiException::badRequest('Unsupported compression: ' . $compression);
        }
        unset($options['compression']);
        $options += ['database' => $database, 'schema' => $this->request->optString('schema')];

        $isQuery = trim((string) ($options['query'] ?? '')) !== '';
        if ($isQuery && $this->isReadOnly()) {
            // Exporting a query runs arbitrary SQL: apply the same guard as sql.execute.
            (new ReadOnlyGuard($db::id()))->assertReadOnly((string) $options['query']);
        }
        $tables = $this->stringList('tables', false);
        if ($tables === [] && $this->request->optString('table') !== null) {
            $tables = [$this->table()];
        }
        if ($tables === [] && !$isQuery) {
            if ($database === null) {
                throw ApiException::badRequest('Missing parameter: db');
            }
            foreach ($db->listTables() as $t) {
                $tables[] = (string) $t['name'];
            }
        }

        try {
            $exporter = ExporterFactory::create($this->request->string('format'), $db, $options);
        } catch (\InvalidArgumentException $e) {
            throw ApiException::badRequest($e->getMessage());
        }

        if ($this->request->string('format') === 'csv' && !$isQuery && count($tables) > 1 && empty($options['multi'])) {
            // Fail as a proper 400 here: once streaming started the exporter's exception becomes a broken 500 download.
            throw ApiException::badRequest('CSV export supports a single table (enable "multi" for several).');
        }

        $name = self::fileBase($database ?? 'export', count($tables) === 1 ? $tables[0] : ($isQuery ? 'query' : null));
        $ext = $exporter->extension();
        $limit = $this->config->int('export_time_limit', 0);
        @set_time_limit($limit);
        $memory = $this->config->get('export_memory_limit');
        if (is_string($memory) && $memory !== '' || is_int($memory) && $memory !== 0) {
            @ini_set('memory_limit', (string) $memory);
        }

        switch ($compression) {
            case 'gzip':
                return Response::download(
                    static function ($out) use ($exporter, $tables): void {
                        self::exportGzip($exporter, $tables, $out);
                    },
                    "$name.$ext.gz",
                    'application/gzip'
                );
            case 'zip':
                return $this->zipDownload($exporter, $tables, "$name.$ext", "$name.zip");
            default:
                return Response::download(
                    static function ($out) use ($exporter, $tables): void {
                        self::runExport($exporter, $tables, $out);
                    },
                    "$name.$ext",
                    $exporter->mimeType()
                );
        }
    }

    /** @return string[] */
    private function compressions(): array
    {
        $list = ['none'];
        if (function_exists('gzencode') || in_array('zlib.*', stream_get_filters(), true)) {
            $list[] = 'gzip';
        }
        if (class_exists('ZipArchive')) {
            $list[] = 'zip';
        }
        return $list;
    }

    /**
     * Run an export into $out. If it fails after output started (the download is already under way
     * and the file would just be cut off), log the error, append the format's failure marker to the
     * same stream (inside any compression filter) and end normally. If nothing was written yet the
     * exception propagates so the client gets a proper JSON error.
     *
     * @param string[] $tables
     * @param resource $out
     */
    public static function runExport(ExporterInterface $exporter, array $tables, $out): void
    {
        try {
            $exporter->export($tables, $out);
        } catch (\Throwable $e) {
            if (!$exporter->hasWritten()) {
                throw $e;
            }
            error_log('[ldb] download failed: ' . get_class($e) . ': ' . $e->getMessage());
            try {
                $exporter->writeFailure($out, $e->getMessage());
            } catch (\Throwable $ignored) {
                // the stream is gone as well: nothing more to do
            }
        }
    }

    /**
     * Stream through the zlib.deflate filter (window 31 = gzip framing) so the download starts
     * immediately with constant memory; falls back to gzencode of a temp copy.
     * @param string[] $tables
     * @param resource $out
     */
    private static function exportGzip(ExporterInterface $exporter, array $tables, $out): void
    {
        $filter = @stream_filter_append($out, 'zlib.deflate', STREAM_FILTER_WRITE, ['level' => 6, 'window' => 31]);
        if (is_resource($filter)) {
            try {
                self::runExport($exporter, $tables, $out);
            } finally {
                stream_filter_remove($filter); // flushes the gzip trailer
            }
            return;
        }
        $tmp = fopen('php://temp/maxmemory:8388608', 'w+b');
        if ($tmp === false) {
            throw new \RuntimeException('Could not create a temporary stream');
        }
        try {
            self::runExport($exporter, $tables, $tmp);
        } catch (\Throwable $e) {
            fclose($tmp);
            throw $e;
        }
        rewind($tmp);
        fwrite($out, (string) gzencode((string) stream_get_contents($tmp), 6));
        fclose($tmp);
    }

    /** @param string[] $tables */
    private function zipDownload(ExporterInterface $exporter, array $tables, string $innerName, string $zipName): Response
    {
        $dir = sys_get_temp_dir();
        $plain = tempnam($dir, 'oph_exp_');
        $zipPath = tempnam($dir, 'oph_zip_');
        if ($plain === false || $zipPath === false) {
            throw new ApiException('Could not create a temporary file', 'internal', 500);
        }
        try {
            $fh = fopen($plain, 'wb');
            if ($fh === false) {
                throw new ApiException('Could not write a temporary file', 'internal', 500);
            }
            try {
                $exporter->export($tables, $fh);
            } finally {
                fclose($fh);
            }
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
                throw new ApiException('Could not create zip archive', 'internal', 500);
            }
            $zip->addFile($plain, $innerName);
            $zip->close();
        } catch (\Throwable $e) {
            @unlink($plain);
            @unlink($zipPath);
            throw $e;
        }
        @unlink($plain);

        return Response::download(
            static function ($out) use ($zipPath): void {
                $in = fopen($zipPath, 'rb');
                if ($in !== false) {
                    stream_copy_to_stream($in, $out);
                    fclose($in);
                }
                @unlink($zipPath);
            },
            $zipName,
            'application/zip'
        )->withHeader('Content-Length', (string) filesize($zipPath));
    }

    /** {db}[_{table}]_{Ymd_His}; Unicode kept, filesystem-hostile characters replaced. */
    private static function fileBase(string $database, ?string $table): string
    {
        $parts = [$database];
        if ($table !== null) {
            $parts[] = $table;
        }
        $parts[] = date('Ymd_His');
        $name = implode('_', $parts);
        $name = (string) preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', '_', $name);
        if (mb_strlen($name, 'UTF-8') > 150) {
            $name = mb_substr($name, 0, 150, 'UTF-8');
        }
        return $name;
    }
}

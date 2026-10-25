<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Http\ApiException;
use Ldb\Import\CsvImporter;
use Ldb\Import\SqlImporter;

final class ImportController extends AbstractController
{
    private const UPLOAD_ERRORS = [
        UPLOAD_ERR_INI_SIZE => 'The file exceeds the server upload limit',
        UPLOAD_ERR_FORM_SIZE => 'The file exceeds the form upload limit',
        UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on the server',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write the uploaded file',
        UPLOAD_ERR_EXTENSION => 'Upload blocked by a PHP extension',
    ];

    /** @return array{encodings:array,upload_max:int} CSV encodings and the effective upload size limit in bytes */
    public function options(): array
    {
        return [
            'encodings' => CsvImporter::encodings(),
            'upload_max' => $this->uploadMax(),
        ];
    }

    /** @return array<string,mixed> {statements, errors, time_ms, time, rows?} */
    public function upload(): array
    {
        $file = $this->request->file('file');
        if ($file === null || is_array($file['error'] ?? null)) {
            throw ApiException::badRequest('No file was uploaded');
        }
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw ApiException::badRequest(self::UPLOAD_ERRORS[$error] ?? 'Upload failed');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (!is_uploaded_file($tmp)) {
            throw ApiException::badRequest('Invalid upload');
        }
        $size = (int) ($file['size'] ?? filesize($tmp));
        if ($size > $this->config->int('upload_max', 64 * 1024 * 1024)) {
            throw ApiException::badRequest('The file exceeds the configured upload limit');
        }

        $format = (string) $this->request->param('format', 'sql');
        if (!in_array($format, ['sql', 'csv'], true)) {
            throw ApiException::badRequest('Unsupported import format');
        }
        $table = $format === 'csv' ? $this->request->string('table') : null;

        $db = $this->db();
        $options = array_replace($this->request->arr('options'), $this->request->arr('csv_options'));
        $options['schema'] = $this->request->optString('schema');
        $options['database'] = $this->request->optString('db');
        $options['original_name'] = (string) ($file['name'] ?? '');

        // Importers look at the extension (e.g. .gz), so keep a sanitised one on the temp copy.
        $gz = (bool) preg_match('/\.gz$/i', (string) ($file['name'] ?? '')) || self::isGzip($tmp);
        $path = $this->tempPath($format . ($gz ? '.gz' : ''));
        if (!move_uploaded_file($tmp, $path)) {
            throw new ApiException('Could not store the uploaded file', 'internal', 500);
        }

        @set_time_limit(0);
        try {
            if ($format === 'csv') {
                $result = (new CsvImporter($db, $options))->importFile($path, (string) $table);
                $result['statements'] = $result['statements'] ?? ($result['rows'] ?? 0);
            } else {
                $result = (new SqlImporter($db, $options))->importFile($path);
            }
        } catch (\RuntimeException $e) {
            if ($e instanceof ApiException) {
                throw $e;
            }
            throw ApiException::badRequest($e->getMessage());
        } finally {
            @unlink($path);
        }

        $result['errors'] = array_map(function ($e): array {
            $e = is_array($e) ? $e : ['message' => (string) $e];
            if (isset($e['statement']) && is_string($e['statement'])) {
                $e['statement'] = self::preview($e['statement'], 500);
            }
            return $e;
        }, (array) ($result['errors'] ?? []));
        $ms = (float) ($result['time_ms'] ?? 0);
        $result['time'] = round($ms / 1000, 3);
        return $result;
    }

    /** Smallest of config upload_max, php.ini upload_max_filesize and post_max_size (0 = unlimited is ignored). */
    private function uploadMax(): int
    {
        $limits = [$this->config->int('upload_max', 64 * 1024 * 1024)];
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $bytes = self::iniBytes((string) ini_get($key));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }
        $positive = array_filter($limits, static fn (int $v): bool => $v > 0);
        return $positive ? min($positive) : 0;
    }

    /** Parse php.ini shorthand sizes ("8M", "1G", "512K"). */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || !preg_match('/^(\d+)\s*([KMG]?)/i', $value, $m)) {
            return 0;
        }
        $n = (int) $m[1];
        switch (strtoupper($m[2])) {
            case 'G':
                $n *= 1024;
                // fall through
            case 'M':
                $n *= 1024;
                // fall through
            case 'K':
                $n *= 1024;
        }
        return $n;
    }

    private function tempPath(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'oph_imp_');
        if ($base === false) {
            throw new ApiException('Could not create a temporary file', 'internal', 500);
        }
        @unlink($base);
        return $base . '.' . $extension;
    }

    private static function isGzip(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = fread($fh, 2);
        fclose($fh);
        return $magic === "\x1f\x8b";
    }
}

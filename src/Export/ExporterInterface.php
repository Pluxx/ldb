<?php

declare(strict_types=1);

namespace Ldb\Export;

use Ldb\Db\DriverInterface;

interface ExporterInterface
{
    /**
     * @param array $options format specific options (see ExporterFactory::formats())
     */
    public function __construct(DriverInterface $db, array $options);

    public function mimeType(): string;

    /** File extension without dot. */
    public function extension(): string;

    /**
     * @param string[] $tables table/view names of the current db/schema (ignored when option 'query' is set)
     * @param resource $out    writable stream
     */
    public function export(array $tables, $out): void;

    /** True once export() (or writeFailure()) has written at least one byte to its stream. */
    public function hasWritten(): bool;

    /**
     * Append a recognisable failure trailer to an export that broke off after output started, so a
     * truncated file cannot be mistaken for a complete one. Each format picks a marker that is
     * valid in (or deliberately invalidates) its syntax. $message is raw; implementations must
     * sanitize it with AbstractExporter::failureMessage().
     *
     * @param resource $out the same stream export() wrote to (a compression filter stays attached)
     */
    public function writeFailure($out, string $message): void;
}

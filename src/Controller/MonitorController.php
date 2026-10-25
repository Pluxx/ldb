<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Monitor\ServerMonitor;

/**
 * Server monitor (route #/c/{conn}/server/monitor): metrics snapshot, top statements and tuning
 * hints. All actions are reads (allowed on read-only connections); capability `monitor`.
 */
final class MonitorController extends AbstractController
{
    /** Normalised snapshot {driver, ts, uptime, mix_unit, counters, gauges, settings, long}. */
    public function metrics(): array
    {
        return $this->monitor()->metrics();
    }

    /** {source, sources, hint, rows} — params: limit (1–100, default 25), source, order (total|avg|count). */
    public function slowLog(): array
    {
        return $this->monitor()->slowLog(
            $this->request->int('limit', 25),
            (string) ($this->request->optString('source') ?? 'auto'),
            (string) ($this->request->optString('order') ?? 'total')
        );
    }

    /** {hints:[{id, severity, metric, value, params}], ts} */
    public function advice(): array
    {
        return $this->monitor()->advice();
    }

    private function monitor(): ServerMonitor
    {
        $db = $this->db(false);
        $this->requireCapability($db, 'monitor');
        return ServerMonitor::for($db);
    }
}

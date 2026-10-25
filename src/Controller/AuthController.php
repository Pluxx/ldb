<?php

declare(strict_types=1);

namespace Ldb\Controller;

use Ldb\Auth\LoginService;
use Ldb\Auth\RateLimiter;
use Ldb\Auth\TwoFactorStore;
use Ldb\Version;

final class AuthController extends AbstractController
{
    /** @return array<string,mixed> */
    public function status(): array
    {
        return [
            'authenticated' => $this->session->isAuthenticated(),
            'csrf' => $this->session->csrf()->token(),
            'connections' => array_map(function (array $c): array {
                $c['read_only'] = $this->factory->isReadOnly($c);
                return $c;
            }, $this->session->connections()),
            'presets' => $this->factory->presets(),
            'drivers' => $this->factory->drivers(),
            'version' => Version::get(),
            'twofa_pending' => $this->session->transient(LoginService::PENDING) !== null,
            'settings' => [
                'only_presets' => $this->config->bool('only_presets'),
                'allow_arbitrary_server' => $this->config->bool('allow_arbitrary_server'),
                'read_only' => $this->config->bool('read_only'),
                'sqlite_enabled' => $this->config->arr('sqlite_paths') !== [],
                'sqlite_password' => $this->factory->sqlitePasswordRequired(),
                'theme_default' => (string) $this->config->get('theme_default', 'system'),
                'max_rows_browse' => $this->config->int('max_rows_browse', 1000),
                'upload_max' => $this->uploadMax(),
                'twofa_available' => TwoFactorStore::secretKey($this->config) !== null,
            ],
        ];
    }

    /** @return array<string,mixed> */
    public function login(): array
    {
        $service = new LoginService($this->config, $this->session, $this->factory);
        $result = $service->login($this->request->all(), $this->rateKey());
        // twofa_required: password verified, nothing stored yet → auth.verify2fa.
        return $this->status() + $result;
    }

    /** Second login step: `code` (TOTP) or `recovery_code`. @return array<string,mixed> */
    public function verify2fa(): array
    {
        $service = new LoginService($this->config, $this->session, $this->factory);
        $code = $this->request->optString('code');
        $recovery = $code === null ? $this->request->optString('recovery_code') : null;
        $conn = $service->verifySecondFactor($code, $recovery, $this->rateKey());
        return $this->status() + ['conn' => $conn];
    }

    private function rateKey(): string
    {
        return 'login|' . RateLimiter::ipBucket($this->request->clientIp());
    }

    /** @return array<string,mixed> */
    public function logout(): array
    {
        $conn = $this->request->optString('conn');
        if ($conn !== null) {
            $this->session->removeConnection($conn);
        } else {
            $this->session->logoutAll();
        }
        return $this->status();
    }

    private function uploadMax(): int
    {
        $limits = [$this->config->int('upload_max', 64 * 1024 * 1024)];
        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $bytes = self::iniBytes((string) ini_get($ini));
            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }
        return min($limits);
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '') {
            return 0;
        }
        $n = (int) $v;
        switch (strtolower(substr($v, -1))) {
            case 'g':
                $n *= 1024;
                // no break
            case 'm':
                $n *= 1024;
                // no break
            case 'k':
                $n *= 1024;
        }
        return $n;
    }
}

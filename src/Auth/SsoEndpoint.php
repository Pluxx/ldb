<?php

declare(strict_types=1);

namespace Ldb\Auth;

use Ldb\Config;
use Ldb\Db\DriverFactory;
use Ldb\Http\ApiException;
use Ldb\Http\Request;
use Ldb\Http\Response;

/**
 * public/sso.php: log in with an SSO token (GET ?token=… or POST form field `token`) and
 * redirect into the app. Every failure shows the same generic page; the reason goes to
 * error_log() only (never the token or credentials). Failures are rate-limited per client IP
 * (IPv6: /64) with the login limits, under a separate bucket from the login form.
 */
final class SsoEndpoint
{
    private Config $config;
    private ?SsoNonceStore $nonces;

    public function __construct(Config $config, ?SsoNonceStore $nonces = null)
    {
        $this->config = $config;
        $this->nonces = $nonces;
    }

    public function handle(Request $request): Response
    {
        $method = $request->method();
        if ($method !== 'GET' && $method !== 'POST') {
            return self::page(405, 'Method not allowed.')->withHeader('Allow', 'GET, POST');
        }
        $secret = Sso::secret($this->config);
        if ($secret === null) {
            self::log('SSO is disabled (sso_secret missing or shorter than ' . Sso::MIN_SECRET_BYTES . ' bytes)', $request);
            return self::page(404, 'Single sign-on is not enabled on this server.');
        }

        $limiter = LoginService::rateLimiter($this->config);
        $key = 'sso|' . RateLimiter::ipBucket($request->clientIp());
        if ($limiter->tooManyAttempts($key)) {
            self::log('rate limited', $request);
            return self::page(429, 'Too many failed sign-on attempts. Please try again later.');
        }

        $token = $request->param('token');
        try {
            if (!is_string($token) || $token === '') {
                throw new SsoException('no token given');
            }
            $dir = $this->config->get('sso_nonce_dir');
            $nonces = $this->nonces ?? new SsoNonceStore(is_string($dir) && $dir !== '' ? $dir : null);
            $claims = Sso::verify($token, $secret, Sso::ttl($this->config), $nonces);
            [$input, $allowArbitrary] = Sso::loginInput($claims, $this->config);
        } catch (SsoException $e) {
            $limiter->hit($key);
            self::log($e->getMessage(), $request);
            return self::invalid();
        }

        try {
            $session = new SessionManager($this->config, $request);
            $service = new LoginService($this->config, $session, new DriverFactory($this->config), $limiter);
            // SSO flag: a 2FA-enabled login is refused unless sso_bypass_2fa (LoginService).
            $conn = (string) ($service->login($input, $key, $allowArbitrary, true)['conn'] ?? '');
            $fragment = Sso::redirectFragment($claims, $conn);
        } catch (ApiException $e) {
            // LoginService already counted the failure.
            self::log('login failed (' . $e->getErrorCode() . '): ' . $e->getMessage(), $request);
            return $e->getErrorCode() === 'rate_limited'
                ? self::page(429, 'Too many failed sign-on attempts. Please try again later.')
                : self::invalid();
        } catch (SsoException $e) {
            self::log($e->getMessage(), $request);
            return self::invalid();
        } catch (\Throwable $e) {
            // e.g. a PHP warning turned into ErrorException while connecting.
            $limiter->hit($key);
            self::log('login error: ' . get_class($e) . ': ' . $e->getMessage(), $request);
            return self::invalid();
        }

        // Relative to sso.php, i.e. always this application; the fragment was validated.
        return self::harden(Response::redirect('index.php' . $fragment, 303));
    }

    private static function invalid(): Response
    {
        return self::page(400, 'This sign-on link is invalid or has expired.');
    }

    private static function page(int $status, string $message): Response
    {
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="referrer" content="no-referrer"><meta name="robots" content="noindex">'
            . '<meta name="color-scheme" content="light dark"><title>LDB – sign-on failed</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;margin:0;padding:15vh 16px;text-align:center}'
            . 'main{max-width:28rem;margin:0 auto}h1{font-size:1.25rem}a{color:#4f46e5}</style></head><body><main>'
            . '<h1>Single sign-on failed</h1><p>' . $e($message) . '</p>'
            . '<p>Request a new link from your control panel, or <a href="index.php#/login">sign in manually</a>.</p>'
            . '</main></body></html>';
        return self::harden(Response::html($html, $status));
    }

    private static function harden(Response $r): Response
    {
        return $r->withHeader('Cache-Control', 'no-store, no-cache, must-revalidate, private')
            ->withHeader('Pragma', 'no-cache')
            ->withHeader('Expires', '0')
            // The token is in this URL (GET): it must never leak to other sites via Referer.
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private static function log(string $reason, Request $request): void
    {
        error_log('[ldb] SSO rejected: ' . $reason . ' (client ' . RateLimiter::ipBucket($request->clientIp()) . ')');
    }
}

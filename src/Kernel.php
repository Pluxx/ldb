<?php

declare(strict_types=1);

namespace Ldb;

use Ldb\Auth\SessionManager;
use Ldb\Controller\AuthController;
use Ldb\Controller\DatabaseController;
use Ldb\Controller\DiffController;
use Ldb\Controller\ExportController;
use Ldb\Controller\ImportController;
use Ldb\Controller\MonitorController;
use Ldb\Controller\PrivilegesController;
use Ldb\Controller\RoutineController;
use Ldb\Controller\ServerController;
use Ldb\Controller\SqlController;
use Ldb\Controller\TableController;
use Ldb\Controller\TwoFactorController;
use Ldb\Db\DriverFactory;
use Ldb\Http\ApiException;
use Ldb\Http\Request;
use Ldb\Http\Response;

/**
 * API dispatcher: maps `action` to a controller method, enforces authentication and CSRF,
 * and converts every exception into the JSON error envelope.
 */
final class Kernel
{
    // Route flags
    private const PUBLIC = 1;      // no authentication needed
    private const NO_CSRF = 2;     // exempt from CSRF check
    private const FORM_CSRF = 4;   // CSRF token may come from a form field (navigations, multipart)
    private const GET_OK = 8;      // may be called via GET (pure read)
    private const KEEP_SESSION = 16; // keeps the session open (writes to it)
    private const WRITE = 32;      // modifies data/schema/server state: refused on read-only connections
    private const ENROLL_OK = 64;  // allowed while a connection must still enroll in 2FA (twofa_required)

    /** @var array<string,array{0:class-string,1:string,2:int}> */
    private const ROUTES = [
        'auth.status' => [AuthController::class, 'status', self::PUBLIC | self::NO_CSRF | self::GET_OK | self::KEEP_SESSION],
        'auth.login' => [AuthController::class, 'login', self::PUBLIC | self::NO_CSRF | self::KEEP_SESSION],
        'auth.logout' => [AuthController::class, 'logout', self::KEEP_SESSION | self::ENROLL_OK],
        // Second login step (pending login in the session; CSRF token from the auth.login answer).
        'auth.verify2fa' => [AuthController::class, 'verify2fa', self::PUBLIC | self::KEEP_SESSION],
        // Not WRITE: 2FA is about the login, so it is managed on read-only connections too.
        'twofa.status' => [TwoFactorController::class, 'status', self::ENROLL_OK],
        'twofa.enrollStart' => [TwoFactorController::class, 'enrollStart', self::KEEP_SESSION | self::ENROLL_OK],
        'twofa.enrollConfirm' => [TwoFactorController::class, 'enrollConfirm', self::KEEP_SESSION | self::ENROLL_OK],
        'twofa.disable' => [TwoFactorController::class, 'disable', self::KEEP_SESSION],
        'twofa.recoveryRegenerate' => [TwoFactorController::class, 'recoveryRegenerate', self::KEEP_SESSION],

        'server.overview' => [ServerController::class, 'overview', self::GET_OK],
        'server.variables' => [ServerController::class, 'variables', self::GET_OK],
        'server.status' => [ServerController::class, 'status', self::GET_OK],
        'server.processes' => [ServerController::class, 'processes', self::GET_OK],
        'server.kill' => [ServerController::class, 'kill', self::WRITE],
        'server.users' => [ServerController::class, 'users', self::GET_OK],
        'server.userGrants' => [ServerController::class, 'userGrants', self::GET_OK],
        'server.userSave' => [ServerController::class, 'userSave', self::WRITE],
        'server.userDrop' => [ServerController::class, 'userDrop', self::WRITE],
        'server.collations' => [ServerController::class, 'collations', self::GET_OK],
        'server.charsets' => [ServerController::class, 'charsets', self::GET_OK],
        'server.engines' => [ServerController::class, 'engines', self::GET_OK],
        'server.dataTypes' => [ServerController::class, 'dataTypes', self::GET_OK],
        // Privileges editor: plan is a preview (nothing runs); apply re-plans on the server.
        'server.privileges' => [PrivilegesController::class, 'privileges', self::GET_OK],
        'server.privilegeObjects' => [PrivilegesController::class, 'objects', self::GET_OK],
        'server.privilegesPlan' => [PrivilegesController::class, 'plan', 0],
        'server.privilegesApply' => [PrivilegesController::class, 'apply', self::WRITE],
        'server.roleCreate' => [PrivilegesController::class, 'roleCreate', self::WRITE],
        'server.roleDrop' => [PrivilegesController::class, 'roleDrop', self::WRITE],
        'server.metrics' => [MonitorController::class, 'metrics', self::GET_OK],
        'server.slowLog' => [MonitorController::class, 'slowLog', self::GET_OK],
        'server.advice' => [MonitorController::class, 'advice', self::GET_OK],

        'db.list' => [DatabaseController::class, 'list', self::GET_OK],
        'db.create' => [DatabaseController::class, 'create', self::WRITE],
        'db.drop' => [DatabaseController::class, 'drop', self::WRITE],
        'db.info' => [DatabaseController::class, 'info', self::GET_OK],
        'db.alter' => [DatabaseController::class, 'alter', self::WRITE],
        'db.copy' => [DatabaseController::class, 'copy', self::WRITE],
        'db.schemas' => [DatabaseController::class, 'schemas', self::GET_OK],
        'db.objects' => [DatabaseController::class, 'objects', self::GET_OK],
        'db.search' => [DatabaseController::class, 'search', 0],
        'db.erd' => [DatabaseController::class, 'erd', self::GET_OK],
        // Schema diff: both sides must be connections of this session with the same driver.
        'diff.compare' => [DiffController::class, 'compare', 0],
        'diff.plan' => [DiffController::class, 'plan', 0],
        'diff.apply' => [DiffController::class, 'apply', self::WRITE], // conn = target connection

        'table.info' => [TableController::class, 'info', self::GET_OK],
        'table.ddl' => [TableController::class, 'ddl', self::GET_OK],
        'table.browse' => [TableController::class, 'browse', 0],
        'table.insert' => [TableController::class, 'insert', self::WRITE],
        'table.update' => [TableController::class, 'update', self::WRITE],
        'table.delete' => [TableController::class, 'delete', self::WRITE],
        'table.applyChanges' => [TableController::class, 'applyChanges', self::WRITE],
        'table.create' => [TableController::class, 'create', self::WRITE],
        'table.alter' => [TableController::class, 'alter', self::WRITE],
        'table.drop' => [TableController::class, 'drop', self::WRITE],
        'table.truncate' => [TableController::class, 'truncate', self::WRITE],
        'table.empty' => [TableController::class, 'empty', self::WRITE],
        'table.rename' => [TableController::class, 'rename', self::WRITE],
        'table.copy' => [TableController::class, 'copy', self::WRITE],
        'table.maintenance' => [TableController::class, 'maintenance', self::WRITE],
        'table.fkValues' => [TableController::class, 'fkValues', 0],

        // Routines / triggers / events editor. routine.execute is not WRITE: functions may run on
        // read-only connections (ReadOnlyGuard + read-only session); procedures are refused there.
        'routine.info' => [RoutineController::class, 'routineInfo', self::GET_OK],
        'routine.save' => [RoutineController::class, 'routineSave', self::WRITE],
        'routine.drop' => [RoutineController::class, 'routineDrop', self::WRITE],
        'routine.execute' => [RoutineController::class, 'routineExecute', 0],
        'trigger.info' => [RoutineController::class, 'triggerInfo', self::GET_OK],
        'trigger.save' => [RoutineController::class, 'triggerSave', self::WRITE],
        'trigger.drop' => [RoutineController::class, 'triggerDrop', self::WRITE],
        'event.info' => [RoutineController::class, 'eventInfo', self::GET_OK],
        'event.save' => [RoutineController::class, 'eventSave', self::WRITE],
        'event.drop' => [RoutineController::class, 'eventDrop', self::WRITE],

        'sql.execute' => [SqlController::class, 'execute', 0],
        'sql.explain' => [SqlController::class, 'explain', 0],
        // Not WRITE: only cancels the session's own running query (allowed on read-only connections).
        'sql.cancel' => [SqlController::class, 'cancel', 0],
        'sql.format' => [SqlController::class, 'format', 0],
        'sql.completions' => [SqlController::class, 'completions', self::GET_OK],

        'import.options' => [ImportController::class, 'options', self::GET_OK],
        'import.upload' => [ImportController::class, 'upload', self::FORM_CSRF | self::WRITE],
        'export.formats' => [ExportController::class, 'formats', self::GET_OK],
        'export.download' => [ExportController::class, 'download', self::FORM_CSRF],
    ];

    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /** Handle the current request and send the response. */
    public function run(): void
    {
        try {
            $request = Request::fromGlobals();
        } catch (ApiException $e) {
            Response::error($e)->send();
            return;
        }
        $this->handle($request)->send();
    }

    public function handle(Request $request): Response
    {
        $response = $this->handleAction($request);
        if ($request->action() === 'export.download') {
            // The export view submits into a hidden same-origin iframe and reads the JSON error
            // from it; DENY would hide the message. Still not frameable by other origins.
            $response = $response
                ->withHeader('X-Frame-Options', 'SAMEORIGIN')
                ->withHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'self'");
        }
        return $response;
    }

    private function handleAction(Request $request): Response
    {
        try {
            return $this->dispatch($request);
        } catch (ApiException $e) {
            return Response::error($e);
        } catch (\PDOException $e) {
            return Response::error(ApiException::dbError($e->getMessage(), null, $e), $this->debugInfo($e));
        } catch (\Throwable $e) {
            error_log('[ldb] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            $message = $this->config->bool('debug') ? $e->getMessage() : 'Internal server error';
            return Response::error(new ApiException($message, 'internal', 500), $this->debugInfo($e));
        }
    }

    private function dispatch(Request $request): Response
    {
        $action = $request->action();
        $route = self::ROUTES[$action] ?? null;
        if ($route === null) {
            throw ApiException::notFound('Unknown action');
        }
        [$class, $method, $flags] = $route;

        if (!$request->isPost() && !($flags & self::GET_OK)) {
            throw new ApiException('This action requires POST', 'bad_request', 405);
        }
        if ($request->isPost()) {
            // Defence in depth on top of SameSite=Strict cookies and CSRF tokens: browsers mark
            // requests from other sites (including sibling sub-domains on shared hosting).
            $site = strtolower((string) $request->header('Sec-Fetch-Site'));
            if ($site === 'cross-site' || $site === 'same-site') {
                throw new ApiException('Cross-site request rejected', 'csrf', 403);
            }
            if (($flags & self::NO_CSRF) && stripos((string) $request->header('Content-Type'), 'application/json') !== 0) {
                // Login has no CSRF token; a JSON body cannot be sent cross-origin without a CORS
                // preflight, which blocks login-CSRF from HTML forms.
                throw new ApiException('Content-Type must be application/json', 'bad_request', 415);
            }
        }

        $session = new SessionManager($this->config, $request);
        $session->start();

        if (!($flags & self::PUBLIC) && !$session->isAuthenticated()) {
            throw ApiException::authRequired();
        }
        if ($request->isPost() && !($flags & self::NO_CSRF)) {
            $token = $request->header('X-CSRF-Token');
            if (($token === null || $token === '') && ($flags & self::FORM_CSRF)) {
                $field = $request->param('csrf');
                $token = is_string($field) ? $field : null;
            }
            if (!$session->csrf()->validate($token)) {
                throw ApiException::csrf();
            }
        }
        if (!($flags & (self::PUBLIC | self::ENROLL_OK)) && $this->enrollmentPending($session)) {
            // twofa_required: a login without 2FA must set it up before doing anything else.
            throw new ApiException('Set up two-factor authentication to continue', 'twofa_enroll_required', 403);
        }
        if (($flags & self::WRITE) && $this->isReadOnlyConnection($session, $request)) {
            // Only table.maintenance op=check is a pure read among the WRITE routes.
            if (!($action === 'table.maintenance' && $request->param('op') === 'check')) {
                throw new ApiException('This connection is read-only', 'read_only', 403);
            }
        }
        if (!($flags & self::KEEP_SESSION)) {
            // Credentials are decrypted from the in-memory copy; release the lock for parallel requests.
            $session->connections();
            $session->close();
        }

        $controller = new $class($this->config, $session, new DriverFactory($this->config), $request);
        $result = $controller->$method();
        return $result instanceof Response ? $result : Response::ok($result);
    }

    /** Whether any connection of the session still has to enroll in 2FA (LoginService). */
    private function enrollmentPending(SessionManager $session): bool
    {
        foreach ($session->connections() as $meta) {
            if (!empty($meta['twofa_enroll'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Read-only state of the connection a request targets (same resolution as
     * AbstractController::connId()). Unknown connections are left to the controller (auth error).
     */
    private function isReadOnlyConnection(SessionManager $session, Request $request): bool
    {
        $conn = $request->optString('conn');
        if ($conn === null) {
            $ids = $session->connectionIds();
            if (count($ids) !== 1) {
                return $this->config->bool('read_only');
            }
            $conn = $ids[0];
        }
        $params = $session->connectionParams($conn);
        if ($params === null) {
            return $this->config->bool('read_only');
        }
        return (new DriverFactory($this->config))->isReadOnly($params);
    }

    /** @return array<string,mixed>|null */
    private function debugInfo(\Throwable $e): ?array
    {
        if (!$this->config->bool('debug')) {
            return null;
        }
        return [
            'exception' => get_class($e),
            'file' => $e->getFile() . ':' . $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString()),
        ];
    }
}

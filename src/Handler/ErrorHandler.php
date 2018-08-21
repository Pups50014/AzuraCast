<?php
namespace App\Handler;

use App\Acl;
use App\View;
use App\Session;
use App\Url;
use App\Entity;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use Monolog\Logger;

class ErrorHandler
{
    /** @var Acl */
    protected $acl;

    /** @var Logger */
    protected $logger;

    /** @var Session */
    protected $session;

    /** @var Router */
    protected $router;

    /** @var View */
    protected $view;

    /**
     * ErrorHandler constructor.
     * NOTE: Session and View need to be injected directly, as the request attributes don't get
     *       passed when handling middleware exceptions.
     *
     * @param Acl $acl
     * @param Logger $logger
     * @param Session $session
     * @param Router $router
     * @param \App\View $view
     */
    public function __construct(
        Acl $acl,
        Logger $logger,
        Router $router,
        Session $session,
        View $view
    )
    {
        $this->acl = $acl;
        $this->logger = $logger;
        $this->router = $router;
        $this->session = $session;
        $this->view = $view;
    }

    public function __invoke(Request $req, Response $res, \Throwable $e)
    {
        // Don't log errors that are internal to the application.
        $e_level = ($e instanceof \App\Exception)
            ? $e->getLoggerLevel()
            : Logger::ERROR;

        $this->logger->addRecord($e_level, $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'code' => $e->getCode(),
        ]);

        $show_detailed = !APP_IN_PRODUCTION;

        if ($req->isXhr() || APP_IS_COMMAND_LINE || APP_TESTING_MODE) {
            $api_response = new Entity\Api\Error(
                $e->getCode(),
                $e->getMessage(),
                ($show_detailed) ? $e->getTrace() : []
            );
            return $res->withStatus(500)->withJson($api_response);
        }

        if ($e instanceof \App\Exception\NotLoggedIn) {
            // Redirect to login page for not-logged-in users.
            $this->session->flash(__('You must be logged in to access this page.'), 'red');

            // Set referrer for login redirection.
            $referrer_login = $this->session->get('login_referrer');
            $referrer_login->url = $this->router->current();

            return $res
                ->withStatus(302)
                ->withHeader('Location', $this->router->named('account:login'));
        }

        if ($e instanceof \App\Exception\PermissionDenied) {
            // Bounce back to homepage for permission-denied users.
            $this->session->flash(__('You do not have permission to access this portion of the site.'),
                Session\Flash::ERROR);

            return $res
                ->withStatus(302)
                ->withHeader('Location', $this->router->named('home'));
        }

        if ($show_detailed) {
            // Register error-handler.
            $handler = new \Whoops\Handler\PrettyPageHandler;
            $handler->setPageTitle('An error occurred!');

            if ($e instanceof \App\Exception) {
                $extra_tables = $e->getExtraData();
                foreach($extra_tables as $legend => $data) {
                    $handler->addDataTable($legend, $data);
                }
            }

            $run = new \Whoops\Run;
            $run->pushHandler($handler);

            return $res->withStatus(500)->write($run->handleException($e));
        }

        return $this->view->renderToResponse($res->withStatus(500), 'system/error_general', [
            'exception' => $e,
        ]);
    }
}

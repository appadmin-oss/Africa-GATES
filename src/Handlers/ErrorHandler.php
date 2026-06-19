<?php
declare(strict_types=1);
namespace AfricaGates\Handlers;
use Slim\App;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

class ErrorHandler {
    public function __construct(private readonly App $app) {}
    public function __invoke(Request $req, Throwable $ex, bool $displayDetails, bool $logErrors, bool $logErrorDetails): Response {
        $code=500;
        if($ex instanceof \Slim\Exception\HttpNotFoundException) $code=404;
        if($ex instanceof \Slim\Exception\HttpMethodNotAllowedException){
            // The CORS catch-all (OPTIONS /{routes:.+}) makes every unmatched path
            // look "method not allowed". If OPTIONS is the only allowed method the
            // path is genuinely unmatched → report a true 404 (correct for SEO),
            // not a misleading 405.
            $allowed = array_map('strtoupper', $ex->getAllowedMethods());
            $code = (array_diff($allowed, ['OPTIONS']) === []) ? 404 : 405;
        }
        $isJson=str_contains($req->getHeaderLine('Accept'),'application/json')||str_starts_with($req->getUri()->getPath(),'/api/');
        $res=$this->app->getResponseFactory()->createResponse($code);
        // Never leak internal exception details to clients on 5xx; 4xx messages
        // (validation/not-found) are safe and intentional. $displayDetails is
        // true only when APP_ENV=development.
        $safeMsg = $code>=500
            ? ($displayDetails ? $ex->getMessage() : 'An internal error occurred.')
            : ($code===404 ? 'Not found.' : ($code===405 ? 'Method not allowed.' : 'Request could not be processed.'));
        if($isJson){ $res->getBody()->write(json_encode(['success'=>false,'message'=>$safeMsg])); return $res->withHeader('Content-Type','application/json'); }
        // Resolve Twig from the request if the view middleware ran, else from the
        // container — routing errors (404/405) fire BEFORE TwigMiddleware attaches
        // the view, so fromRequest() alone would throw and skip the branded page.
        $twig = null;
        try { $twig = \Slim\Views\Twig::fromRequest($req); }
        catch(\Throwable $e){ try { $c=$this->app->getContainer(); $twig = $c?->get(\Slim\Views\Twig::class); } catch(\Throwable $e2){} }
        if($twig){
            try {
                return $twig->render($res,'pages/error.twig',[
                    'code'    => $code,
                    'heading' => $code===404 ? 'Page not found.' : ($code===405 ? "That request isn't allowed here." : 'Something went sideways.'),
                    'message' => $safeMsg,
                    'gates_page' => '', 'has_hero' => false,
                ]);
            } catch(\Throwable $e3){ /* fall through to minimal output */ }
        }
        $res->getBody()->write("<h1>".(int)$code."</h1><p>".htmlspecialchars($safeMsg,ENT_QUOTES,'UTF-8')."</p>"); return $res->withHeader('Content-Type','text/html');
    }
}

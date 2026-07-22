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
        // Always record full 5xx details to an easy-to-find file, so a production
        // crash can be diagnosed even though detail DISPLAY is hardened off on
        // public hosts. Best-effort; never let logging cause a second failure.
        if ($code >= 500) {
            try {
                $dir = dirname(__DIR__, 2) . '/var/logs';
                if (!is_dir($dir)) @mkdir($dir, 0775, true);
                @file_put_contents($dir . '/error-detail.log',
                    '[' . date('c') . '] ' . get_class($ex) . ': ' . $ex->getMessage()
                    . ' in ' . $ex->getFile() . ':' . $ex->getLine() . "\n"
                    . $ex->getTraceAsString() . "\n\n", FILE_APPEND);
            } catch (\Throwable $ignore) {}
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
                $pageHeading = match(true){
                    $code===404 => 'This path leads nowhere',
                    $code===405 => "That request isn’t allowed here.",
                    $code>=500  => 'Something went wrong on our end',
                    default     => 'Something went sideways.',
                };
                $pageMessage = match(true){
                    $code===404 => 'The page you’re looking for has moved or never existed. The road to recognition is still wide open — let’s get you back on it.',
                    $code>=500  => ($displayDetails ? $ex->getMessage() : 'Our team has been notified and is on it. Your votes and data are safe — please try again in a moment.'),
                    default     => $safeMsg,
                };
                return $twig->render($res,'pages/error.twig',[
                    'code'    => $code,
                    'heading' => $pageHeading,
                    'message' => $pageMessage,
                    'gates_page' => '', 'has_hero' => false, 'lite_page' => true,
                ]);
            } catch(\Throwable $e3){ /* fall through to minimal output */ }
        }
        $res->getBody()->write("<h1>".(int)$code."</h1><p>".htmlspecialchars($safeMsg,ENT_QUOTES,'UTF-8')."</p>"); return $res->withHeader('Content-Type','text/html');
    }
}

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
        // Always record full 5xx details to an easy-to-find file, so a production crash
        // can be diagnosed even though detail DISPLAY is hardened off on public hosts.
        //
        // AND MINT A REFERENCE FOR IT. The log line used to carry only a timestamp while
        // the reader was told "please try again in a moment", so a person who did report
        // the fault could describe what they were doing and nothing else — and nobody
        // could find their entry among a day of them. The detail and the person were in
        // the same building and could not be introduced. PublicFault::record() writes the
        // reference first on the line so grepping what somebody quoted finds it.
        $ref = $code >= 500 ? \AfricaGates\Support\PublicFault::record($ex, $req->getMethod()
            . ' ' . $req->getUri()->getPath()) : null;
        $isJson=str_contains($req->getHeaderLine('Accept'),'application/json')||str_starts_with($req->getUri()->getPath(),'/api/');
        $res=$this->app->getResponseFactory()->createResponse($code);
        // Never leak internal exception details to clients on 5xx; 4xx messages
        // (validation/not-found) are safe and intentional. $displayDetails is
        // true only when APP_ENV=development.
        $safeMsg = $code>=500
            ? ($displayDetails ? $ex->getMessage() : 'An internal error occurred.')
            : ($code===404 ? 'Not found.' : ($code===405 ? 'Method not allowed.' : 'Request could not be processed.'));
        // The reference travels on the JSON branch too. An API consumer hitting a 500 is
        // usually a page of ours, and the toast it shows is the only thing the person ever
        // sees — a reference they cannot quote is a reference that does not exist.
        if($isJson){
            $body = ['success'=>false,'message'=>$safeMsg];
            if($ref !== null) $body['reference'] = $ref;
            $res->getBody()->write((string) json_encode($body));
            return $res->withHeader('Content-Type','application/json');
        }
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
                // WHAT THIS USED TO SAY: "Our team has been notified and is on it."
                //
                // Nobody is notified. The exception goes to a log file whose only reader is
                // a diagnostics route an operator has to remember to open, on a host with
                // no shell and no alerting. The sentence was false — and worse than false,
                // because telling somebody their problem is already being handled is the
                // most effective way to stop them reporting it.
                //
                // The template owns the 500 copy now, so the reference and the sentence
                // that asks for it cannot drift apart.
                $pageMessage = match(true){
                    $code===404 => 'The page you’re looking for has moved or never existed. The road to recognition is still wide open — let’s get you back on it.',
                    $code>=500  => ($displayDetails ? $ex->getMessage() : ''),
                    default     => $safeMsg,
                };
                return $twig->render($res,'pages/error.twig',[
                    'code'    => $code,
                    'heading' => $pageHeading,
                    'message' => $pageMessage,
                    // The template has referenced `error_ref` since it was written and
                    // nothing has ever set it, so the block below it has never rendered
                    // once. Same name, loop closed.
                    'error_ref' => $ref,
                    'gates_page' => '', 'has_hero' => false, 'lite_page' => true,
                ]);
            } catch(\Throwable $e3){ /* fall through to minimal output */ }
        }
        // Last resort: Twig itself failed. Still carries the reference, because this is
        // exactly the situation somebody needs to be able to report.
        $res->getBody()->write("<h1>".(int)$code."</h1><p>".htmlspecialchars($safeMsg,ENT_QUOTES,'UTF-8')."</p>"
            . ($ref !== null ? "<p>Reference <code>".htmlspecialchars($ref,ENT_QUOTES,'UTF-8')."</code></p>" : ''));
        return $res->withHeader('Content-Type','text/html');
    }
}

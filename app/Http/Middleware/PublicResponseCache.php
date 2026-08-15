<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicResponseCache
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if (! in_array($request->method(), ['GET', 'HEAD'], true)
            || ! $request->routeIs('home', 'site.page', 'sitemap', 'robots')) {
            return $response;
        }

        $response->headers->remove('Set-Cookie');
        if ($response->getStatusCode() !== 200) {
            $response->headers->set('Cache-Control', 'no-store, max-age=0');

            return $response;
        }

        $response->setPublic();
        $response->setMaxAge(300);
        $response->setSharedMaxAge(600);
        $response->headers->set('Cache-Control', 'public, max-age=300, s-maxage=600, stale-while-revalidate=60');
        $normalizedContent = preg_replace(
            '/\snonce="[^"]+"/u',
            ' nonce=""',
            (string) $response->getContent(),
        ) ?? (string) $response->getContent();
        $response->setEtag(hash('sha256', $normalizedContent), true);
        if ($response->isNotModified($request)) {
            // A 304 reuses the cached body and its CSP nonce. Do not replace the
            // matching cached policy with the nonce generated for this empty 304.
            $response->headers->remove('Content-Security-Policy');
        }

        return $response;
    }
}

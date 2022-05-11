<?php

namespace App\Guzzle\Middleware;

use Closure;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

class RateLimiterMiddleware
{
    /** @var RateLimiterFactory $apiLimiter */
    private $apiLimiter;

    /**
     * @param RateLimiterFactory $apiLimiter
     */
    public function __construct(RateLimiterFactory $apiLimiter) {
        $this->apiLimiter = $apiLimiter;
    }

    public function __invoke(callable $handler): Closure
    {
        $apiLimiter = $this->apiLimiter;

        return function (RequestInterface $request, array $options = []) use ($handler, $apiLimiter) {
            $limiter = $apiLimiter->create('limiter');

            do {
                $limit = $limiter->consume();
                $limit->wait();
            } while (!$limit->isAccepted());

            return $handler($request, $options);
        };
    }
}

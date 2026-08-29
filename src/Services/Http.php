<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * The one place InvoGrid talks to anything over HTTP.
 *
 * Every integration — Paperless, Clear Books, OpenAI, Anthropic — goes through
 * here rather than calling cURL itself, so a timeout, a retry rule or a proxy
 * setting is changed once. There is no vendor SDK anywhere in this application
 * and there is not going to be one.
 *
 * Two rules it enforces on behalf of its callers:
 *
 *  - **Everything has a timeout.** A hung LLM or a Clear Books outage must not
 *    park a PHP worker forever. Connect and total timeouts are separate because
 *    "the host is unreachable" and "the model is thinking" want very different
 *    numbers.
 *  - **A non-2xx is not an exception.** It is a response with a status on it.
 *    Callers decide what a 404 or a 409 means to them; only a transport failure
 *    (DNS, connect, timeout) throws, because there is no response to reason
 *    about in that case.
 */
final class Http
{
    /** Seconds to wait for the connection itself. */
    public const CONNECT_TIMEOUT = 10;

    /** Seconds for the whole exchange, when the caller does not say. */
    public const TIMEOUT = 30;

    /**
     * The longest this will sit waiting on a `Retry-After` inside one request.
     *
     * A provider that asks for five minutes is not refused — the wait is simply
     * not taken here. The exception carries the number up to the queue, which
     * can afford to wait five minutes because it is not holding a web request
     * open while it does.
     */
    public const MAX_INLINE_WAIT = 20;

    /**
     * Statuses worth sending the same request again for.
     *
     * 429 is the rate limit and says so; 5xx is the far end having a bad
     * moment. A 4xx that is not 429 is the request being wrong, and repeating
     * it is how a wrong request becomes a wrong request three times.
     */
    private const WORTH_REPEATING = [429, 500, 502, 503, 504];

    /**
     * @param array<string,string> $headers
     * @param string|null $body Raw request body, already encoded.
     */
    public static function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        int $timeout = self::TIMEOUT,
        ?string $sinkPath = null,
        int $retries = 0,
        int $maxBytes = 0,
    ): HttpResponse {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = self::send($method, $url, $headers, $body, $timeout, $sinkPath, $maxBytes);
            } catch (HttpTransportException $e) {
                // A connection that never opened sent nothing, so repeating it
                // cannot duplicate anything. A timeout is different — the far
                // end may have acted — which is why the caller opts in.
                if ($attempt > $retries) {
                    throw $e;
                }

                self::pause(self::backoff($attempt));
                continue;
            }

            if ($attempt > $retries || !in_array($response->status, self::WORTH_REPEATING, true)) {
                return $response;
            }

            // The far end usually knows better than any curve we could invent.
            $wait = self::retryAfter($response) ?? self::backoff($attempt);

            if ($wait > self::MAX_INLINE_WAIT) {
                // Longer than a web request should hold. Hand the status back
                // and let the caller turn it into a queue delay.
                return $response;
            }

            self::pause($wait);
        }
    }

    /** One attempt, with no opinion about whether there should be another. */
    private static function send(
        string $method,
        string $url,
        array $headers,
        ?string $body,
        int $timeout,
        ?string $sinkPath,
        int $maxBytes = 0,
    ): HttpResponse {
        $handle = curl_init();

        if ($handle === false) {
            throw new RuntimeException('Could not initialise cURL.');
        }

        $responseHeaders = [];
        $sink            = null;

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => $timeout,

            // Redirects are not followed. An API that answers a POST with a 302
            // is telling us something is wrong with the request — usually the
            // URL — and quietly re-issuing it somewhere else has, at least
            // once, meant a document submitted twice.
            CURLOPT_FOLLOWLOCATION => false,

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'InvoGrid/1.0 (+https://github.com/maeterlinckle/invogrid)',

            CURLOPT_HEADERFUNCTION => static function ($ignored, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $parts  = explode(':', $line, 2);

                if (count($parts) === 2) {
                    // Header names are case-insensitive; lower-casing the key
                    // means a caller never has to guess how the server spelled
                    // it.
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $length;
            },
        ];

        // A PDF that streams straight to disk never has to fit in memory. A
        // scanned document can be tens of megabytes and PHP's memory limit is
        // not something a document's page count should be able to reach.
        if ($sinkPath !== null) {
            $sink = fopen($sinkPath, 'wb');

            if ($sink === false) {
                curl_close($handle);

                throw new RuntimeException('Could not open ' . $sinkPath . ' for writing.');
            }

            $options[CURLOPT_FILE] = $sink;

            /*
             * Abort mid-transfer once the cap is passed.
             *
             * A progress callback rather than CURLOPT_MAXFILESIZE, because that
             * option only works when the far end sends a Content-Length — and a
             * chunked response, which is exactly what a streaming download
             * looks like, sends none. Returning non-zero here aborts the
             * transfer, and the partial file is deleted below with every other
             * failed download.
             */
            if ($maxBytes > 0) {
                $options[CURLOPT_NOPROGRESS]       = false;
                $options[CURLOPT_PROGRESSFUNCTION] = static function (
                    $ignored,
                    $expected,
                    $downloaded
                ) use ($maxBytes): int {
                    // $expected is 0 on a chunked response, so it is only
                    // useful as an early exit when the server does say.
                    if ($expected > $maxBytes || $downloaded > $maxBytes) {
                        return 1;
                    }

                    return 0;
                };
            }
        } else {
            $options[CURLOPT_RETURNTRANSFER] = true;
        }

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        if ($headerLines !== []) {
            $options[CURLOPT_HTTPHEADER] = $headerLines;
        }

        curl_setopt_array($handle, $options);

        $started = microtime(true);
        $raw     = curl_exec($handle);
        $error   = curl_error($handle);
        $errno   = curl_errno($handle);
        $status  = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if (is_resource($sink)) {
            fclose($sink);
        }

        if ($errno !== 0) {
            // A partial file is worse than none: the next stage would treat a
            // truncated PDF as a real one.
            if ($sinkPath !== null && is_file($sinkPath)) {
                @unlink($sinkPath);
            }

            throw new HttpTransportException(
                self::explain($errno, $error, $url),
                $errno
            );
        }

        return new HttpResponse(
            $status,
            $sinkPath !== null ? '' : (is_string($raw) ? $raw : ''),
            $responseHeaders,
            (int) round((microtime(true) - $started) * 1000)
        );
    }

    /**
     * How long the far end asked us to wait, in seconds, or null if it did not.
     *
     * `Retry-After` comes in two shapes — a number of seconds, or an HTTP date
     * — and both are in use. A date in the past reads as zero rather than as a
     * negative wait.
     */
    public static function retryAfter(HttpResponse $response): ?int
    {
        $value = trim((string) ($response->headers['retry-after'] ?? ''));

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return min(3600, (int) $value);
        }

        $when = strtotime($value);

        if ($when === false) {
            return null;
        }

        return max(0, min(3600, $when - time()));
    }

    /** Exponential, with a little jitter so several workers do not resynchronise. */
    private static function backoff(int $attempt): int
    {
        return min(self::MAX_INLINE_WAIT, (2 ** max(0, $attempt - 1)) + random_int(0, 1));
    }

    /** Extracted so the smoke test can exercise the decision without waiting. */
    private static function pause(int $seconds): void
    {
        if ($seconds > 0) {
            sleep($seconds);
        }
    }

    /**
     * @param array<string,string> $headers
     */
    public static function get(string $url, array $headers = [], int $timeout = self::TIMEOUT, int $retries = 0): HttpResponse
    {
        return self::request('GET', $url, $headers, null, $timeout, null, $retries);
    }

    /**
     * Download to a file rather than into memory.
     *
     * @param array<string,string> $headers
     */
    /**
     * `$maxBytes` aborts the transfer mid-flight rather than checking the size
     * afterwards.
     *
     * Checking afterwards is checking after the disk is already full. The far
     * end here is Paperless, which is trusted-ish rather than trusted: it is a
     * separate service on the network, and a misconfiguration or a wrong
     * document id should not be able to take the application down by filling
     * the volume it stores everything else on.
     *
     * @param array<string,string> $headers
     */
    public static function download(
        string $url,
        string $path,
        array $headers = [],
        int $timeout = 120,
        int $retries = 0,
        int $maxBytes = 0,
    ): HttpResponse {
        return self::request('GET', $url, $headers, null, $timeout, $path, $retries, $maxBytes);
    }

    /**
     * @param array<string,mixed>  $payload
     * @param array<string,string> $headers
     */
    /**
     * `$retries` defaults to none, and callers that create things must leave it
     * that way. An LLM completion is safe to repeat — it costs money and
     * produces text. A Clear Books bill is not.
     */
    public static function postJson(
        string $url,
        array $payload,
        array $headers = [],
        int $timeout = self::TIMEOUT,
        int $retries = 0,
    ): HttpResponse {
        return self::request(
            'POST',
            $url,
            $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            self::encode($payload),
            $timeout,
            null,
            $retries
        );
    }

    /**
     * @param array<string,mixed>  $payload
     * @param array<string,string> $headers
     */
    public static function patchJson(string $url, array $payload, array $headers = [], int $timeout = self::TIMEOUT): HttpResponse
    {
        return self::request(
            'PATCH',
            $url,
            $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            self::encode($payload),
            $timeout
        );
    }

    /**
     * @param array<string,mixed>  $payload
     * @param array<string,string> $headers
     */
    public static function putJson(string $url, array $payload, array $headers = [], int $timeout = self::TIMEOUT): HttpResponse
    {
        return self::request(
            'PUT',
            $url,
            $headers + ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            self::encode($payload),
            $timeout
        );
    }

    /** @param array<string,string> $headers */
    public static function delete(string $url, array $headers = [], int $timeout = self::TIMEOUT): HttpResponse
    {
        return self::request('DELETE', $url, $headers + ['Accept' => 'application/json'], null, $timeout);
    }

    /**
     * Build a URL with a query string, encoding every value.
     *
     * @param array<string,scalar|null> $query
     */
    public static function url(string $base, string $path, array $query = []): string
    {
        $url = rtrim($base, '/') . '/' . ltrim($path, '/');

        if ($query === []) {
            return $url;
        }

        return $url . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string,mixed> $payload */
    private static function encode(array $payload): string
    {
        // Slashes unescaped so a URL in a payload is readable in a log; throw
        // rather than silently posting the string "false".
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Turn a cURL error number into something an administrator can act on.
     *
     * The raw messages are accurate and unhelpful: "Failed to connect" does not
     * say that the base URL is probably wrong, and a certificate error reads as
     * a fault in InvoGrid rather than in the far end.
     */
    private static function explain(int $errno, string $message, string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: $url;

        return match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => "Could not resolve {$host}. Check the base URL in Settings.",
            CURLE_COULDNT_CONNECT      => "Could not connect to {$host}. Check the base URL, the port, and that the service is running.",
            CURLE_OPERATION_TIMEDOUT   => "{$host} did not answer in time.",
            CURLE_ABORTED_BY_CALLBACK  => "{$host} sent more than the size limit allows, so the transfer was stopped.",
            CURLE_SSL_CACERT,
            CURLE_PEER_FAILED_VERIFICATION => "The TLS certificate for {$host} could not be verified.",
            default => "Request to {$host} failed: {$message}",
        };
    }
}

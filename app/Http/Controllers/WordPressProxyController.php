<?php

namespace App\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class WordPressProxyController extends Controller
{
    public function __invoke(Request $request, ?string $path = ''): Response
    {
        if (!config('gigtune.wordpress.bridge_enabled', true)) {
            abort(503, 'WordPress bridge is disabled.');
        }

        $mode = strtolower((string) config('gigtune.wordpress.execution_mode', 'http'));
        if ($mode === 'cgi') {
            return $this->proxyViaCgi($request);
        }

        return $this->proxyViaHttp($request);
    }

    private function proxyViaHttp(Request $request): Response
    {
        $baseUrl = rtrim((string) config('gigtune.wordpress.base_url', ''), '/');
        if ($baseUrl === '') {
            abort(500, 'WordPress bridge base URL is not configured.');
        }

        $rawRequestUri = (string) $request->server('REQUEST_URI', '/');
        $rawPath = (string) (parse_url($rawRequestUri, PHP_URL_PATH) ?? '/');
        $uri = $rawPath !== '' ? $rawPath : '/';
        $method = strtoupper($request->getMethod());

        $client = new Client([
            'base_uri' => $baseUrl,
            'http_errors' => false,
            'allow_redirects' => false,
            'decode_content' => false,
            'timeout' => max(1, (int) config('gigtune.wordpress.timeout_seconds', 180)),
        ]);

        $headers = $this->buildForwardHeaders($request, $baseUrl);
        $options = [
            'headers' => $headers,
            'query' => $request->query(),
        ];

        $contentType = strtolower((string) $request->header('Content-Type', ''));
        if ($this->isMultipart($contentType)) {
            $options['multipart'] = $this->buildMultipartPayload($request);
            unset($options['headers']['Content-Type']);
            unset($options['headers']['content-type']);
        } elseif ($this->isFormEncoded($contentType)) {
            $options['form_params'] = $request->request->all();
            unset($options['headers']['Content-Type']);
            unset($options['headers']['content-type']);
        } elseif (!in_array($method, ['GET', 'HEAD'], true)) {
            $rawBody = (string) $request->getContent();
            if ($rawBody !== '') {
                $options['body'] = $rawBody;
            }
        }

        try {
            $upstream = $client->request($method, $uri, $options);
        } catch (GuzzleException $exception) {
            return response(
                'WordPress bridge request failed: ' . $exception->getMessage(),
                502
            );
        }

        $frontendOrigin = $request->getSchemeAndHttpHost();
        $body = (string) $upstream->getBody();
        $body = str_replace($baseUrl, $frontendOrigin, $body);

        $response = response($body, $upstream->getStatusCode());
        foreach ($upstream->getHeaders() as $headerName => $values) {
            $normalized = strtolower($headerName);
            if ($this->isHopByHopHeader($headerName) || in_array($normalized, ['host', 'date'], true)) {
                continue;
            }
            foreach ($values as $value) {
                $headerValue = str_replace($baseUrl, $frontendOrigin, (string) $value);
                $response->headers->set($headerName, $headerValue, false);
            }
        }

        return $response;
    }

    private function proxyViaCgi(Request $request): Response
    {
        $wordpressRoot = (string) config('gigtune.wordpress.root', '');
        if ($wordpressRoot === '' || !is_dir($wordpressRoot)) {
            abort(500, 'WordPress root is not configured or does not exist.');
        }

        $indexPath = rtrim($wordpressRoot, '\\/') . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($indexPath)) {
            abort(500, 'WordPress index.php was not found.');
        }

        $phpCgiBinary = (string) config('gigtune.wordpress.cgi_binary', 'php-cgi');
        if ($phpCgiBinary === '') {
            abort(500, 'WordPress CGI binary is not configured.');
        }

        $rawRequestUri = (string) $request->server('REQUEST_URI', '/');
        $queryString = (string) (parse_url($rawRequestUri, PHP_URL_QUERY) ?? '');
        $rawBody = $this->extractRequestBodyForCgi($request);
        $contentType = (string) $request->header('Content-Type', '');

        $cgiEnv = $this->buildCgiEnvironment(
            $request,
            $wordpressRoot,
            $indexPath,
            $rawRequestUri,
            $queryString,
            $contentType,
            strlen($rawBody)
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $baseEnv = getenv();
        if (!is_array($baseEnv)) {
            $baseEnv = [];
        }

        $process = proc_open(
            [$phpCgiBinary, '-q'],
            $descriptorSpec,
            $pipes,
            $wordpressRoot,
            array_merge($baseEnv, $cgiEnv)
        );

        if (!is_resource($process)) {
            abort(502, 'WordPress CGI bridge could not start php-cgi.');
        }

        fwrite($pipes[0], $rawBody);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if (!is_string($stdout)) {
            $stdout = '';
        }
        if (!is_string($stderr)) {
            $stderr = '';
        }

        if ($exitCode !== 0 && $stdout === '') {
            Log::warning('WordPress CGI bridge failed to produce output.', [
                'exit_code' => $exitCode,
                'stderr' => mb_substr(trim($stderr), 0, 500),
            ]);
            return response(
                'WordPress CGI bridge failed: ' . trim($stderr),
                502
            );
        }

        [$rawHeaders, $body] = $this->splitCgiOutput($stdout);
        $parsedHeaders = $this->parseRawHeaders($rawHeaders);

        $statusCode = 200;
        foreach ($parsedHeaders as $headerName => $values) {
            if (strtolower((string) $headerName) !== 'status') {
                continue;
            }
            if (is_array($values) && isset($values[0])) {
                $statusCode = $this->parseStatusCode((string) $values[0]);
            }
            unset($parsedHeaders[$headerName]);
            break;
        }

        $baseUrl = rtrim((string) config('gigtune.wordpress.base_url', ''), '/');
        $frontendOrigin = $request->getSchemeAndHttpHost();
        if ($baseUrl !== '') {
            $body = str_replace($baseUrl, $frontendOrigin, $body);
        }

        $response = response($body, $statusCode);
        foreach ($parsedHeaders as $headerName => $values) {
            if ($this->isHopByHopHeader($headerName)) {
                continue;
            }

            foreach ($values as $value) {
                $headerValue = (string) $value;
                if ($baseUrl !== '') {
                    $headerValue = str_replace($baseUrl, $frontendOrigin, $headerValue);
                }
                $response->headers->set($headerName, $headerValue, false);
            }
        }

        return $response;
    }

    private function buildForwardHeaders(Request $request, string $baseUrl): array
    {
        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if ($this->isHopByHopHeader($name) || strtolower($name) === 'host') {
                continue;
            }
            $headers[$name] = implode(', ', $values);
        }

        $targetHost = parse_url($baseUrl, PHP_URL_HOST);
        $targetPort = parse_url($baseUrl, PHP_URL_PORT);
        if (is_string($targetHost) && $targetHost !== '') {
            $headers['Host'] = $targetPort ? $targetHost . ':' . $targetPort : $targetHost;
        }

        $headers['X-Laravel-WordPress-Bridge'] = '1';

        return $headers;
    }

    private function isHopByHopHeader(string $headerName): bool
    {
        return in_array(strtolower($headerName), [
            'connection',
            'keep-alive',
            'proxy-authenticate',
            'proxy-authorization',
            'te',
            'trailers',
            'transfer-encoding',
            'upgrade',
            'content-length',
        ], true);
    }

    private function isMultipart(string $contentType): bool
    {
        return Str::startsWith($contentType, 'multipart/form-data');
    }

    private function isFormEncoded(string $contentType): bool
    {
        return Str::startsWith($contentType, 'application/x-www-form-urlencoded');
    }

    private function buildMultipartPayload(Request $request): array
    {
        $parts = [];

        foreach ($request->request->all() as $name => $value) {
            $this->appendMultipartValue($parts, (string) $name, $value);
        }

        foreach ($request->allFiles() as $name => $fileValue) {
            $this->appendMultipartFile($parts, (string) $name, $fileValue);
        }

        return $parts;
    }

    private function extractRequestBodyForCgi(Request $request): string
    {
        $method = strtoupper($request->getMethod());
        if (in_array($method, ['GET', 'HEAD'], true)) {
            return '';
        }

        $raw = (string) $request->getContent();
        if ($raw !== '') {
            return $raw;
        }

        $contentType = strtolower((string) $request->header('Content-Type', ''));
        if ($this->isFormEncoded($contentType)) {
            return (string) http_build_query($request->request->all());
        }

        return '';
    }

    private function buildCgiEnvironment(
        Request $request,
        string $wordpressRoot,
        string $indexPath,
        string $requestUri,
        string $queryString,
        string $contentType,
        int $contentLength
    ): array {
        $host = (string) $request->getHost();
        $serverPort = (string) ($request->getPort() ?: ($request->isSecure() ? 443 : 80));
        $isHttps = $request->isSecure();

        $baseUrl = rtrim((string) config('gigtune.wordpress.base_url', ''), '/');
        if ($baseUrl !== '') {
            $baseHost = parse_url($baseUrl, PHP_URL_HOST);
            $basePort = parse_url($baseUrl, PHP_URL_PORT);
            $baseScheme = strtolower((string) (parse_url($baseUrl, PHP_URL_SCHEME) ?? ''));

            if (is_string($baseHost) && $baseHost !== '') {
                $host = $baseHost;
            }
            if (is_int($basePort) && $basePort > 0) {
                $serverPort = (string) $basePort;
            } elseif ($baseScheme === 'https') {
                $serverPort = '443';
            } elseif ($baseScheme === 'http') {
                $serverPort = '80';
            }
            if (in_array($baseScheme, ['http', 'https'], true)) {
                $isHttps = $baseScheme === 'https';
            }
        }

        $env = [
            'GATEWAY_INTERFACE' => 'CGI/1.1',
            'SERVER_SOFTWARE' => 'LaravelWordPressBridge/1.0',
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REQUEST_METHOD' => strtoupper((string) $request->getMethod()),
            'REQUEST_URI' => $requestUri !== '' ? $requestUri : '/',
            'QUERY_STRING' => $queryString,
            'SCRIPT_FILENAME' => $indexPath,
            'SCRIPT_NAME' => '/index.php',
            'DOCUMENT_ROOT' => $wordpressRoot,
            'SERVER_NAME' => $host,
            'SERVER_PORT' => $serverPort,
            'REMOTE_ADDR' => (string) ($request->ip() ?? '127.0.0.1'),
            'HTTPS' => $isHttps ? 'on' : 'off',
            'REDIRECT_STATUS' => '200',
            'HTTP_HOST' => $host . ':' . $serverPort,
        ];

        if ($contentType !== '') {
            $env['CONTENT_TYPE'] = $contentType;
        }
        if ($contentLength > 0) {
            $env['CONTENT_LENGTH'] = (string) $contentLength;
        }

        foreach ($request->headers->all() as $name => $values) {
            $lower = strtolower((string) $name);
            if (in_array($lower, ['content-type', 'content-length', 'host'], true)) {
                continue;
            }
            $key = 'HTTP_' . strtoupper(str_replace('-', '_', $lower));
            $env[$key] = implode(', ', $values);
        }

        return $env;
    }

    private function splitCgiOutput(string $output): array
    {
        $parts = preg_split("/\r\n\r\n|\n\n/", $output, 2);
        if (!is_array($parts) || count($parts) < 2) {
            return ['', $output];
        }

        return [(string) $parts[0], (string) $parts[1]];
    }

    private function parseRawHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = preg_split("/\r\n|\n|\r/", $rawHeaders);
        if (!is_array($lines)) {
            return $headers;
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode(':', $line, 2));
            if ($name === '') {
                continue;
            }

            if (!isset($headers[$name])) {
                $headers[$name] = [];
            }
            $headers[$name][] = $value;
        }

        return $headers;
    }

    private function parseStatusCode(string $statusHeader): int
    {
        if (preg_match('/^\s*(\d{3})\b/', $statusHeader, $matches) === 1) {
            $code = (int) $matches[1];
            if ($code >= 100 && $code <= 599) {
                return $code;
            }
        }

        return 200;
    }

    private function appendMultipartValue(array &$parts, string $name, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $childName = $name . '[' . $childKey . ']';
                $this->appendMultipartValue($parts, $childName, $childValue);
            }
            return;
        }

        if (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif (is_scalar($value) || $value === null) {
            $value = (string) ($value ?? '');
        } else {
            $value = json_encode($value);
        }

        $parts[] = [
            'name' => $name,
            'contents' => (string) $value,
        ];
    }

    private function appendMultipartFile(array &$parts, string $name, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $childName = $name . '[' . $childKey . ']';
                $this->appendMultipartFile($parts, $childName, $childValue);
            }
            return;
        }

        if (!($value instanceof UploadedFile) || !$value->isValid()) {
            return;
        }

        $path = $value->getRealPath();
        if ($path === false || !is_file($path)) {
            return;
        }

        $parts[] = [
            'name' => $name,
            'contents' => fopen($path, 'rb'),
            'filename' => $value->getClientOriginalName(),
            'headers' => [
                'Content-Type' => $value->getClientMimeType() ?: 'application/octet-stream',
            ],
        ];
    }
}

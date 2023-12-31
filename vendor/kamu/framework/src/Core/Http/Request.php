<?php

namespace Core\Http;

use Core\Facades\App;
use Core\File\UploadedFile;
use Core\Valid\Exception\ValidationException;
use Core\Valid\Validator;
use Exception;

/**
 * Request yang masuk.
 *
 * @class Request
 * @package \Core\Http
 */
class Request
{
    public const GET = 'GET';
    public const POST = 'POST';
    public const PUT = 'PUT';
    public const PATCH = 'PATCH';
    public const DELETE = 'DELETE';
    public const OPTIONS = 'OPTIONS';
    public const METHOD = '__method';

    /**
     * Data dari global request.
     *
     * @var Header $request
     */
    public $request;

    /**
     * Data dari global server.
     *
     * @var Header $server
     */
    public $server;

    /**
     * Uploaded file.
     *
     * @var Header $file
     */
    public $file;

    /**
     * Init objek.
     *
     * @return void
     */
    public function __construct()
    {
        $this->server = new Header($_SERVER);

        if ($this->ajax()) {
            $raw = @file_get_contents('php://input');
            if ($raw) {
                $json = @json_decode($raw, true, 1024) ?? [];
                if ($json) {
                    $_REQUEST = [...$_REQUEST, ...$json];
                }
            }
        }

        $this->request = new Header($_REQUEST);
        $this->file = new Header(UploadedFile::parse($_FILES));
    }

    /**
     * Get bearer token from header.
     *
     * @return string|null
     */
    public function bearerToken(): string|null
    {
        $auth = $this->server->get('HTTP_AUTHORIZATION');
        if (!$auth) {
            return null;
        }

        return trim(substr($auth, 6));
    }

    /**
     * Http method.
     *
     * @param string|null $method
     * @return string|bool
     */
    public function method(string|null $method = null): string|bool
    {
        $current = strtoupper($this->server->get('REQUEST_METHOD'));
        if (!$method) {
            return $current;
        }

        return $current == $method;
    }

    /**
     * Dapatkan ipnya.
     *
     * @return string|null
     */
    public function ip(): string|null
    {
        if ($this->server->get('HTTP_CLIENT_IP')) {
            return $this->server->get('HTTP_CLIENT_IP');
        }

        if ($this->server->get('HTTP_X_FORWARDED_FOR')) {
            $ipList = explode(',', $this->server->get('HTTP_X_FORWARDED_FOR'));
            foreach ($ipList as $ip) {
                if (!empty($ip)) {
                    return $ip;
                }
            }
        }

        $lists = [
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($lists as $value) {
            $ipValue = $this->server->get($value);
            if ($ipValue) {
                return $ipValue;
            }
        }

        return null;
    }

    /**
     * Cek apakah ajax atau fetch?.
     *
     * @return string|bool
     */
    public function ajax(): string|bool
    {
        if (
            str_contains(strtolower($this->server->get('HTTP_ACCEPT', '')), 'json')
            || str_contains(strtolower($this->server->get('CONTENT_TYPE') ?? ''), 'json')
        ) {
            if ($this->server->get('HTTP_TOKEN')) {
                return $this->server->get('HTTP_TOKEN');
            }

            return true;
        }

        if ($this->server->get('CONTENT_TYPE') && $this->server->get('HTTP_COOKIE') && $this->server->get('HTTP_TOKEN')) {
            return $this->server->get('HTTP_TOKEN');
        }

        return false;
    }

    /**
     * Tampilkan error secara manual.
     *
     * @param array<string, string>|Validator $error
     * @return void
     *
     * @throws Exception
     */
    public function throw(array|Validator $error): void
    {
        $validator = null;

        if ($error instanceof Validator) {
            if (App::get()->has(Validator::class)) {
                throw new Exception('Terdapat 2 object validator !');
            }

            $validator = $error;
        } else {
            $validator = App::get()->singleton(Validator::class);
            $validator->throw($error);
        }

        if ($validator->fails()) {
            throw new ValidationException($this, $validator);
        }
    }

    /**
     * Validasi request yang masuk.
     *
     * @param array<string, array<int, string>> $params
     * @return array<string, mixed>
     */
    public function validate(array $params = []): array
    {
        $key = array_keys($params);

        $data = [];
        foreach ($key as $value) {
            if ($this->request->has($value)) {
                $data[$value] = $this->request->get($value);
            } elseif ($this->file->has($value)) {
                $data[$value] = $this->file->get($value);
            }
        }

        $validator = App::get()->make(Validator::class, [$data, $params]);

        if ($validator->fails()) {
            throw new ValidationException($this, $validator);
        }

        foreach ($key as $k) {
            $this->__set($k, $validator->get($k));
        }

        return $this->only($key);
    }

    /**
     * Ambil file yang masuk.
     *
     * @param string $name
     * @return UploadedFile|array<int, UploadedFile>
     */
    public function file(string $name): UploadedFile|array
    {
        return $this->file->get($name);
    }

    /**
     * Ambil semua nilai dari request ini.
     *
     * @return array
     */
    public function all(): array
    {
        return $this->request->all();
    }

    /**
     * Get with key.
     *
     * @param string $name
     * @param mixed $defaultValue
     * @return mixed
     */
    public function get(string $name, mixed $defaultValue = null): mixed
    {
        return $this->__get($name) ?? $defaultValue;
    }

    /**
     * Ambil sebagian dari request.
     *
     * @param array $only
     * @return array
     */
    public function only(array $only): array
    {
        return $this->request->only($only);
    }

    /**
     * Ambil kecuali dari request.
     *
     * @param array $except
     * @return array
     */
    public function except(array $except): array
    {
        return $this->request->except($except);
    }

    /**
     * Ambil nilai dari request ini.
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->__isset($name) ? $this->request->__get($name) : null;
    }

    /**
     * Isi nilai ke request ini.
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, mixed $value): void
    {
        $this->request->__set($name, $value);
    }

    /**
     * Cek nilai dari request ini.
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return $this->request->__isset($name);
    }
}

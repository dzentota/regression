<?php
declare(strict_types=1);

namespace Regression;

final class Config
{
    public const DEFAULT_LICENSE = 'DEFAULT_LICENSE';

    private array $data;

    public static function create(array $array): self
    {
        $config = new self();
        foreach ($array as $param => $value) {
            $config->set($param, $value);
        }
        return $config;
    }

    public function set(string $param, $value): self
    {
        $this->data[$param] = $value;
        return $this;
    }

    public function get(string $param, $default = null)
    {
        return $this->data[$param]?? $default;
    }

    public function getBaseUri(): string
    {
        if (str_ends_with($this->data['baseUri'], '/')) {
            return $this->data['baseUri'];
        }

        return $this->data['baseUri'] . '/';
    }

    public function getLicense(): string
    {
        return $this->data['license'];
    }

    /**
     * Identifier of the current suite run; used to tag data created by scenarios.
     */
    public function getRunId(): string
    {
        if (empty($this->data['runId'])) {
            $this->data['runId'] = 'rg' . bin2hex(random_bytes(3));
        }
        return $this->data['runId'];
    }

    public function getUserPassword(string $username, $default = null): ?string
    {
        return $this->data['credentials'][$username] ?? $default;
    }

    public function __get(string $param)
    {
        $method = 'get' . $param;
        if (is_callable([$this, $method])) {
            return $this->{$method}();
        }
        return $this->get($param);
    }
}
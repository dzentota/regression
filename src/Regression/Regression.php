<?php
declare(strict_types=1);

namespace Regression;

/**
 * Class Scenario
 * @package Regression
 */
abstract class Regression extends Scenario
{
    /**
     * @return string
     */
    abstract public function getRegressionDescription(): string;

    public function getDescription(): string
    {
        return $this->getRegressionDescription();
    }

    /**
     * @param string $substring
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     */
    public function expectSubstring(string $substring, ?string $errorMessage = null): self
    {
        $content = (string)$this->lastResponse->getBody();
        if (false === strpos($content, $substring)) {
            $this->throwException($errorMessage ?? "The response does NOT contain substring '$substring'");
        }
        $this->status = Status::NO_ISSUE;
        return $this;
    }

    /**
     * @param string $regexp
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     */
    public function expectRegexp(string $regexp, ?string $errorMessage = null): self
    {
        if (!preg_match($regexp, (string)$this->lastResponse->getBody())) {
            $this->throwException($errorMessage ?? "The response does NOT match regexp '$regexp'");
        }
        $this->status = Status::NO_ISSUE;
        return $this;
    }

    /**
     * @param callable $callback
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     */
    public function expect(callable $callback, ?string $errorMessage = null): self
    {
        if (false === $callback($this->lastResponse)) {
            $this->throwException($errorMessage ?? 'The response does NOT fit expectations');
        }
        $this->status = Status::NO_ISSUE;
        return $this;
    }

    /**
     * @param int $status
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     */
    public function expectStatusCode(int $status, ?string $errorMessage = null): self
    {
        if ($this->lastResponse->getStatusCode() !== $status) {
            $this->throwException($errorMessage ?? sprintf('%s status code is expected, %s given', $status, $this->lastResponse->getStatusCode()));
        }
        $this->status = Status::NO_ISSUE;
        return $this;
    }

    /**
     * @param string $variableName
     * @param string $regexp
     * @param int $group
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     */
    public function extractRegexp(
        string $variableName,
        string $regexp,
        int $group = 1,
        ?string $errorMessage = null
    ): self {
        if (!preg_match($regexp, (string)$this->lastResponse->getBody(), $m)) {
            $this->throwException($errorMessage ?? "The response does NOT match regexp '$regexp'");
        }
        $this->status = Status::NO_ISSUE;
        $this->vars[$variableName] = $m[$group];
        return $this;
    }

    /**
     * @param string $variableName
     * @param callable $callback
     * @param string|null $errorMessage
     * @return $this
     * @throws RegressionException
     */
    public function extract(
        string $variableName,
        callable $callback,
        ?string $errorMessage = null
    ): self {
        if (false === ($value = $callback($this->lastResponse))) {
            $this->throwException($errorMessage ?? 'Can not extract variable with the provided callback');
        }
        $this->status = Status::NO_ISSUE;
        $this->vars[$variableName] = $value;
        return $this;
    }

    /**
     * @param string $message
     * @return mixed
     * @throws RegressionException
     */
    protected function throwException(string $message)
    {
        $this->status = Status::HAS_ISSUE;
        $this->conclusion = $message;
        throw new RegressionException($message);
    }

    /**
     * Fails if script injected by this scenario executes when $url is rendered in a browser.
     *
     * @param array $options see Scenario::browse()
     */
    public function expectNoScriptExecution(string $url, ?string $errorMessage = null, array $options = []): self
    {
        if (!empty($options['allowMissingPayload'])) {
            $options['markerRetries'] ??= 0; // the payload isn't expected on the page: don't reload looking for it
        }
        $result = $this->browse($url, $options);
        $inPage = array_sum((array)($result['markersInPage'] ?? []));
        if (empty($result['hits']) && !empty($result['markersInPage']) && $inPage === 0 && empty($options['allowMissingPayload'])) {
            $this->throwException(
                "Payload not found on $url: the page doesn't show the injected data, so the test can't tell whether it is escaped"
                . ' (wrong page, data not saved, or still cached). Pass allowMissingPayload to accept.'
            );
        }
        if (!empty($result['hits'])) {
            $hits = implode('; ', array_map(fn($h) => "{$h['type']}: {$h['message']}", $result['hits']));
            $this->throwException(($errorMessage ?? 'Injected script executed') . " at $url ($hits)");
        }
        $this->status = Status::NO_ISSUE;
        return $this;
    }
}

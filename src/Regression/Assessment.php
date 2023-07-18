<?php
declare(strict_types=1);

namespace Regression;

/**
 * Class Assessment
 * @package Regression
 */
abstract class Assessment extends Scenario
{
    /**
     * @return string
     */
    abstract public function getAssessmentDescription(): string;

    public function getDescription(): string
    {
        return $this->getAssessmentDescription();
    }

    /**
     * @param string $variableName
     * @param string $regexp
     * @param int $group
     * @return $this
     */
    public function extractRegexp(
        string &$variableName,
        string $regexp,
        int $group = 1
    ): self {
        if (preg_match($regexp, (string)$this->lastResponse->getBody(), $m)) {
            $variableName = $m[$group];
        }
        return $this;
    }

    /**
     * @param string $variableName
     * @param callable $callback
     * @return $this
     */
    public function extract(
        string &$variableName,
        callable $callback
    ): self {
        if (false === ($value = $callback($this->lastResponse))) {
            $variableName = $value;
        }
        return $this;
    }

    /**
     * @param string $substring
     * @param $result
     * @return $this
     */
    public function assumeSubstring(string $substring, &$result): self
    {
        $content = (string)$this->lastResponse->getBody();
        $result =  false !== strpos($content, $substring);
        return $this;
    }

    /**
     * @param string $regexp
     * @param $result
     * @return $this
     */
    public function assumeRegexp(string $regexp, &$result): self
    {
        $result = (bool)preg_match($regexp, (string)$this->lastResponse->getBody());
        return $this;
    }

    /**
     * @param callable $callback
     * @param $result
     * @return $this
     */
    public function assume(callable $callback, &$result): self
    {
        $result = (bool)$callback($this->lastResponse);
        return $this;
    }

    public function checkAssumptions(string $conclusion, ... $assumptions)
    {
        if (count(array_filter($assumptions))) {
            $this->status = Status::HAS_ISSUE;
            $this->conclusion = $conclusion;
        } else {
            $this->status = Status::NO_ISSUE;
        }
    }
}
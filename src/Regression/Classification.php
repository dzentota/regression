<?php
declare(strict_types=1);

namespace Regression;

final class Classification
{
    private string $cvssMetrics;
    private int $cweId;

    /**
     * @return string
     */
    public function getCvssMetrics(): string
    {
        return $this->cvssMetrics;
    }

    /**
     * @return string
     */
    public function getCwe(): string
    {
        return 'CWE-' . $this->cweId;
    }

    public static function create(): self
    {
        return new self();
    }

    /**
     * @param string $cvssMetrics
     * @return $this
     */
    public function withCvssMetrics(string $cvssMetrics): self
    {
        //@todo add validation, expected format: CVSS:3.0/AV:N/AC:L/PR:N/UI:N/S:C/C:H/I:H/A:H
        $this->cvssMetrics = $cvssMetrics;
        return $this;
    }

    /**
     * @param int $cweId
     * @return $this
     */
    public function withCweId(int $cweId): self
    {
        $this->cweId = $cweId;
        return $this;
    }
}

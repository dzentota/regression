<?php

namespace Regression\Helpers\Output;

use Symfony\Component\Console\Style\OutputStyle;

class QueueOutput
{
    private ?QueueOutputItem $head = null;
    private ?QueueOutputItem $rear = null;

    public function push(QueueOutputItem $item): void
    {
        if ($this->head === null) {
            $this->head = $item;
            $this->rear = $item;
        } else {
            $this->rear->next = $item;
            $this->rear = $this->rear->next;
        }
    }

    public function clear(): void
    {
        $this->head = null;
        $this->rear = null;
    }

    public function flushToOutput(OutputStyle $io): void
    {
        $curItem = $this->head;

        while ($curItem !== null) {
            $curItem->execOutput($io)();

            $curItem = $curItem->next;
        }

        $this->clear();
    }
}

<?php

/**
 * This is a modified version of the LineReader from Amp except with a restriction on buffer memory
 */
namespace codesand;

use Amp\Promise;
use function Amp\call;

final class SafeLineReader
{
    /** @var string */
    private $delimiter;

    /** @var bool */
    private $lineMode;

    /** @var string */
    private $buffer = "";

    /** @var \Amp\ByteStream\InputStream */
    private $source;

    private int $maxBuffer;

    public function __construct(\Amp\ByteStream\InputStream $inputStream, string $delimiter = null, $maxBuffer = 4096)
    {
        $this->source = $inputStream;
        $this->delimiter = $delimiter === null ? "\n" : $delimiter;
        $this->lineMode = $delimiter === null;
        $this->maxBuffer = $maxBuffer;
    }

    /**
     * @return Promise<string|null>
     */
    public function readLine(): Promise
    {
        return call(function () {
            if (false !== \strpos($this->buffer, $this->delimiter)) {
                list($line, $this->buffer) = \explode($this->delimiter, $this->buffer, 2);
                return $this->lineMode ? \rtrim($line, "\r") : $line;
            }

            while (null !== $chunk = yield $this->source->read()) {
                //For simplicity we will just roll it for now
                //TODO Buffer could end up being bigger than max, but i just want to prevent a OOM right now
                if(strlen($this->buffer) < $this->maxBuffer)
                    $this->buffer .= $chunk;
                else {
                    if(strlen($this->buffer) > strlen($chunk))
                        $this->buffer = substr($this->buffer, strlen($chunk));
                    else
                        $this->buffer = '';
                    $this->buffer .= $chunk;
                }

                if (false !== \strpos($this->buffer, $this->delimiter)) {
                    list($line, $this->buffer) = \explode($this->delimiter, $this->buffer, 2);
                    return $this->lineMode ? \rtrim($line, "\r") : $line;
                }
            }

            if ($this->buffer === "") {
                return null;
            }

            $line = $this->buffer;
            $this->buffer = "";
            return $this->lineMode ? \rtrim($line, "\r") : $line;
        });
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * @return void
     */
    public function clearBuffer()
    {
        $this->buffer = "";
    }
}
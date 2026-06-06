<?php

namespace draw;

class MergePrimitive implements FilterPrimitive
{
    private readonly ?string $inputName;

    public function __construct(
        private readonly array $mergeInputs,
        private readonly ?string $result = null,
    ) {
        $this->inputName = $mergeInputs[0] ?? null;
    }

    public function getInput(): ?string
    {
        return $this->inputName;
    }

    public function getResult(): ?string
    {
        return $this->result;
    }

    public function apply(Canvas $input, FilterPipeline $pipeline): Canvas
    {
        if (empty($this->mergeInputs)) {
            $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
            if ($this->result !== null) {
                $pipeline->setResult($this->result, $output);
            }
            return $output;
        }

        $lastInput = $pipeline->getResult($this->mergeInputs[count($this->mergeInputs) - 1]);
        if ($lastInput === null) {
            $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
            if ($this->result !== null) {
                $pipeline->setResult($this->result, $output);
            }
            return $output;
        }

        $output = Canvas::createBlank($lastInput->w, $lastInput->h, $lastInput->halfblocks);
        Compositor::blend($output, $lastInput);

        for ($i = count($this->mergeInputs) - 2; $i >= 0; $i--) {
            $layer = $pipeline->getResult($this->mergeInputs[$i]);
            if ($layer !== null) {
                Compositor::blend($output, $layer);
            }
        }

        if ($this->result !== null) {
            $pipeline->setResult($this->result, $output);
        }

        return $output;
    }
}

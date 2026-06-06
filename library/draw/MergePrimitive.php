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

        $firstInput = $pipeline->getResult($this->mergeInputs[0]);
        if ($firstInput === null) {
            $output = Canvas::createBlank($input->w, $input->h, $input->halfblocks);
            if ($this->result !== null) {
                $pipeline->setResult($this->result, $output);
            }
            return $output;
        }

        $output = Canvas::createBlank($firstInput->w, $firstInput->h, $firstInput->halfblocks);
        Compositor::blend($output, $firstInput);

        for ($i = 1; $i < count($this->mergeInputs); $i++) {
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

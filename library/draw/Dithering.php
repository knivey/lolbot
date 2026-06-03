<?php

namespace draw;

enum Dithering
{
    case None;
    case Ordered4x4;
    case ShaderBlocks;
}

<?php
declare(strict_types=1);

namespace CodeX\Router;

final class VariableSegment
{
    public string $pattern;
    public string $paramName;
    public Node $node;

    public function __construct(string $regex, string $paramName)
    {
        $this->paramName = $paramName;
        $this->pattern = '/^' . str_replace('/', '\/', $regex) . '$/u';
        $this->node = new Node();
    }
}
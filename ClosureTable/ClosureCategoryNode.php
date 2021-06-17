<?php

namespace App\Model;

final class ClosureCategoryNode
{
    private array $children = [];

    private ClosureCategory $category;

    public function __construct(ClosureCategory $category)
    {
        $this->category = $category;
    }

    public function getCategory(): ClosureCategory
    {
        return $this->category;
    }

    public function addChild(ClosureCategoryNode $child): ClosureCategoryNode
    {
        $this->children[] = $child;
        return $this;
    }

    public function toArray(): array
    {
        $children = [];

        foreach ($this->children as $child) {
            $children[] = $child->toArray();
        }

        return array_merge(
            $this->category->toArray(),
            ['children' => $children]
        );
    }
}
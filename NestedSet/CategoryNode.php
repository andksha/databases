<?php

namespace App\Model;

final class CategoryNode
{
    /** @var CategoryNode[] array  */
    private array $children = [];

    private CategoryNestedSet $category;

    public function __construct(CategoryNestedSet $category)
    {
        $this->category = $category;
    }

    public function addChild(CategoryNode $child): CategoryNode
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
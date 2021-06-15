<?php

namespace App\Model;

final class CategoryTree
{
    private array $lvls = [];

    /** @var CategoryNode[] array  */
    private array $roots = [];

    public function setLastNodeOfLvl(CategoryNode $node, int $lvl): CategoryTree
    {
        $this->lvls[$lvl] = $node;
        return $this;
    }

    public function getLastNodeOfLvl(int $lvl): CategoryNode
    {
        return $this->lvls[$lvl];
    }

    public function addRoot(CategoryNode $root): CategoryTree
    {
        $this->roots[] = $root;
        return $this;
    }

    public function toArray(): array
    {
        $a = [];

        foreach ($this->roots as $root) {
            $a[] = $root->toArray();
        }

        return $a;
    }
}
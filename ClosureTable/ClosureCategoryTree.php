<?php

namespace App\Model;

final class ClosureCategoryTree
{
    private array $roots;
    private array $nodes;

    public function addRoot(ClosureCategoryNode $node): void
    {
        $this->roots[$node->getCategory()->parent_id] = $node;
        $this->addNode($node);
    }

    public function addNode(ClosureCategoryNode $node): void
    {
        $this->nodes[$node->getCategory()->parent_id] = $node;
    }

    public function getNode(int $id): ?ClosureCategoryNode
    {
        return $this->nodes[$id] ?? null;
    }

    public function getNodes(): array
    {
        return array_map(function ($n) {
            return $n->toArray();
        }, $this->nodes);
    }

    public function toArray(): array
    {
        $a = [];

        /** @var ClosureCategoryNode $root */
        foreach ($this->roots as $root) {
            $a[] = $root->toArray();
        }

        return $a;
    }
}
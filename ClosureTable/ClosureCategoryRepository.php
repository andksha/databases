<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class ClosureCategoryRepository
{
    public function getMany(): array
    {
        $categories = ClosureCategory::query()->fromRaw('categories_closures cc')
            ->whereRaw('cc.parent_id = cc.child_id')
            ->get();

        return $this->mapCategories($categories);
    }

    public function lastLvl(): array
    {
        return ClosureCategory::query()->fromRaw('categories_closures cc1')
            ->whereNotExists(function ($query) {
                $query->fromRaw('categories_closures cc2')
                    ->whereColumn('cc2.immediate_parent_id', 'cc1.parent_id');
            })
            ->orderBy('parent_id')
            ->get()
            ->toArray();
    }

    public function getOne(int $id): array
    {
        $categories = ClosureCategory::query()->selectRaw('cc1.*')
            ->from(DB::raw('categories_closures cc1'))
            ->join(DB::raw('categories_closures cc2'), function (JoinClause $join) use ($id) {
                $join->on(function (JoinClause $join) use ($id) {
                    $join->on('cc1.parent_id', '=', 'cc2.parent_id')
                        ->where('cc2.child_id', '=', $id);
                })->orOn(function (JoinClause $join) use ($id) {
                    $join->on('cc1.child_id', '=', 'cc2.child_id')
                        ->where('cc2.parent_id', '=', $id);
                });
            })
            ->whereRaw('cc1.parent_id = cc1.child_id')
            ->get();

        return $this->mapCategories($categories);
    }

    private function mapCategories(Collection $categories): array
    {
        $categoryTree = new ClosureCategoryTree();

        /** @var ClosureCategory $category */
        foreach ($categories as $category) {
            if ($category->parent_id === $category->immediate_parent_id) {
                $categoryTree->addRoot(new ClosureCategoryNode($category));
                continue;
            }

            if (!$categoryTree->getNode($category->immediate_parent_id)) {
                $categories->push($category);
            } else {
                $node = new ClosureCategoryNode($category);
                $categoryTree->addNode($node);
                $categoryTree->getNode($category->immediate_parent_id)->addChild($node);
            }
        }

        return $categoryTree->toArray();
    }

    public function add(?int $parentID, string $name): Category
    {
        $category = new Category(['name' => $name]);
        $category->save();

        return $this->addToTree($parentID, $category);
    }

    private function addToTree(?int $parentID, Category $category): Category
    {
        ClosureCategory::query()->insert($this->getInsertArray(
            $category->id,
            $category->id,
            $parentID ?? $category->id
        ));

        if (!$parentID) {
            return $category;
        }

        return $this->addAsChild($parentID, $category);
    }

    private function addAsChild(int $parentID, Category $category): Category
    {
        $parentsOfParents = ClosureCategory::query()
            ->where('child_id', $parentID)
            ->get();

        $toInsert = [];

        /** @var ClosureCategory $parentOfParent */
        foreach ($parentsOfParents as $parentOfParent) {
            $toInsert[] = $this->getInsertArray(
                $parentOfParent->parent_id,
                $category->id,
                $parentOfParent->immediate_parent_id,
            );
        }

        ClosureCategory::query()->insert($toInsert);

        return $category;
    }

    // delete category moving it's children up
    public function delete(int $id): bool
    {
        /** @var ClosureCategory $closureCategory */
        $closureCategory = ClosureCategory::query()->where('parent_id', $id)
            ->where('child_id', $id)
            ->first();
        if (!$closureCategory) {
            return false;
        }

        $this->updateChildrenImmediateParentId($closureCategory);

        return ClosureCategory::query()->where('parent_id', $closureCategory->parent_id)
            ->orWhere('child_id', $closureCategory->parent_id)
            ->delete();
    }

    private function updateChildrenImmediateParentId(ClosureCategory $oldParent): void
    {
        /** @var ClosureCategory $immediateParent */
        $immediateParent = ClosureCategory::query()->where('parent_id', $oldParent->immediate_parent_id)
            ->where('child_id', $oldParent->immediate_parent_id)
            ->where('immediate_parent_id', '!=', $oldParent->parent_id)
            ->first();
        if (!$immediateParent) {
            $newImmediateParentId = DB::raw('parent_id');
        } else {
            $newImmediateParentId = $immediateParent->parent_id;
        }

        ClosureCategory::query()->where('immediate_parent_id', $oldParent->parent_id)
            ->update(['immediate_parent_id' => $newImmediateParentId]);
    }

    public function move(int $id, ?int $parentId): bool
    {
        /** @var Category $category */
        $category = Category::query()->where('id', $id)->first();
        if (!$category) {
            return false;
        }

        return $this->delete($id) && $this->addToTree($parentId, $category);
    }

    private function getInsertArray(int $parentId, int $childId, int $immediateParentId): array
    {
        return [
            'parent_id' => $parentId,
            'child_id' => $childId,
            'immediate_parent_id' => $immediateParentId,
        ];
    }
}
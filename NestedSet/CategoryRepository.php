<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CategoryRepository
{
    public function getMany(): array
    {
        $category = CategoryNestedSet::query()->orderBy('lft')->get();

        return $this->mapCategories($category);
    }

    public function lastLvl(): array
    {
        return CategoryNestedSet::query()->whereRaw('"rgt" - "lft" = 1')->get()->toArray();
    }

    public function getOne(int $id): array
    {
        $category = CategoryNestedSet::query()->where('id', $id)
            ->with(['attributes'])
            ->first();

        $categories = CategoryNestedSet::query()->where('lft', '<', $category->lft)
            ->where('rgt', '>', $category->rgt)
            ->get()
            ->merge([$category])
            ->sortBy('lft');

        return $this->mapCategories($categories);
    }

    private function mapCategories(Collection $categories): array
    {
        $tree = new CategoryTree();
        $minDepth = $categories->min('depth');

        /** @var CategoryNestedSet $value */
        foreach ($categories as $key => $value) {
            $node = new CategoryNode($value);
            $tree->setLastNodeOfLvl($node, $value->depth);

            if ($key === 0 || $value->depth === $minDepth) {
                $tree->addRoot($node);
                continue;
            }

            $tree->getLastNodeOfLvl($value->depth - 1)->addChild($node);
        }

        return $tree->toArray();
    }

    public function add(?int $parentID, string $name): CategoryNestedSet
    {
        if (!$parentID) {
            return $this->addRoot($name);
        }

        return $this->addNode($parentID, $name);
    }

    private function addRoot(string $name): CategoryNestedSet
    {
        if (!$max = CategoryNestedSet::query()->max('rgt')) {
            return CategoryNestedSet::query()->create([
                "parent_id" => null,
                "depth" => 1,
                "name" => $name,
                "slug" => Str::slug($name),
                "lft" => 1,
                "rgt" => 1,
            ]);
        }

        return CategoryNestedSet::query()->create([
            "parent_id" => null,
            "depth" => 1,
            "name" => $name,
            "slug" => Str::slug($name),
            "lft" => $max + 1,
            "rgt" => $max + 2,
        ]);
    }

    private function addNode(int $parentID, string $name): CategoryNestedSet
    {
        $parent = CategoryNestedSet::query()->where('id', $parentID)->first();
        CategoryNestedSet::query()->where('lft', '>=', $parent->rgt)->update(['lft' => DB::raw('"lft" + 2')]);
        CategoryNestedSet::query()->where('rgt', '>=', $parent->rgt)->update(['rgt' => DB::raw('"rgt" + 2')]);

        return CategoryNestedSet::query()->create([
            "parent_id" => $parentID,
            "depth" => $parent->depth + 1,
            "name" => $name,
            "slug" => Str::slug($name),
            "lft" => $parent->rgt,
            "rgt" => $parent->rgt + 1,
        ]);
    }

    public function delete(int $id): bool
    {
        $category = CategoryNestedSet::query()->where('id', $id)->first();

        // assign $category's parent id to it's children, decrement lft and rgt by one, decrement depth
        CategoryNestedSet::query()->where('lft', '>', $category->lft)
            ->where('rgt', '<', $category->rgt)
            ->update([
                'parent_id' => $category->parent_id,
                'lft' => DB::raw('"lft" - 1'),
                'rgt' => DB::raw('"rgt" - 1'),
                'depth' => DB::raw('"depth" - 1'),
            ]);

        // decrement rgt by 2 where rgt > $category->rgt
        CategoryNestedSet::query()->where('rgt', '>', $category->rgt)->update([
            'rgt' => DB::raw('"rgt" - 2'),
        ]);
        // decrement lft by 2 where lft > $category->rgt
        CategoryNestedSet::query()->where('lft', '>', $category->rgt)->update([
            'lft' => DB::raw('"lft" - 2'),
        ]);

        return $category->delete();
    }

    public function move(int $id, ?int $parentId): bool
    {
        $category = CategoryNestedSet::query()->where('id', $id)->first();
        $max = CategoryNestedSet::query()->max('rgt');

        if (!$parentId) {
            $category->update([
                'depth' => 1,
                'parent_id' => null,
                'lft' => $max + 1,
                'rgt' => $max + 2
            ]);
        }

        if (!$parent = CategoryNestedSet::query()->where('id', $parentId)->first()) {
            return false;
        }

        if ($parent->rgt > $category->rgt) {
            CategoryNestedSet::query()->where('lft', '>', $category->lft)
                ->where('lft', '<', $parent->rgt)
                ->update([
                    'lft' => DB::raw('"lft" - 2'),
                ]);
            CategoryNestedSet::query()->where('rgt', '>', $category->lft)
                ->where('rgt', '<', $parent->rgt)
                ->update([
                    'rgt' => DB::raw('"rgt" - 2'),
                ]);

            $category->update([
                'depth' => $parent->depth + 1,
                'parent_id' => $parent->id,
                'lft' => $parent->rgt - 2,
                'rgt' => $parent->rgt - 1,
            ]);
        } elseif ($parent->rgt < $category->rgt) {
            CategoryNestedSet::query()->where('lft', '>', $parent->rgt)
                ->where('lft', '<', $category->rgt)
                ->update([
                    'lft' => DB::raw('"lft" + 2'),
                ]);
            CategoryNestedSet::query()->where('rgt', '>=', $parent->rgt)
                ->where('rgt', '<', $category->rgt)
                ->update([
                    'rgt' => DB::raw('"rgt" + 2'),
                ]);

            $category->update([
                'depth' => $parent->depth + 1,
                'parent_id' => $parent->id,
                'lft' => $parent->rgt,
                'rgt' => $parent->rgt + 1,
            ]);
        }

        return true;
    }

        public function moveBranch(int $id, ?int $newParentId): bool
    {
        $category = CategoryNestedSet::query()->where('id', $id)->first();
        $oldLeftRightDiff = $category->right - $category->left;

        if (!$newParentId) {
            $maxRight = CategoryNestedSet::query()->max('right');
            $newMaxRight = $maxRight - $oldLeftRightDiff - 1;
            $newOldDiff = $newMaxRight + 1 - $category->left;

            $leftToUpdate = CategoryNestedSet::query()->where('left', '>', $category->right)->pluck('id');
            $rightToUpdate = CategoryNestedSet::query()->where('right', '>', $category->right)->pluck('id');

            $depthDiff = $category->depth - 1;

            CategoryNestedSet::query()->where('left', '>', $category->left)
                ->where('right', '<', $category->right)
                ->update([
                    'left' => DB::raw('"left" + ' . $newOldDiff),
                    'right' => DB::raw('"right" + ' . $newOldDiff),
                    'depth' => DB::raw('"depth" - ' . $depthDiff)
                ]);
            $category->update([
                'depth' => 1,
                'parent_id' => null,
                'left' => $newMaxRight + 1,
                'right' => $newMaxRight + $oldLeftRightDiff + 1,
            ]);

            CategoryNestedSet::query()->whereIn('id', $leftToUpdate)->update([
                    'left' => DB::raw('"left" - ' . ($oldLeftRightDiff + 1)),
                ]);
            CategoryNestedSet::query()->whereIn('id', $rightToUpdate)->update([
                    'right' => DB::raw('"right" - ' . ($oldLeftRightDiff + 1)),
                ]);

            return true;
        }

        if (!$newParent = CategoryNestedSet::query()->where('id', $newParentId)->first()) {
            return false;
        }

        if ($newParent->right > $category->right) {
            $newOldDiff = $newParent->right - 1 - $category->right;
            $depthDiff = $newParent->depth + 1 - $category->depth;

            $leftToUpdate = CategoryNestedSet::query()->where('left', '>', $category->right)
                ->where('left', '<', $newParent->right)
                ->pluck('id');
            $rightToUpdate = CategoryNestedSet::query()->where('right', '>', $category->right)
                ->where('right', '<', $newParent->right)
                ->pluck('id');

            CategoryNestedSet::query()->where('left', '>', $category->left)
                ->where('right', '<', $category->right)
                ->update([
                    'left' => DB::raw('"left" + ' . $newOldDiff),
                    'right' => DB::raw('"right" + ' . $newOldDiff),
                    'depth' => DB::raw('"depth" + ' . $depthDiff),
                ]);

            $category->update([
                'depth' => $newParent->depth + 1,
                'parent_id' => $newParent->id,
                'left' => $newParent->right - $oldLeftRightDiff - 1,
                'right' => $newParent->right - 1,
            ]);

            CategoryNestedSet::query()->whereIn('id', $leftToUpdate)->update([
                'left' => DB::raw('"left" - ' . ($oldLeftRightDiff + 1)),
            ]);
            CategoryNestedSet::query()->whereIn('id', $rightToUpdate)->update([
                'right' => DB::raw('"right" - ' . ($oldLeftRightDiff + 1)),
            ]);
        } elseif ($newParent->right < $category->right) {
            $newOldDiff = $category->left - $newParent->right;
            $depthDiff = ($newParent->depth + 1 - $category->depth) * ($newParent->depth > $category->depth ?: -1);

            $leftToUpdate = CategoryNestedSet::query()->where('left', '<', $category->left)
                ->where('left', '>', $newParent->right)
                ->pluck('id');
            $rightToUpdate = CategoryNestedSet::query()->where('right', '<', $category->left)
                ->where('right', '>=', $newParent->right)
                ->pluck('id');

            CategoryNestedSet::query()->where('left', '>', $category->left)
                ->where('right', '<', $category->right)
                ->update([
                    'left' => DB::raw('"left" - ' . $newOldDiff),
                    'right' => DB::raw('"right" - ' . $newOldDiff),
                    'depth' => DB::raw('"depth" - ' . $depthDiff),
                ]);

            $category->update([
                'depth' => $newParent->depth + 1,
                'parent_id' => $newParent->id,
                'left' => $newParent->right,
                'right' => $newParent->right + $oldLeftRightDiff,
            ]);

            CategoryNestedSet::query()->whereIn('id', $leftToUpdate)->update([
                'left' => DB::raw('"left" + ' . ($oldLeftRightDiff + 1)),
            ]);
            CategoryNestedSet::query()->whereIn('id', $rightToUpdate)->update([
                'right' => DB::raw('"right" + ' . ($oldLeftRightDiff + 1)),
            ]);
        }

        return true;
    }
}
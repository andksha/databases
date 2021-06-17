<?php

namespace App\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * Class ClosureCategory
 *
 * @package App\Model
 * @property int $parent_id
 * @property int $child_id
 * @property int $immediate_parent_id

 */
class ClosureCategory extends Model
{
    protected $table = 'categories_closures';

    public $timestamps = false;
}

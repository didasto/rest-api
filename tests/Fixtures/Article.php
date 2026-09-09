<?php

namespace Didasto\RestApi\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $table = 'articles';

    protected $fillable = ['title', 'slug', 'position', 'price', 'published'];

    protected $hidden = ['secret'];

    protected $casts = [
        'position'  => 'integer',
        'price'     => 'float',
        'published' => 'boolean',
    ];
}

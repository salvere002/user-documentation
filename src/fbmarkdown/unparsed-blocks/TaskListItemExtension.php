<?hh // strict
/*
 *  Copyright (c) 2004-present, Facebook, Inc.
 *  All rights reserved.
 *
 *  This source code is licensed under the BSD-style license found in the
 *  LICENSE file in the root directory of this source tree. An additional grant
 *  of patent rights can be found in the PATENTS file in the same directory.
 *
 */

namespace Facebook\Markdown\UnparsedBlocks;

use namespace Facebook\Markdown\{Blocks, Inlines};
use namespace HH\Lib\{C, Str, Vec};

class TaskListItemExtension extends ListItem{
  public function __construct(
    private bool $checked,
    string $delimiter,
    ?int $number,
    vec<Block> $children,
  ) {
    parent::__construct(
      $delimiter,
      $number,
      $children,
    );
  }

  <<__Override>>
  protected static function createFromContents(
    Context $context,
    string $delimiter,
    ?int $number,
    vec<string> $contents,
  ): ListItem {
    $first = C\first($contents);
    if ($first === null) {
      return parent::createFromContents(
        $context,
        $delimiter,
        $number,
        $contents,
      );
    }

    $first = Str\trim_left($first);
    if (Str\starts_with($first, '[ ] ')) {
      $contents = Vec\concat(
        vec[Str\slice($first, 4)],
        Vec\drop($contents, 1),
      );
      return new self(
        /* checked = */ false,
        $delimiter,
        $number,
        self::consumeChildren($context, $contents),
      );
    }

    if (Str\starts_with($first, '[x] ')) {
      $contents = Vec\concat(
        vec[Str\slice($first, 4)],
        Vec\drop($contents, 1),
      );
      return new self(
        /* checked = */ true,
        $delimiter,
        $number,
        self::consumeChildren($context, $contents),
      );
    }

    return parent::createFromContents(
      $context,
      $delimiter,
      $number,
      $contents,
    );
  }

  <<__Override>>
  public function withParsedInlines(
    Inlines\Context $ctx,
  ): Blocks\TaskListItemExtension {
    return new Blocks\TaskListItemExtension(
      $this->checked,
      $this->number,
      Vec\map($this->children, $child ==> $child->withParsedInlines($ctx)),
    );
  }
}
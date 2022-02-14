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

namespace HHVM\UserDocumentation;

use type Facebook\DefinitionFinder\{
  FileParser,
  ScannedClass,
  ScannedClassish,
  ScannedDefinition,
  ScannedFunction,
  ScannedInterface,
  ScannedMethod,
  ScannedTrait,
};
use namespace Facebook\{HHAPIDoc, TypeAssert};
use namespace Facebook\HHAPIDoc\Documentables;
use type Facebook\HHAPIDoc\Documentable;
use namespace HH\Lib\{C, Dict, Str, Vec};

final class HHAPIDocBuildStep extends BuildStep {
  <<__Override>>
  public function buildAll(): void {
    Log::i("\nHHAPIDocBuildStep");
    if (self::shouldSkip()) {
      Log::i("\n  ...already built and no dependencies changed, skipping.");
      return;
    }

    $exts = ImmSet {'php', 'hhi', 'hh'};

    Log::i("\nFinding Builtin Sources");
    $runtime_sources = Vec\concat(
      self::findSources(BuildPaths::HHVM_TREE.'/hphp/system/php/', $exts),
      self::findSources(BuildPaths::HHVM_TREE.'/hphp/runtime/ext/', $exts),
    );
    $hhi_sources =
      self::findSources(BuildPaths::HHVM_TREE.'/hphp/hack/hhi/', $exts);
    Log::i("\nParsing builtins");
    list($runtime_defs, $hhi_defs) = \HH\Asio\join(async {
      return tuple(
        await self::parseAsync($runtime_sources),
        await self::parseAsync($hhi_sources),
      );
    });
    Log::i("\nDe-duping builtins");
    $builtin_defs = DataMerger::mergeAll(Vec\concat($runtime_defs, $hhi_defs));

    Log::i("\nFiltering out PHP builtins");
    $builtin_defs = Vec\filter(
      $builtin_defs,
      $documentable ==> {
        $parent = $documentable['parent'];
        if ($parent !== null) {
          return ScannedDefinitionFilters::isHHSpecific($parent);
        }
        return
          ScannedDefinitionFilters::isHHSpecific($documentable['definition']);
      },
    );

    Log::i("\nFinding HSL sources");
    // We have prefix whitelists (especially for hsl-experimental), but they're
    // already handled when extracting the tarball; no need to filter out non-
    // whitelisted files again here.
    $hsl_sources =
      self::findSources(BuildPaths::HHVM_TREE.'/hphp/hsl/src/', $exts);
    $hsl_experimental_sources =
      self::findSources(BuildPaths::HSL_EXPERIMENTAL_TREE.'/src/', $exts);
    Log::i("\nParsing HSL sources");
    $hsl_defs = \HH\Asio\join(self::parseAsync($hsl_sources));
    $hsl_experimental_defs =
      \HH\Asio\join(self::parseAsync($hsl_experimental_sources));

    Log::i("\nGenerating search and navigation index for builtins");
    $builtin_index = self::createProductIndex(APIProduct::HACK, $builtin_defs);
    Log::i("\nGenerating search and navigation index for the HSL");
    $hsl_index = self::createProductIndex(APIProduct::HSL, $hsl_defs);
    $hsl_experimental_index = self::createProductIndex(
      APIProduct::HSL_EXPERIMENTAL,
      $hsl_experimental_defs,
    );
    Log::i("\nWriting search and navigation index file");
    \file_put_contents(
      BuildPaths::APIDOCS_INDEX_JSON,
      JSON\encode_shape(
        APIIndexShape::class,
        shape(
          APIProduct::HACK => $builtin_index,
          APIProduct::HSL => $hsl_index,
          APIProduct::HSL_EXPERIMENTAL => $hsl_experimental_index,
        ),
      ),
    );

    // HHApiDoc index; needed so that e.g. `File\WriteHandle`'s documentation
    // generation knows about `IO\WriteHandle`'s methods
    Log::i("\nCreating cross-reference index");
    $hh_apidoc_index = shape(
      'types' => dict[],
      'newtypes' => dict[],
      'functions' => dict[],
      'classes' => dict[],
      'interfaces' => dict[],
      'traits' => dict[],
    );
    $all_documentables =
      Vec\flatten(vec[$builtin_defs, $hsl_defs, $hsl_experimental_defs]);
    foreach ($all_documentables as $documentable) {
      $def = $documentable['definition'];
      // types and newtypes are not currently supported by docs.hhvm.com
      if ($def is ScannedFunction) {
        $hh_apidoc_index['functions'][$def->getName()] = $documentable;
        continue;
      }
      if ($def is ScannedClass) {
        $hh_apidoc_index['classes'][$def->getName()] = $documentable;
        continue;
      }
      if ($def is ScannedInterface) {
        $hh_apidoc_index['interfaces'][$def->getName()] = $documentable;
        continue;
      }
      if ($def is ScannedTrait) {
        $hh_apidoc_index['traits'][$def->getName()] = $documentable;
        continue;
      }
    }

    Log::i("\nGenerating Markdown for builtins");
    $builtin_md = self::buildMarkdown(APIProduct::HACK, $builtin_defs, $hh_apidoc_index);
    Log::i("\nGenerating Markdown for the HSL");
    $hsl_md = self::buildMarkdown(APIProduct::HSL, $hsl_defs, $hh_apidoc_index);
    $hsl_experimental_md =
      self::buildMarkdown(APIProduct::HSL_EXPERIMENTAL, $hsl_experimental_defs, $hh_apidoc_index);

    \file_put_contents(
      BuildPaths::APIDOCS_MARKDOWN.'/index.json',
      JSON\encode_shape(
        DirectoryIndex::class,
        shape(
          'files' => Vec\concat($builtin_md, $hsl_md, $hsl_experimental_md),
        ),
      ),
    );

    // This ensures that this step gets rebuild if and only if any of its
    // dependencies change.
    \file_put_contents(BuildPaths::APIDOCS_TAG, self::getTagFileContent());
  }

  public static function shouldSkip(): bool {
    // Checking the tag file should be sufficient, but we check for existence of
    // a few basic files to catch any pathological cases. Note that we don't
    // check for every single file generated by this step though.
    return \file_exists(BuildPaths::APIDOCS_INDEX_JSON) &&
      \file_exists(BuildPaths::APIDOCS_MARKDOWN.'/index.json') &&
      \file_exists(BuildPaths::APIDOCS_TAG) &&
      \file_get_contents(BuildPaths::APIDOCS_TAG) === self::getTagFileContent();
  }

  <<__Memoize>>
  private static function getTagFileContent(): string {
    $content = Str\format(
      "build step source hash: %s\ntags from dependencies:\n",
      \sha1(\file_get_contents(__FILE__)),
    );
    foreach (APIProduct::getValues() as $product) {
      $content .= APISourcesBuildStep::getTagFileContent($product);
    }

    // Get the last modified time of the last modified file in api-examples/
    $max_mtime = null;
    foreach (
      new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator(BuildPaths::API_EXAMPLES_DIR),
      ) as $file_info
    ) {
      if ($max_mtime is null || $file_info->getMTime() > $max_mtime) {
        $max_mtime = $file_info->getMTime();
      }
    }
    invariant(
      $max_mtime is nonnull,
      'Error finding the last modified file in api-examples/',
    );
    $content .= 'highest api-examples mtime: '.$max_mtime."\n";

    return $content;
  }

  private static function createProductIndex(
    APIProduct $product,
    vec<Documentable> $documentables,
  ): ProductAPIIndexShape {
    $documentables =
      Vec\sort_by($documentables, $d ==> $d['definition']->getName());
    return shape(
      'class' => self::createClassishIndex(
        $product,
        APIDefinitionType::CLASS_DEF,
        $documentables,
      ),
      'interface' => self::createClassishIndex(
        $product,
        APIDefinitionType::INTERFACE_DEF,
        $documentables,
      ),
      'trait' => self::createClassishIndex(
        $product,
        APIDefinitionType::TRAIT_DEF,
        $documentables,
      ),
      'function' => self::createFunctionIndex($product, $documentables),
    );
  }

  private static function createClassishIndex(
    APIProduct $product,
    APIDefinitionType $type,
    vec<Documentable> $documentables,
  ): dict<string, APIClassIndexEntry> {
    $classes = Vec\filter(
      $documentables,
      $d ==> {
        if ($type === APIDefinitionType::CLASS_DEF) {
          return $d['definition'] is ScannedClass;
        }
        if ($type === APIDefinitionType::INTERFACE_DEF) {
          return $d['definition'] is ScannedInterface;
        }
        if ($type === APIDefinitionType::TRAIT_DEF) {
          return $d['definition'] is ScannedTrait;
        }
        invariant_violation('unhandled type: %s', $type);
      },
    );

    $html_paths = HTMLPaths::get($product);

    return Dict\pull(
      $classes,
      $class ==> {
        $class_name = $class['definition']->getName();
        $methods = Dict\filter(
          $documentables,
          $d ==> $d['parent'] === $class['definition'],
        );

        return shape(
          'type' => $type,
          'name' => $class_name,
          'htmlPath' => $html_paths->getPathForClassish($type, $class_name),
          'urlPath' => \APIClassPageControllerURIBuilder::getPath(shape(
            'Product' => $product,
            'Name' => Str\replace($class_name, "\\", '.'),
            'Type' => $type,
          )),
          'methods' => Dict\pull(
            $methods,
            $method ==> {
              $method_name = $method['definition']->getName();
              return shape(
                'name' => $method_name,
                'className' => $class_name,
                'classType' => $type,
                'htmlPath' => $html_paths->getPathForClassishMethod(
                  $type,
                  $class_name,
                  $method_name,
                ),
                'urlPath' => \APIMethodPageControllerURIBuilder::getPath(shape(
                  'Product' => $product,
                  'Class' => Str\replace($class_name, "\\", '.'),
                  'Method' => $method_name,
                  'Type' => $type,
                )),
              );
            },
            $method ==>
              Str\replace($method['definition']->getName(), "\\", '.'),
          ),
        );
      },
      $class ==> Str\replace($class['definition']->getName(), "\\", '.'),
    );
  }

  private static function createFunctionIndex(
    APIProduct $product,
    vec<Documentable> $documentables,
  ): dict<string, APIFunctionIndexEntry> {
    $functions =
      Dict\filter($documentables, $d ==> $d['definition'] is ScannedFunction);
    $html_paths = HTMLPaths::get($product);
    return Dict\pull(
      $functions,
      $function ==> {
        $def = $function['definition'];
        $function_name = $def->getName();
        $deprecated = $def->getAttributes()['__Deprecated'] ?? null;
        if ($deprecated !== null) {
          $deprecated = C\onlyx($deprecated) as string;
        }
        return shape(
          'name' => $function_name,
          'htmlPath' => $html_paths->getPathForFunction($function_name),
          'urlPath' => \APIClassPageControllerURIBuilder::getPath(
            shape(
              'Product' => $product,
              'Name' => Str\replace($function_name, "\\", '.'),
              'Type' => APIDefinitionType::FUNCTION_DEF,
            ),
          ),
          'deprecation' => $deprecated,
        );
      },
      $function ==> Str\replace($function['definition']->getName(), "\\", '.'),
    );
  }

  private static function correctHHIOnlyDefs(Documentable $def): Documentable {
    $obj = $def['definition'];
    if (!$obj is ScannedFunction) {
      return $def;
    }
    $to_fix = keyset[
      'fun',
      'meth_caller',
      'class_meth',
      'inst_meth',
    ];
    if (!C\contains_key($to_fix, $obj->getName())) {
      return $def;
    }
    $def['definition'] = new ScannedFunction(
      $obj->getASTx(),
      "HH\\".$obj->getName(),
      $obj->getContext(),
      $obj->getAttributes(),
      $obj->getDocComment(),
      $obj->getGenericTypes(),
      $obj->getReturnType(),
      $obj->getParameters(),
    );
    return $def;
  }

  private static async function parseAsync(
    Traversable<string> $sources,
  ): Awaitable<vec<Documentable>> {
    $parsers = await Vec\map_async(
      $sources,
      async $file ==> {
        $parsed = await FileParser::fromFileAsync($file);
        Log::v('.');
        return $parsed;
      },
    );
    return $parsers
      |> Vec\map($$, $parser ==> Documentables\from_parser($parser))
      |> Vec\flatten($$)
      |> Vec\map($$, $def ==> self::correctHHIOnlyDefs($def))
      |> Vec\filter(
        $$,
        $documentable ==> {
          $parent = $documentable['parent'];
          if (
            $parent !== null &&
            ScannedDefinitionFilters::shouldNotDocument($parent)
          ) {
            return false;
          }
          return !ScannedDefinitionFilters::shouldNotDocument(
            $documentable['definition'],
          );
        },
      );
  }

  private static function buildMarkdown(
    APIProduct $product,
    vec<Documentable> $documentables,
    \Facebook\HHAPIDoc\Index $index,
  ): vec<string> {
    $root = BuildPaths::APIDOCS_MARKDOWN.'/'.$product;

    if (!\is_dir($root)) {
      \mkdir($root, /* mode = */ 0755, /* recursive = */ true);
    }
    $md_paths = MarkdownPaths::get($product);
    $ctx = (
      new HHAPIDoc\DocumentationBuilderContext(
        $index,
        new HHAPIDocExt\PathProvider(),
        shape(
          'format' => HHAPIDoc\OutputFormat::MARKDOWN,
          'syntaxHighlighting' => true,
          'hidePrivateMethods' => true,
          'hideInheritedMethods' => false,
        ),
      )
    );
    $builder = new HHAPIDocExt\MarkdownBuilder($ctx);

    return Vec\map($documentables, $documentable ==> {
      Log::v('.');
      $md = $builder->getDocumentation($documentable);
      $what = $documentable['definition'];
      if ($what is ScannedMethod) {
        $parent = TypeAssert\not_null($documentable['parent']);
        $path = $md_paths->getPathForClassishMethod(
          self::getClassishAPIDefinitionType($parent),
          $parent->getName(),
          $what->getName(),
        );
      } else if ($what is ScannedFunction) {
        $path = $md_paths->getPathForFunction($what->getName());
      } else if ($what is ScannedClassish) {
        $path = $md_paths->getPathForClassish(
          self::getClassishAPIDefinitionType($what),
          $what->getName(),
        );
      } else {
        invariant_violation(
          "Can't handle definition of type %s",
          \get_class($what),
        );
      }
      \file_put_contents($path, $md."\n<!-- HHAPIDOC -->\n");
      return $path;
    });
  }

  private static function getClassishAPIDefinitionType(
    ScannedDefinition $definition,
  ): APIDefinitionType {
    if ($definition is ScannedClass) {
      return APIDefinitionType::CLASS_DEF;
    }
    if ($definition is ScannedInterface) {
      return APIDefinitionType::INTERFACE_DEF;
    }
    if ($definition is ScannedTrait) {
      return APIDefinitionType::TRAIT_DEF;
    }
    invariant_violation("Can't handle type %s", \get_class($definition));
  }
}

<?php
declare(strict_types=1);

require_once 'vendor/autoload.php';
require_once __DIR__ . '/CustomPrettyPrinter.php';
require_once __DIR__ . '/PhpUnitAnnotationTransformer.php';

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PhpParser\Node\Stmt\Expression as StmtExpression;
use PhpParser\ParserFactory;
use PhpParser\NodeVisitor;

/**
 * オプションヘルプ表示関数
 */
function displayHelp(): void
{
    echo "Usage: php migrator.php [--exclude <glob path>] <directory_path>" . PHP_EOL;
    echo "  <directory_path>: Path to the directory to recursively fetch PHP files from." . PHP_EOL;
    echo "  --exclude <glob path>: Glob pattern for excluding files (e.g., */bootstrap.php, /path/to/*)." . PHP_EOL;
    exit(1);
}

/**
 * 再帰的に指定したディレクトリ内のすべてのPHPファイルを取得する
 *
 * @param string $directoryPath
 * @param array $excludePatterns
 * @return array
 */
function getPhpFiles(string $directoryPath, array $excludePatterns = []): array
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directoryPath)
    );

    $phpFiles = [];
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $realPath = $file->getRealPath();

            // 除外パターンと照合
            $shouldExclude = false;
            foreach ($excludePatterns as $pattern) {
                if (fnmatch($pattern, $realPath)) {
                    $shouldExclude = true;
                    break;
                }
            }

            // 除外対象でない場合に追加
            if (!$shouldExclude) {
                $phpFiles[] = $realPath;
            }
        }
    }

    return $phpFiles;
}

/**
 * 指定されたPHPコードに対してPHPUnitのアノテーションをトランスフォームし、コード全体を整形して返す
 *
 * @param string $code トランスフォーム対象のPHPコード
 * @return string トランスフォームおよび整形後のPHPコード
 * @throws Throwable フォーマット時にエラーが発生した場合
 */
function transformPhpUnitAnnotations(string $code): string
{
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $prettyPrinter = new CustomPrettyPrinter();

    $oldStmts = $parser->parse($code);
    $oldTokens = $parser->getTokens();

    // 一度クローンする
    // https://github.com/nikic/PHP-Parser/issues/813#issuecomment-946939267
    $traverser = new NodeTraverser();
    $traverser->addVisitor(new NodeVisitor\CloningVisitor());
    $newStmts = $traverser->traverse($oldStmts);

    // コードを修正する
    $traverser = new NodeTraverser();
    $transformer = new PhpUnitAnnotationTransformer();
    $traverser->addVisitor($transformer);
    $newStmts = $traverser->traverse($newStmts);

    // 以下、コード先頭の use を最適化する
    $neededUses = $transformer->neededUses;

    // Namespace ノードを探す
    $namespaceNode = null;
    foreach ($newStmts as $stmt) {
        if ($stmt instanceof Node\Stmt\Namespace_) {
            $namespaceNode = $stmt;
            break;
        }
    }

    if ($namespaceNode) {
        // Namespace がある場合、既存の use を確認
        $newUseNodes = [];
        foreach ($namespaceNode->stmts as $key => $stmt) {
            if ($stmt instanceof Use_) {
                foreach ($stmt->uses as $use) {
                    $neededUses[] = $use->name->toCodeString();
                }
                unset($namespaceNode->stmts[$key]); // 既存の use を一旦削除
            }
        }

        // 新たに挿入する use ノードを作成
        $neededUses = array_unique($neededUses); // 重複を削除
        sort($neededUses); // アルファベット順にソート
        foreach ($neededUses as $neededUse) {
            $newUseNodes[] = new Use_([
                new UseUse(new Node\Name($neededUse)),
            ]);
        }

        // Namespace 直後に use ステートメントを挿入
        $namespaceNode->stmts = array_merge($newUseNodes, $namespaceNode->stmts);

    } else {
        // Namespace がない場合、トップレベルの use を確認
        $newUseNodes = [];
        foreach ($newStmts as $key => $stmt) {
            if ($stmt instanceof Use_) {
                foreach ($stmt->uses as $use) {
                    $neededUses[] = $use->name->toCodeString();
                }
                unset($newStmts[$key]); // 既存の use を一旦削除
            }
        }
        $newStmts = array_values($newStmts);

        // 先頭の include require などを探す
        $lastIncludeOffset = 0;
        foreach ($newStmts as $key => $stmt) {
            if ($stmt instanceof StmtExpression && $stmt->expr instanceof Include_) {
                $lastIncludeOffset = $key + 1;
            }
        }

        // 新たに挿入する use ノードを作成
        $neededUses = array_unique($neededUses); // 重複を削除
        sort($neededUses); // アルファベット順にソート
        foreach ($neededUses as $neededUse) {
            $newUseNodes[] = new Use_([
                new UseUse(new Node\Name($neededUse)),
            ]);
        }

        // トップ、または include の下に use ステートメントを挿入
        array_splice($newStmts, $lastIncludeOffset, 0, $newUseNodes);
    }

    try {
        $result = $prettyPrinter->printFormatPreserving($newStmts, $oldStmts, $oldTokens);
    } catch (Throwable $t) {
        echo "format missing...\n";
//        $result = $prettyPrinter->prettyPrintFile($newStmts);
        throw $t;
    }

    // 細かい修正
    $result = preg_replace('/    }\n(    )?\n}/', "    }\n}", $result);
    $result = preg_replace('/(\nuse[^;]+;)\n(#\[|\/\*\*)/', "$1\n\n$2", $result);
    $result = preg_replace('/ \*\s+\*\//', " */", $result);
    $result = preg_replace('/( \*\n)+ \*\//', " */", $result);
    $result = str_replace("// -*- encoding:utf-8 -*-", "", $result);
    return $result;
}

// コマンドライン引数の処理
$options = getopt("", ["exclude:"], $rest_index);

// `getopt`による残りの引数を取得
$restArguments = array_slice($argv, $rest_index);

// ディレクトリパスを取得
if (count($restArguments) !== 1) {
    displayHelp();
}

$directoryPath = $restArguments[0];
if (!is_dir($directoryPath)) {
    echo "Error: Given path is not a directory." . PHP_EOL;
    exit(1);
}

// 除外パターンを取得
$excludePatterns = isset($options['exclude']) ? explode(',', $options['exclude']) : [];

// PHPファイルの取得
$phpFiles = getPhpFiles($directoryPath, $excludePatterns);
foreach ($phpFiles as $phpFile) {
    echo "process $phpFile";
    $oldCode = file_get_contents($phpFile);
    $newCode = transformPhpUnitAnnotations($oldCode);
    if ($oldCode === $newCode) {
        echo " no diff\n";
    } else {
        echo "\n";
        file_put_contents($phpFile, $newCode);
    }
}

echo "PHPUnit annotations transformed!";

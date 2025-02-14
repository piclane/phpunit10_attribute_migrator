<?php
require_once 'vendor/autoload.php';
require_once __DIR__ . '/AttributeUtils.php';

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;

/**
 * PhpUnitのアノテーションをPHP属性(Attribute)形式に変換するためのノード訪問クラス
 * PHPUnitのテストクラスやメソッドのDocCommentを解析し、対応するPHP属性を生成・適用する
 */
class PhpUnitAnnotationTransformer extends NodeVisitorAbstract
{
    /**
     * メソッドの@coversアノテーションから抽出したメソッド情報を格納する配列
     * @var array<string>
     */
    private array $coverMethods = [];

    /**
     * 必要な`use`ステートメントを格納する配列
     * @var array<string>
     */
    public array $neededUses = [];

    /**
     * アノテーションと対応するPHPUnitの属性クラスのマッピング
     * @var array<string, string>
     */
    private const ATTRIBUTE_USES = [
        'Group' => 'PHPUnit\Framework\Attributes\Group',
        'Test' => 'PHPUnit\Framework\Attributes\Test',
        'CoversMethod' => 'PHPUnit\Framework\Attributes\CoversMethod',
        'DataProvider' => 'PHPUnit\Framework\Attributes\DataProvider',
    ];

    /**
     * ノードを解析時に呼び出されるメソッド
     * クラスまたはクラスメソッドの場合、それぞれに応じて適切な変換処理を実行する
     *
     * @param Node $node 現在解析中のノード
     */
    public function enterNode(Node $node)
    {
        if ($node instanceof Class_) {
            $this->transformClassAnnotations($node);
        }

        if ($node instanceof ClassMethod) {
            $this->transformMethodAnnotations($node);
        }
    }

    /**
     * ノード解析終了時に呼び出されるメソッド
     * 必要な use ステートメントの挿入や covers アノテーションの処理を行う
     *
     * @param Node $node 現在解析中のノード
     */
    public function leaveNode(Node $node)
    {
        // 必要な use ステートメントの追加
        if ($node instanceof Node\Stmt\Namespace_) {
            foreach ($this->neededUses as $neededUse) {
                $alreadyImported = false;
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Use_) {
                        foreach ($stmt->uses as $use) {
                            if ($use->name->toCodeString() === $neededUse) {
                                $alreadyImported = true;
                                break 2;
                            }
                        }
                    }
                }

                if (!$alreadyImported) {
                    array_unshift($node->stmts, new Use_([
                        new UseUse(new Node\Name($neededUse)),
                    ]));
                }
            }
        }

        // covers アノテーションの処理
        if ($node instanceof Class_) {
            if ($this->coverMethods) {
                $coverMethods = array_unique($this->coverMethods);
                foreach ($coverMethods as $coverMethod) {
                    [$className, $methodName] = explode('::', $coverMethod);
                    $attrGroup = new Node\AttributeGroup([
                        new Node\Attribute(new Node\Name('CoversMethod'), [
                            new Node\Arg(new Node\Expr\ClassConstFetch(
                                new Node\Name($className),
                                'class'
                            )),
                            new Node\Arg(new Node\Scalar\String_($methodName)),
                        ]),
                    ]);
                    if (!includesAttributeGroup($attrGroup, $node->attrGroups)) {
                        $node->attrGroups[] = $attrGroup;
                    }
                }
                $this->coverMethods = [];
            }
        }
    }

    /**
     * クラスレベルのアノテーションを解析し、対応するPHP属性に変換する
     *
     * @param Class_ $classNode 解析対象のクラスノード
     */
    private function transformClassAnnotations(Class_ $classNode): void
    {
        if ($classNode->getDocComment() !== null) {
            $docComment = $classNode->getDocComment()->getText();
            $updatedDocComment = $docComment;
            if (preg_match_all('/@group\s+(\w+)/', $docComment, $matches)) {
                foreach($matches[1] as $groupValue) {
                    $attrGroup = new Node\AttributeGroup([
                        new Node\Attribute(new Node\Name('Group'), [
                            new Node\Arg(new Node\Scalar\String_($groupValue)),
                        ]),
                    ]);
                    if (!includesAttributeGroup($attrGroup, $classNode->attrGroups)) {
                        $classNode->attrGroups[] = $attrGroup;
                    }
                    $this->neededUses[] = self::ATTRIBUTE_USES['Group'];
                }

                // Remove @group annotation from doc comment
                $updatedDocComment = preg_replace('/\n(\s*\*\s+@group\s+\w+\n)+/', "\n", $updatedDocComment);
            }
            $classNode->setDocComment(new PhpParser\Comment\Doc($updatedDocComment));
        }
    }

    /**
     * メソッドアノテーションをライブラリの属性に変換する処理を行う
     *
     * - test アノテーションを PHPUnit の Test 属性に変換し、不要であれば doc コメントから削除する
     * - group アノテーションを PHPUnit の Group 属性に変換し、関連する情報を追加する。また、doc コメントから該当アノテーションを削除する
     * - covers アノテーションを解析し、対象のメソッド名を内部プロパティに保存する
     * - dataProvider アノテーションをライブラリの DataProvider 属性に変換し、不要であれば doc コメントから削除する
     *
     * @param ClassMethod $methodNode アノテーション変換対象となるメソッドノード
     * @throws LogicException 属性が正しく追加できない場合
     */
    private function transformMethodAnnotations(ClassMethod $methodNode): void
    {
        if ($methodNode->getDocComment() !== null) {
            $docComment = $methodNode->getDocComment()->getText();
            $updatedDocComment = $docComment;

            if (preg_match('/@test\b/', $docComment)) {
                $attrGroup = new Node\AttributeGroup([
                    new Node\Attribute(new Node\Name('Test')),
                ]);
                if (!includesAttributeGroup($attrGroup, $methodNode->attrGroups)) {
                    $methodNode->attrGroups[] = $attrGroup;
                }
                $this->neededUses[] = self::ATTRIBUTE_USES['Test'];

                $updatedDocComment = preg_replace('/\n(\s*\*\s+@test\s*\n)+/', "\n", $updatedDocComment);
            }

            if (preg_match_all('/@group\s+(\w+)/', $docComment, $matches)) {
                foreach($matches[1] as $groupValue) {
                    $attrGroup = new Node\AttributeGroup([
                        new Node\Attribute(new Node\Name('Group'), [
                            new Node\Arg(new Node\Scalar\String_($groupValue)),
                        ]),
                    ]);
                    if (!includesAttributeGroup($attrGroup, $methodNode->attrGroups)) {
                        $methodNode->attrGroups[] = $attrGroup;
                    }
                    $this->neededUses[] = self::ATTRIBUTE_USES['Group'];
                }

                // Remove @group annotation from doc comment
                $updatedDocComment = preg_replace('/\n(\s*\*\s+@group\s+\w+\n)+/', "\n", $docComment);
            }

            if (preg_match_all('/@covers\s+([\w:]+)/', $docComment, $matches)) {
                foreach($matches[1] as $coversValue) {
                    $this->coverMethods[] = $coversValue;
                }
                $this->neededUses[] = self::ATTRIBUTE_USES['CoversMethod'];
            }

            if (preg_match('/@dataProvider\s+(\w+)/', $docComment, $matches)) {
                $dataProviderValue = $matches[1];
                $attrGroup = new Node\AttributeGroup([
                    new Node\Attribute(new Node\Name('DataProvider'), [
                        new Node\Arg(new Node\Scalar\String_($dataProviderValue)),
                    ]),
                ]);
                if (!includesAttributeGroup($attrGroup, $methodNode->attrGroups)) {
                    $methodNode->attrGroups[] = $attrGroup;
                }
                $this->neededUses[] = self::ATTRIBUTE_USES['DataProvider'];

                $updatedDocComment = preg_replace('/\n(\s*\*\s+@dataProvider\s+\w+\s*\n)+/', "\n", $updatedDocComment);
            }

            // Set updated doc comment, preserving any @covers annotations
            if ($updatedDocComment !== $docComment) {
                $methodNode->setDocComment(new PhpParser\Comment\Doc($updatedDocComment));
            }
        }
    }
}

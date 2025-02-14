<?php
require_once 'vendor/autoload.php';

use PhpParser\PrettyPrinter\Standard;
use PhpParser\Node\Stmt;

class CustomPrettyPrinter extends Standard
{
    // Override method to enforce 1 blank line between functions
    protected function pStmt_ClassMethod(Stmt\ClassMethod $node): string
    {
        return $this->pAttrGroups($node->attrGroups)
            . $this->pModifiers($node->flags)
            . 'function ' . ($node->byRef ? '&' : '') . $node->name
            . '(' . $this->pMaybeMultiline($node->params, $this->phpVersion->supportsTrailingCommaInParamList()) . ')'
            . (null !== $node->returnType ? ': ' . $this->p($node->returnType) : '')
            . (null !== $node->stmts
                ? $this->nl . '{' . $this->pStmts($node->stmts) . $this->nl . '}'
                : ';')
            . $this->nl; // // メソッドの後に空白行を挿入
    }

    protected function pStmt_Class(Stmt\Class_ $node): string
    {
        return Standard::pStmt_Class($node) . "\n"; // クラスの後に空白行を挿入
    }

    protected function pStmts(array $nodes, bool $indent = true): string
    {
        $result = Standard::pStmts($nodes, $indent);

        // 関数間の空行を標準的な1行に調整
        $result = preg_replace(
            "/\n{3,}/",
            "\n\n",
            $result
        );
        return $result;
    }
}

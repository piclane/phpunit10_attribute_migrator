<?php
require_once 'vendor/autoload.php';

use PhpParser\Node;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Attribute;

$printer = new PhpParser\PrettyPrinter\Standard();

function areAttributesEqual(Attribute $a, Attribute $b): bool {
    global $printer;

    // 名前が異なる場合は false
    if ($a->name->toString() !== $b->name->toString()) {
        return false;
    }

    // 引数の数が異なる場合は false
    if (count($a->args) !== count($b->args)) {
        return false;
    }

    // それぞれの引数を比較
    foreach ($a->args as $index => $aArg) {
        $bArg = $b->args[$index];
        $aExpr = trim($printer->prettyPrintExpr($aArg->value), '"\'');
        $bExpr = trim($printer->prettyPrintExpr($bArg->value), '"\'');

        // 引数の AST が一致するかチェック
        if ($aExpr !== $bExpr) {
            return false;
        }
    }

    return true;
}

function equalsAttributeGroup(AttributeGroup $a, AttributeGroup $b): bool
{
    // 属性リストの長さが異なる場合は false
    if (count($a->attrs) !== count($b->attrs)) {
        return false;
    }

    // 各 Attribute インスタンスを比較
    foreach ($a->attrs as $index => $attr) {
        if (!areAttributesEqual($attr, $b->attrs[$index])) {
            return false;
        }
    }

    return true;
}

function includesAttributeGroup(Node\AttributeGroup $needle, array $haystack): bool
{
    return array_any($haystack, fn($e) => equalsAttributeGroup($needle, $e));
}
